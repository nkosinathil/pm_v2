<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Constants for display
define('CURRENT_TIMESTAMP', date('Y-m-d H:i:s'));
define('CURRENT_USER', $_SESSION['username'] ?? 'nkosinathil');

// Session check - redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Database connection
include_once('db-connection.php');

$error_message = '';
$success_message = '';

// Get parameters from URL
$transfer_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$custody_id = isset($_GET['custody_id']) ? (int)$_GET['custody_id'] : 0;

// Verify both IDs are valid
if ($transfer_id <= 0 || $custody_id <= 0) {
    $error_message = "Invalid transfer or custody ID provided.";
} else {
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        
        // Start transaction
        $pdo->beginTransaction();
        
        // Verify the transfer exists and belongs to the custody record
        $stmt = $pdo->prepare("
            SELECT * FROM custody_transfers 
            WHERE id = ? AND custody_id = ?
        ");
        $stmt->execute([$transfer_id, $custody_id]);
        $transfer = $stmt->fetch();
        
        if (!$transfer) {
            $error_message = "Transfer record not found or does not belong to the specified custody record.";
        } else {
            // Delete the transfer record
            $stmt = $pdo->prepare("DELETE FROM custody_transfers WHERE id = ?");
            $result = $stmt->execute([$transfer_id]);
            
            if ($result) {
                // Log the deletion in audit logs
                $stmt = $pdo->prepare("
                    INSERT INTO audit_logs (
                        user_id,
                        action,
                        target_table,
                        target_id,
                        timestamp
                    ) VALUES (?, ?, ?, ?, NOW())
                ");
                
                $stmt->execute([
                    $_SESSION['user_id'] ?? 0,
                    "Deleted transfer #$transfer_id from custody #$custody_id by " . CURRENT_USER,
                    'custody_transfers',
                    $custody_id
                ]);
                
                $success_message = "Transfer record has been deleted successfully.";
                
                // Commit transaction
                $pdo->commit();
                
                // Redirect back to the edit page after brief delay
                header("Refresh: 2; URL=edit-coc.php?id=$custody_id");
            } else {
                $pdo->rollBack();
                $error_message = "Failed to delete transfer record: Database error.";
            }
        }
    } catch (PDOException $e) {
        if (isset($pdo)) $pdo->rollBack();
        error_log('Database Error: ' . $e->getMessage());
        $error_message = "Database error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Delete Transfer - Project Management System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="css/coc-styles.css" rel="stylesheet">
</head>
<body>
    <div class="top-bar">
        <div class="container">
            <div class="system-info">
                <span class="timestamp">Current Date and Time (UTC): <?= CURRENT_TIMESTAMP ?></span>
                <span class="username">Current User's Login: <?= CURRENT_USER ?></span>
            </div>
        </div>
    </div>

    <div class="layout">
        <!-- Sidebar Navigation -->
        <nav class="sidebar">
            <div class="sidebar-header">
                <img src="../assets/logo.jpg" alt="Logo" class="logo">
                <h2>Project Management</h2>
            </div>
            
            <div class="menu-section">
                <h3>Case Management</h3>
                <ul>
                    <li>
                        <a href="dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                    </li>
                    <li>
                        <a href="create-case.php">
                            <i class="fas fa-plus-circle"></i> Create New Case
                        </a>
                    </li>
                    <li>
                        <a href="assign-case.php">
                            <i class="fas fa-user-plus"></i> Assign Case
                        </a>
                    </li>
                    <li>
                        <a href="task-management.php">
                            <i class="fas fa-tasks"></i> Task Management
                        </a>
                    </li>
                </ul>
            </div>
            
            <div class="menu-section">
                <h3>Document Management</h3>
                <ul>
                    <li class="active">
                        <a href="coc.php">
                            <i class="fas fa-file-contract"></i> Chain of Custody
                        </a>
                    </li>
                    <li>
                        <a href="consent-form.php">
                            <i class="fas fa-file-signature"></i> Consent Forms
                        </a>
                    </li>
                </ul>
            </div>

            <div class="sidebar-footer">
                <a href="logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </nav>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="page-header">
                <h1>Delete Transfer Record</h1>
                <nav class="breadcrumb">
                    <a href="dashboard.php">Dashboard</a> / 
                    <a href="coc.php">Chain of Custody</a> / 
                    <a href="view-coc.php?id=<?= $custody_id ?>">View Details</a> / 
                    <a href="edit-coc.php?id=<?= $custody_id ?>">Edit Form</a> / 
                    <span>Delete Transfer</span>
                </nav>
            </div>

            <?php if ($error_message): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?= $error_message ?>
                    <div class="mt-3">
                        <a href="edit-coc.php?id=<?= $custody_id ?>" class="action-button">Return to Edit Form</a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?= $success_message ?>
                    <p class="mt-2">You will be redirected to the edit form in a moment...</p>
                </div>
            <?php endif; ?>

            <?php if (!$error_message && !$success_message): ?>
                <div class="content-card">
                    <div class="form-header">
                        <div class="form-header-icon">
                            <i class="fas fa-trash"></i>
                        </div>
                        <div class="form-header-info">
                            <h2>Deleting Transfer #<?= $transfer_id ?></h2>
                            <p>Please confirm that you want to delete this transfer record.</p>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <div class="form-info-banner warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            <p>Warning: This action cannot be undone. The transfer record will be permanently deleted.</p>
                        </div>
                        
                        <div class="form-buttons">
                            <a href="edit-coc.php?id=<?= $custody_id ?>" class="cancel-button">Cancel</a>
                            <a href="delete-transfer.php?id=<?= $transfer_id ?>&custody_id=<?= $custody_id ?>&confirm=yes" class="danger-button">Confirm Deletion</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Mobile Menu Toggle Button -->
    <button id="mobileMenuToggle" class="mobile-menu-toggle">
        <i class="fas fa-bars"></i>
    </button>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Mobile menu toggle
            const mobileToggle = document.getElementById('mobileMenuToggle');
            const sidebar = document.querySelector('.sidebar');
            
            if (mobileToggle) {
                mobileToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('active');
                });
            }
        });
    </script>
</body>
</html>