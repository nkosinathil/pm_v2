<?php
session_start();
include_once('db-connection.php');

$user_id = $_SESSION['user_id'] ?? null;
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Collect and sanitize form inputs
$client_code       = trim($_POST['client_code'] ?? '');
$case_number       = trim($_POST['case_number'] ?? '');
$case_description  = trim($_POST['case_description'] ?? '');
$rep_name          = trim($_POST['rep_name'] ?? '');
$rep_surname       = trim($_POST['rep_surname'] ?? '');
$rep_phone         = trim($_POST['rep_phone'] ?? '');
$rep_email         = trim($_POST['rep_email'] ?? '');
$year              = date('Y');

if (empty($client_code) || empty($case_number)) {
    die("Missing client code or case number.");
}

try {
    // Insert into cases table
    $stmt = $pdo->prepare("
        INSERT INTO cases (
            client_code, case_number, case_description, year,
            rep_name, rep_surname, rep_phone, rep_email, created_at
        ) VALUES (
            :client_code, :case_number, :case_description, :year,
            :rep_name, :rep_surname, :rep_phone, :rep_email, NOW()
        )
    ");
    $stmt->execute([
        ':client_code'      => $client_code,
        ':case_number'      => $case_number,
        ':case_description' => $case_description,
        ':year'             => $year,
        ':rep_name'         => $rep_name,
        ':rep_surname'      => $rep_surname,
        ':rep_phone'        => $rep_phone,
        ':rep_email'        => $rep_email
    ]);

    $case_id = $pdo->lastInsertId();

    // Audit log for case creation
    $pdo->prepare("
        INSERT INTO audit_logs (user_id, action, target_table, target_id, timestamp)
        VALUES (?, ?, ?, ?, NOW())
    ")->execute([
        $user_id, 'Create Case', 'cases', $case_id
    ]);

    // Insert into projects if not exists
    $stmt = $pdo->prepare("SELECT id FROM projects WHERE case_number = ?");
    $stmt->execute([$case_number]);
    $existingProject = $stmt->fetch();

    if (!$existingProject) {
        $insert = $pdo->prepare("INSERT INTO projects (case_number, description) VALUES (?, ?)");
        $insert->execute([$case_number, $case_description]);

        $project_id = $pdo->lastInsertId();

        // Audit log for project creation
        $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, target_table, target_id, timestamp)
            VALUES (?, ?, ?, ?, NOW())
        ")->execute([
            $user_id, 'Create Project', 'projects', $project_id
        ]);
    }

    $_SESSION['case_number'] = $case_number;
    header("Location: acquisition-form.php?client_code={$client_code}");
    exit;

} catch (PDOException $e) {
    die("Error saving case or project: " . $e->getMessage());
}
?>
