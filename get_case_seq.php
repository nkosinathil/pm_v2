<?php
header('Content-Type: application/json');

$client_code = $_GET['client_code'] ?? '';
$year = $_GET['year'] ?? date('Y');

if (!$client_code) {
    echo json_encode(['status' => 'error', 'message' => 'Missing client code']);
    exit;
}

// DB credentials
include_once('db-connection.php');

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);

    // Count existing cases for the client in the given year
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cases WHERE client_code = :client_code AND year = :year");
    $stmt->execute([
        ':client_code' => $client_code,
        ':year' => $year
    ]);
    $count = $stmt->fetchColumn();

    // Determine next sequence
    $next_seq = str_pad($count + 1, 3, '0', STR_PAD_LEFT);
    echo json_encode(['status' => 'ok', 'next_seq' => $next_seq]);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
