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
$preview_data = [];

// Get parameters from URL
$client_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$client_code = isset($_GET['client_code']) ? trim($_GET['client_code']) : '';
$action = isset($_GET['action']) ? $_GET['action'] : 'preview';

// Verify client ID is valid
if ($client_id <= 0) {
    $error_message = "Invalid client ID provided.";
} else {
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        
        // Get client information
        $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
        $stmt->execute([$client_id]);
        $client = $stmt->fetch();
        
        if (!$client) {
            $error_message = "Client record not found.";
        } else {
            $client_code = $client['client_code'];
            
            if ($action === 'preview') {
                // Preview mode - show what will be deleted
                
                // Check consent forms
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM consent_forms WHERE client_code = ?");
                $stmt->execute([$client_code]);
                $consent_forms_count = $stmt->fetchColumn();
                
                // Check cases
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM cases WHERE client_code = ?");
                $stmt->execute([$client_code]);
                $cases_count = $stmt->fetchColumn();
                
                // Check credit topups (will be cascaded)
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM credit_topups WHERE client_id = ?");
                $stmt->execute([$client_id]);
                $credit_topups_count = $stmt->fetchColumn();
                
                // Check credit usage (will be cascaded)
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM credit_usage WHERE client_id = ?");
                $stmt->execute([$client_id]);
                $credit_usage_count = $stmt->fetchColumn();
                
                // Check invoices (will be cascaded)
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM invoices WHERE client_id = ?");
                $stmt->execute([$client_id]);
                $invoices_count = $stmt->fetchColumn();
                
                $preview_data = [
                    'client' => $client,
                    'consent_forms' => $consent_forms_count,
                    'cases' => $cases_count,
                    'credit_topups' => $credit_topups_count,
                    'credit_usage' => $credit_usage_count,
                    'invoices' => $invoices_count
                ];
                
                // Check for potential blocking constraints
                if ($consent_forms_count > 0 || $cases_count > 0) {
                    $preview_data['has_dependencies'] = true;
                }
                
            } elseif ($action === 'confirm') {
                // Perform the actual deletion
                
                // Start transaction
                $pdo->beginTransaction();
                
                try {
                    $deletion_stats = [
                        'consent_forms' => 0,
                        'cases' => 0,
                        'credit_topups' => 0,
                        'credit_usage' => 0,
                        'invoices' => 0
                    ];
                    
                    // Delete consent forms first (foreign key constraint)
                    $stmt = $pdo->prepare("DELETE FROM consent_forms WHERE client_code = ?");
                    $stmt->execute([$client_code]);
                    $deletion_stats['consent_forms'] = $stmt->rowCount();
                    
                    // Delete cases (foreign key constraint)
                    $stmt = $pdo->prepare("DELETE FROM cases WHERE client_code = ?");
                    $stmt->execute([$client_code]);
                    $deletion_stats['cases'] = $stmt->rowCount();
                    
                    // Get counts for cascaded deletions before deleting client
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM credit_topups WHERE client_id = ?");
                    $stmt->execute([$client_id]);
                    $deletion_stats['credit_topups'] = $stmt->fetchColumn();
                    
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM credit_usage WHERE client_id = ?");
                    $stmt->execute([$client_id]);
                    $deletion_stats['credit_usage'] = $stmt->fetchColumn();
                    
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE client_id = ?");
                    $stmt->execute([$client_id]);
                    $deletion_stats['invoices'] = $stmt->fetchColumn();
                    
                    // Finally delete the client (this will cascade to credit_topups, credit_usage, and invoices)
                    $stmt = $pdo->prepare("DELETE FROM clients WHERE id = ?");
                    $result = $stmt->execute([$client_id]);
                    
                    if ($result && $stmt->rowCount() > 0) {
                        // Log the deletion in audit logs
                        $total_records = array_sum($deletion_stats) + 1; // +1 for the client record
                        $details = "Deleted client {$client['first_name']} {$client['surname']} (Code: {$client_code}) and " . 
                                  ($total_records - 1) . " related records by " . CURRENT_USER;
                        
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
                            $details,
                            'clients',
                            $client_id
                        ]);
                        
                        $success_message = "Client and all related records have been deleted successfully. " .
                                         "Statistics: {$deletion_stats['consent_forms']} consent forms, " .
                                         "{$deletion_stats['cases']} cases, {$deletion_stats['credit_topups']} credit topups, " .
                                         "{$deletion_stats['credit_usage']} credit usage records, " .
                                         "{$deletion_stats['invoices']} invoices.";
                        
                        // Commit transaction
                        $pdo->commit();
                        
                        // Redirect back to dashboard after brief delay
                        header("Refresh: 5; URL=dashboard.php");
                    } else {
                        $pdo->rollBack();
                        $error_message = "Failed to delete client record: No rows affected.";
                    }
                    
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    throw $e;
                }
            }
        }
    } catch (PDOException $e) {
        if (isset($pdo)) $pdo->rollBack();
        error_log('Database Error: ' . $e->getMessage());
        
        // Check if it's a foreign key constraint error
        if (strpos($e->getMessage(), '1451') !== false) {
            $error_message = "Cannot delete client: Foreign key constraint violation. " .
                           "There are related records that must be deleted first. " .
                           "Error details: " . $e->getMessage();
        } else {
            $error_message = "Database error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Delete Client - Project Management System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="css/coc-styles.css" rel="stylesheet">
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin: 1rem 0;
        }
        .stat-card {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
            border-left: 4px solid #007bff;
        }
        .stat-card.warning {
            border-left-color: #ffc107;
            background: #fff3cd;
        }
        .stat-card.danger {
            border-left-color: #dc3545;
            background: #f8d7da;
        }
        .stat-number {
            font-size: 1.5rem;
            font-weight: 600;
            color: #333;
        }
        .stat-label {
            font-size: 0.875rem;
            color: #666;
            margin-top: 0.25rem;
        }
        .client-info {
            background: #e3f2fd;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        .warning-banner {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
        }
        .danger-banner {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
        }
    </style>
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
                    <li>
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
                <h1><?= $action === 'preview' ? 'Delete Client - Preview' : 'Delete Client - Confirm' ?></h1>
                <nav class="breadcrumb">
                    <a href="dashboard.php">Dashboard</a> / 
                    <span>Delete Client</span>
                </nav>
            </div>

            <?php if ($error_message): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?= $error_message ?>
                    <div class="mt-3">
                        <a href="dashboard.php" class="action-button">Return to Dashboard</a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?= $success_message ?>
                    <p class="mt-2">You will be redirected to the dashboard in a moment...</p>
                </div>
            <?php endif; ?>

            <?php if (!$error_message && !$success_message && $action === 'preview' && !empty($preview_data)): ?>
                <div class="content-card">
                    <div class="form-header">
                        <div class="form-header-icon">
                            <i class="fas fa-trash-alt"></i>
                        </div>
                        <div class="form-header-info">
                            <h2>Delete Client Preview</h2>
                            <p>Review the records that will be deleted when removing this client.</p>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <div class="client-info">
                            <h3><i class="fas fa-user"></i> Client Information</h3>
                            <p><strong>Name:</strong> <?= htmlspecialchars($preview_data['client']['first_name'] . ' ' . $preview_data['client']['surname']) ?></p>
                            <p><strong>Company:</strong> <?= htmlspecialchars($preview_data['client']['company_name']) ?></p>
                            <p><strong>Client Code:</strong> <?= htmlspecialchars($preview_data['client']['client_code']) ?></p>
                            <p><strong>Email:</strong> <?= htmlspecialchars($preview_data['client']['email']) ?></p>
                        </div>

                        <?php if (!empty($preview_data['has_dependencies'])): ?>
                            <div class="danger-banner">
                                <i class="fas fa-exclamation-triangle"></i>
                                <strong>Warning:</strong> This client has related records that will be deleted. Please review carefully.
                            </div>
                        <?php endif; ?>

                        <h3>Related Records Statistics</h3>
                        <div class="stats-grid">
                            <div class="stat-card <?= $preview_data['consent_forms'] > 0 ? 'warning' : '' ?>">
                                <div class="stat-number"><?= $preview_data['consent_forms'] ?></div>
                                <div class="stat-label">Consent Forms</div>
                            </div>
                            <div class="stat-card <?= $preview_data['cases'] > 0 ? 'warning' : '' ?>">
                                <div class="stat-number"><?= $preview_data['cases'] ?></div>
                                <div class="stat-label">Cases</div>
                            </div>
                            <div class="stat-card <?= $preview_data['credit_topups'] > 0 ? 'danger' : '' ?>">
                                <div class="stat-number"><?= $preview_data['credit_topups'] ?></div>
                                <div class="stat-label">Credit Topups (Cascaded)</div>
                            </div>
                            <div class="stat-card <?= $preview_data['credit_usage'] > 0 ? 'danger' : '' ?>">
                                <div class="stat-number"><?= $preview_data['credit_usage'] ?></div>
                                <div class="stat-label">Credit Usage (Cascaded)</div>
                            </div>
                            <div class="stat-card <?= $preview_data['invoices'] > 0 ? 'danger' : '' ?>">
                                <div class="stat-number"><?= $preview_data['invoices'] ?></div>
                                <div class="stat-label">Invoices (Cascaded)</div>
                            </div>
                        </div>

                        <div class="warning-banner">
                            <i class="fas fa-info-circle"></i>
                            <p><strong>Deletion Order:</strong></p>
                            <ol>
                                <li>Consent Forms (manual deletion to avoid foreign key constraint)</li>
                                <li>Cases (manual deletion to avoid foreign key constraint)</li>
                                <li>Client Record (will automatically cascade to Credit Topups, Credit Usage, and Invoices)</li>
                            </ol>
                            <p><strong>Note:</strong> This action cannot be undone.</p>
                        </div>
                        
                        <div class="form-buttons">
                            <a href="dashboard.php" class="cancel-button">Cancel</a>
                            <a href="delete-client.php?id=<?= $client_id ?>&action=confirm" 
                               class="danger-button"
                               onclick="return confirm('Are you absolutely sure you want to delete this client and ALL related records? This action cannot be undone.')">
                               <i class="fas fa-trash"></i> Confirm Deletion
                            </a>
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