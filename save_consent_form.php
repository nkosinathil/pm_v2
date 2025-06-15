<?php
session_start();

// Database connection setup
include_once('db-connection.php');

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Collect and sanitize form inputs
$full_name        = trim($_POST['full_name'] ?? '');
$management_for   = trim($_POST['management_for'] ?? '');
$signature_data   = trim($_POST['signature_data'] ?? '');
$date_signed      = trim($_POST['date_signed'] ?? '');
$confirm_full_name = trim($_POST['confirm_full_name'] ?? '');
$place_signed     = trim($_POST['place_signed'] ?? '');
$client_code      = $_SESSION['client_code'] ?? null;

if (!$client_code) {
    die("Client code not found in session.");
}

// Insert into database
$sql = "INSERT INTO consent_forms 
        (client_code, full_name, management_for, signature_data, date_signed, confirm_full_name, place_signed, created_at)
        VALUES (:client_code, :full_name, :management_for, :signature_data, :date_signed, :confirm_full_name, :place_signed, NOW())";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':client_code'        => $client_code,
    ':full_name'          => $full_name,
    ':management_for'     => $management_for,
    ':signature_data'     => $signature_data,
    ':date_signed'        => $date_signed,
    ':confirm_full_name'  => $confirm_full_name,
    ':place_signed'       => $place_signed
]);

// Redirect to create case
header("Location: create-case.php");
exit;
?>
