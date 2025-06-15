<?php
session_start();
include_once('db-connection.php');

// Check user authentication
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$transfer_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$response = ['success' => false];

if ($transfer_id > 0) {
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        
        $stmt = $pdo->prepare("SELECT * FROM custody_transfers WHERE id = ?");
        $stmt->execute([$transfer_id]);
        $transfer = $stmt->fetch();
        
        if ($transfer) {
            // Make sure signatures are properly formatted for display
            // No changes needed if they're already stored as data URLs
            $response['success'] = true;
            $response['transfer'] = $transfer;
            
            // Log successful retrieval
            error_log("Retrieved transfer ID $transfer_id successfully");
            error_log("Releaser signature exists: " . (!empty($transfer['releaser_signature']) ? 'Yes' : 'No'));
            error_log("Recipient signature exists: " . (!empty($transfer['recipient_signature']) ? 'Yes' : 'No'));
        } else {
            $response['message'] = 'Transfer record not found';
            error_log("Transfer ID $transfer_id not found");
        }
    } catch (PDOException $e) {
        error_log('Database error in get-transfer-details.php: ' . $e->getMessage());
        $response['message'] = 'Database error occurred';
    }
} else {
    $response['message'] = 'Invalid transfer ID';
    error_log("Invalid transfer ID provided: $transfer_id");
}

header('Content-Type: application/json');
echo json_encode($response);
?>