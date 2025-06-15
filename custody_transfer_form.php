<?php
// Start the session to access user info
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

define('CURRENT_TIMESTAMP', date('d-m-Y H:i:s'));
define('CURRENT_USER', $_SESSION['name'] ?? 'nkosinathil');

// Include database connection
include_once('db-connection.php');

// Initialize variables for form values
$custody_id = $_GET['custody_id'] ?? '';
$job_code = $_GET['job_code'] ?? '';
$transfer_date = date('Y-m-d\TH:i');  // Default current date and time
$transfer_reason = 'Returning device';  // Default reason
$released_by_name = CURRENT_USER;  // Default to current user
$released_by_position = 'Examiner';
$received_by_name = '';
$received_by_position = 'Client';
$error = $success = '';

// Check if a specific device ID or move_all was provided
$device_id = isset($_GET['device_id']) ? (int)$_GET['device_id'] : 0;
$device_name = isset($_GET['device_name']) ? $_GET['device_name'] : '';
$move_all = isset($_GET['move_all']) ? (bool)$_GET['move_all'] : false;

// Check if we're editing an existing transfer
$edit_transfer_id = isset($_GET['edit_transfer']) ? (int)$_GET['edit_transfer'] : 0;
$transfer_data = [];

// Get all devices for this job code
$all_devices = [];

// First check if the database has the notes column
try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    // Check if notes column exists in custody_transfers table
    $notesColumnExists = false;
    $stmt = $pdo->query("SHOW COLUMNS FROM custody_transfers LIKE 'notes'");
    $notesColumnExists = $stmt->fetchColumn() !== false;
    
    // If notes column doesn't exist, add it
    if (!$notesColumnExists) {
        try {
            $pdo->exec("ALTER TABLE custody_transfers ADD COLUMN notes TEXT AFTER created_at");
            $notesColumnExists = true;
            error_log("Added 'notes' column to custody_transfers table");
        } catch (PDOException $e) {
            error_log("Failed to add 'notes' column to custody_transfers: " . $e->getMessage());
            // We'll continue without using notes
        }
    }
    
    // Create transfer_devices table if it doesn't exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS transfer_devices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transfer_id INT NOT NULL,
            device_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (transfer_id) REFERENCES custody_transfers(id) ON DELETE CASCADE,
            FOREIGN KEY (device_id) REFERENCES custody_devices(id) ON DELETE CASCADE
        )
    ");
} catch (PDOException $e) {
    $error = "Database setup error: " . $e->getMessage();
    error_log("Database setup error in custody_transfer_form: " . $e->getMessage());
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Debug information
    error_log('POST data received in custody_transfer_form.php:');
    foreach ($_POST as $key => $value) {
        if ($key === 'releaser_signature' || $key === 'recipient_signature') {
            error_log("$key: " . (isset($value) ? "Length: " . strlen($value) : "NOT PRESENT"));
        } elseif ($key === 'device_ids') {
            error_log("$key: " . print_r($value, true));
        } else {
            error_log("$key: " . (isset($value) ? $value : "NOT PRESENT"));
        }
    }

    // Validate and sanitize input data
    $custody_id = filter_input(INPUT_POST, 'custody_id', FILTER_VALIDATE_INT);
    $job_code = filter_input(INPUT_POST, 'job_code', FILTER_SANITIZE_STRING);
    $transfer_date = filter_input(INPUT_POST, 'transfer_date', FILTER_SANITIZE_STRING);
    $transfer_reason = filter_input(INPUT_POST, 'transfer_reason', FILTER_SANITIZE_STRING);
    $released_by_name = filter_input(INPUT_POST, 'released_by_name', FILTER_SANITIZE_STRING);
    $released_by_position = filter_input(INPUT_POST, 'released_by_position', FILTER_SANITIZE_STRING);
    $received_by_name = filter_input(INPUT_POST, 'received_by_name', FILTER_SANITIZE_STRING);
    $received_by_position = filter_input(INPUT_POST, 'received_by_position', FILTER_SANITIZE_STRING);
    $notes = filter_input(INPUT_POST, 'notes', FILTER_SANITIZE_STRING);
    
    // Get signature data from POST
    $releaser_signature = $_POST['releaser_signature'] ?? null;
    $recipient_signature = $_POST['recipient_signature'] ?? null;
    
    // Get selected device IDs
    $selected_device_ids = isset($_POST['device_ids']) ? $_POST['device_ids'] : [];
    
    // Validate required fields
    if (!$custody_id || empty($job_code) || empty($transfer_date) || 
        empty($released_by_name) || empty($received_by_name) || empty($selected_device_ids)) {
        $error = "Please fill all required fields and select at least one device.";
    } else {
        try {
            // Connect to database
            $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
            
            // Start transaction
            $pdo->beginTransaction();
            
            // Prepare SQL based on whether notes column exists
            if ($notesColumnExists) {
                $sql = "
                    INSERT INTO custody_transfers (
                        custody_id,
                        job_code,
                        transfer_date,
                        transfer_reason,
                        released_by_name,
                        released_by_position,
                        received_by_name,
                        received_by_position,
                        releaser_signature,
                        recipient_signature,
                        created_at,
                        notes
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
                ";
                $params = [
                    $custody_id, 
                    $job_code, 
                    $transfer_date, 
                    $transfer_reason, 
                    $released_by_name, 
                    $released_by_position, 
                    $received_by_name, 
                    $received_by_position,
                    $releaser_signature,
                    $recipient_signature,
                    $notes
                ];
            } else {
                $sql = "
                    INSERT INTO custody_transfers (
                        custody_id,
                        job_code,
                        transfer_date,
                        transfer_reason,
                        released_by_name,
                        released_by_position,
                        received_by_name,
                        received_by_position,
                        releaser_signature,
                        recipient_signature,
                        created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ";
                $params = [
                    $custody_id, 
                    $job_code, 
                    $transfer_date, 
                    $transfer_reason, 
                    $released_by_name, 
                    $released_by_position, 
                    $received_by_name, 
                    $received_by_position,
                    $releaser_signature,
                    $recipient_signature
                ];
                
                // Save notes to audit log instead
                if (!empty($notes)) {
                    error_log("Saving notes to audit log since notes column doesn't exist: $notes");
                }
            }
            
            // Execute the insert
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute($params);
            
            if ($result) {
                $transfer_id = $pdo->lastInsertId();
                
                // Now add the device relationships
                $deviceStmt = $pdo->prepare("
                    INSERT INTO transfer_devices (
                        transfer_id,
                        device_id
                    ) VALUES (?, ?)
                ");
                
                foreach ($selected_device_ids as $device_id) {
                    $deviceStmt->execute([$transfer_id, $device_id]);
                }
                
                // Add to audit log
                $stmt = $pdo->prepare("
                    INSERT INTO audit_logs (
                        user_id,
                        action,
                        target_table,
                        target_id,
                        timestamp
                    ) VALUES (?, ?, ?, ?, NOW())
                ");
                
                $auditMessage = 'Added transfer record with ' . count($selected_device_ids) . ' devices';
                if (!$notesColumnExists && !empty($notes)) {
                    $auditMessage .= " - Notes: $notes";
                }
                
                $stmt->execute([
                    $_SESSION['user_id'] ?? 0,
                    $auditMessage,
                    'custody_transfers',
                    $transfer_id
                ]);
                
                // Commit the transaction
                $pdo->commit();
                
                $success = "Transfer record successfully created with " . count($selected_device_ids) . " devices!";
                
                // Redirect back to edit-coc page after successful submission
                header("Refresh: 2; URL=view-coc.php?id=$custody_id");
            } else {
                $pdo->rollBack();
                $error = "Failed to save transfer record.";
            }
            
        } catch (PDOException $e) {
            if (isset($pdo)) $pdo->rollBack();
            $error = "Database error: " . $e->getMessage();
            error_log("PDOException in custody_transfer_form: " . $e->getMessage());
        }
    }
}

// Get custody data if custody_id is provided
if (!empty($custody_id)) {
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        
        // Get custody record details
        $stmt = $pdo->prepare("SELECT * FROM custody_logs WHERE id = ?");
        $stmt->execute([$custody_id]);
        $custody_record = $stmt->fetch();
        
        if ($custody_record && empty($job_code)) {
            $job_code = $custody_record['job_code'];
        }
        
        // Get all devices for this job code
        $stmt = $pdo->prepare("
            SELECT * FROM custody_devices 
            WHERE job_code = ? 
            ORDER BY item_number
        ");
        $stmt->execute([$job_code]);
        $all_devices = $stmt->fetchAll();
        
        // If editing, get the transfer data
        if ($edit_transfer_id > 0) {
            $stmt = $pdo->prepare("
                SELECT * FROM custody_transfers 
                WHERE id = ? AND custody_id = ?
            ");
            $stmt->execute([$edit_transfer_id, $custody_id]);
            $transfer_data = $stmt->fetch();
            
            if ($transfer_data) {
                // Populate form fields with existing data
                $transfer_date = date('Y-m-d\TH:i', strtotime($transfer_data['transfer_date']));
                $transfer_reason = $transfer_data['transfer_reason'];
                $released_by_name = $transfer_data['released_by_name'];
                $released_by_position = $transfer_data['released_by_position'];
                $received_by_name = $transfer_data['received_by_name'];
                $received_by_position = $transfer_data['received_by_position'];
                
                // Get devices associated with this transfer
                $stmt = $pdo->prepare("
                    SELECT device_id FROM transfer_devices 
                    WHERE transfer_id = ?
                ");
                $stmt->execute([$edit_transfer_id]);
                $selected_devices = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
                
                // If no devices found in transfer_devices table, assume all devices
                if (empty($selected_devices)) {
                    $selected_devices = array_column($all_devices, 'id');
                }
            }
        }
    } catch (PDOException $e) {
        $error = "Failed to load custody data: " . $e->getMessage();
        error_log("Database error in custody_transfer_form: " . $e->getMessage());
    }
}

// Determine page title based on context
if ($edit_transfer_id > 0) {
    $page_title = "Edit Transfer #$edit_transfer_id";
} elseif ($device_id > 0) {
    $page_title = "Record Movement for " . htmlspecialchars($device_name);
} elseif ($move_all) {
    $page_title = "Move All Devices";
} else {
    $page_title = "Add Internal Transfer";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> | Project Management System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/coc-styles.css">
    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.0.0/dist/signature_pad.umd.min.js"></script>
    <style>
        .signature-container {
            border: 1px solid #e9ecef;
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 10px;
        }
        .signature-pad {
            width: 100%;
            height: 200px;
            background-color: #fff;
        }
        .signature-actions {
            display: flex;
            justify-content: flex-end;
            padding: 5px 10px;
            background-color: #f8f9fa;
        }
        .btn-text {
            background: none;
            border: none;
            color: #1098ad;
            cursor: pointer;
            padding: 5px 10px;
            font-size: 0.9em;
            border-radius: 4px;
        }
        .btn-text:hover {
            background-color: #e3f2fd;
        }
        
        /* Device selection styles */
        .device-selection {
            border: 1px solid #e9ecef;
            border-radius: 4px;
            max-height: 300px;
            overflow-y: auto;
            margin-bottom: 20px;
        }
        .device-item {
            display: flex;
            padding: 10px 15px;
            border-bottom: 1px solid #e9ecef;
            align-items: center;
        }
        .device-item:last-child {
            border-bottom: none;
        }
        .device-checkbox {
            margin-right: 10px;
        }
        .device-info {
            flex-grow: 1;
        }
        .device-name {
            font-weight: 500;
            margin-bottom: 4px;
        }
        .device-serial {
            font-size: 0.85em;
            color: #6c757d;
        }
        .device-actions {
            display: flex;
        }
        .device-action-btn {
            background: none;
            border: none;
            color: #e03131;
            cursor: pointer;
            padding: 5px;
            font-size: 0.9em;
            border-radius: 4px;
            opacity: 0.6;
            transition: opacity 0.2s ease;
        }
        .device-action-btn:hover {
            opacity: 1;
            background-color: #ffe3e3;
        }
        .empty-selection {
            text-align: center;
            padding: 15px;
            color: #6c757d;
        }
        .text-muted {
            color: #6c757d;
            font-size: 0.9em;
            margin-top: 5px;
        }
        .selected-count {
            margin-bottom: 10px;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <div class="top-bar">
        <div class="container">
            <div class="system-info">
                <span class="timestamp">Current Date and Time (UTC - YYYY-MM-DD HH:MM:SS formatted): <?= CURRENT_TIMESTAMP ?></span>
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
                <h1><?= $page_title ?></h1>
                <nav class="breadcrumb">
                    <a href="dashboard.php">Dashboard</a> / 
                    <a href="coc.php">Chain of Custody</a> / 
                    <a href="view-coc.php?id=<?= $custody_id ?>">View Form</a> / 
                    <span><?= $edit_transfer_id > 0 ? 'Edit Transfer' : 'Add Transfer' ?></span>
                </nav>
            </div>
            
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?= $error ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($success)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?= $success ?>
                    <p>Redirecting back to the custody form...</p>
                </div>
            <?php endif; ?>
            
            <div class="content-card">
                <div class="form-header">
                    <div class="form-header-icon">
                        <i class="fas fa-exchange-alt"></i>
                    </div>
                    <div class="form-header-info">
                        <h2><?= $page_title ?></h2>
                        <p>
                            <?php if ($edit_transfer_id > 0): ?>
                                Update the transfer record details.
                            <?php elseif ($device_id > 0): ?>
                                Record movement for this specific device in the chain of custody.
                            <?php elseif ($move_all): ?>
                                Move multiple devices in one transfer record.
                            <?php else: ?>
                                Record a transfer of custody between individuals.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                
                <form method="post" action="<?= htmlspecialchars($_SERVER["PHP_SELF"] . "?" . http_build_query($_GET)) ?>" id="transferForm">
                    <input type="hidden" name="custody_id" value="<?= $custody_id ?>">
                    
                    <div class="form-section">
                        <h2>Transfer Details</h2>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="job_code">Job Code*</label>
                                <input type="text" class="form-control" id="job_code" name="job_code" 
                                       value="<?= htmlspecialchars($job_code) ?>" readonly required>
                            </div>
                            
                            <div class="form-group">
                                <label for="transfer_date">Transfer Date and Time*</label>
                                <input type="datetime-local" class="form-control" id="transfer_date" 
                                       name="transfer_date" value="<?= htmlspecialchars($transfer_date) ?>" required>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="transfer_reason">Transfer Reason*</label>
                            <input type="text" class="form-control" id="transfer_reason" 
                                   name="transfer_reason" value="<?= htmlspecialchars($transfer_reason) ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="notes">Notes (optional)</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3"
                                      placeholder="Additional details about this transfer..."><?= htmlspecialchars($transfer_data['notes'] ?? '') ?></textarea>
                        </div>
                    </div>
                    
                    <!-- Devices Section -->
                    <div class="form-section">
                        <h2>Devices</h2>
                        <p class="selected-count">
                            <span id="selectedCount">0</span> device(s) selected
                        </p>
                        
                        <?php if (!empty($all_devices)): ?>
                            <div class="device-selection" id="deviceSelection">
                                <?php foreach($all_devices as $device): 
                                    $isChecked = false;
                                    
                                    // Check if this device should be selected by default
                                    if ($device_id > 0 && $device['id'] == $device_id) {
                                        $isChecked = true;
                                    } else if ($edit_transfer_id > 0 && isset($selected_devices) && in_array($device['id'], $selected_devices)) {
                                        $isChecked = true;
                                    } else if ($move_all || ($device_id == 0 && $edit_transfer_id == 0)) {
                                        $isChecked = true;
                                    }
                                ?>
                                    <div class="device-item" id="device-item-<?= $device['id'] ?>">
                                        <input type="checkbox" name="device_ids[]" value="<?= $device['id'] ?>" 
                                               class="device-checkbox" id="device_<?= $device['id'] ?>"
                                               <?= $isChecked ? 'checked' : '' ?>>
                                        <div class="device-info">
                                            <label for="device_<?= $device['id'] ?>" class="device-name">
                                                <?= htmlspecialchars($device['description']) ?>
                                            </label>
                                            <div class="device-serial">
                                                SN: <?= htmlspecialchars($device['serial_number']) ?>
                                            </div>
                                        </div>
                                        <div class="device-actions">
                                            <button type="button" class="device-action-btn" 
                                                    onclick="removeDevice(<?= $device['id'] ?>)" title="Remove device">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <p class="text-muted">
                                <i class="fas fa-info-circle"></i> 
                                Select the devices that are part of this transfer. You can remove devices from the selection if needed.
                            </p>
                        <?php else: ?>
                            <div class="empty-selection">
                                <i class="fas fa-laptop"></i>
                                <p>No devices found for this job code.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-section">
                        <h2>Released By (Current Custodian)</h2>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="released_by_name">Full Name*</label>
                                <input type="text" class="form-control" id="released_by_name" 
                                       name="released_by_name" value="<?= htmlspecialchars($released_by_name) ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="released_by_position">Position</label>
                                <input type="text" class="form-control" id="released_by_position" 
                                       name="released_by_position" value="<?= htmlspecialchars($released_by_position) ?>">
                            </div>
                        </div>
                        
                        <div class="form-group full-width">
                            <label>Releaser Signature*</label>
                            <div class="signature-container">
                                <canvas id="releaser-signature-pad" class="signature-pad"></canvas>
                                <div class="signature-actions">
                                    <button type="button" id="clear-releaser" class="btn-text">Clear</button>
                                </div>
                            </div>
                            <input type="hidden" name="releaser_signature" id="releaser-signature-data">
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h2>Received By (New Custodian)</h2>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="received_by_name">Full Name*</label>
                                <input type="text" class="form-control" id="received_by_name" 
                                       name="received_by_name" value="<?= htmlspecialchars($received_by_name) ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="received_by_position">Position</label>
                                <input type="text" class="form-control" id="received_by_position" 
                                       name="received_by_position" value="<?= htmlspecialchars($received_by_position) ?>">
                            </div>
                        </div>
                        
                        <div class="form-group full-width">
                            <label>Recipient Signature*</label>
                            <div class="signature-container">
                                <canvas id="recipient-signature-pad" class="signature-pad"></canvas>
                                <div class="signature-actions">
                                    <button type="button" id="clear-recipient" class="btn-text">Clear</button>
                                </div>
                            </div>
                            <input type="hidden" name="recipient_signature" id="recipient-signature-data">
                        </div>
                    </div>
                    
                    <div class="form-buttons">
                        <a href="view-coc.php?id=<?= $custody_id ?>" class="cancel-button">Cancel</a>
                        <button type="submit" class="submit-button"><?= $edit_transfer_id > 0 ? 'Update Transfer' : 'Submit Transfer' ?></button>
                    </div>
                </form>
            </div>
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
            
            // Releaser signature pad
            const releaserCanvas = document.getElementById('releaser-signature-pad');
            const releaserSignaturePad = new SignaturePad(releaserCanvas, {
                backgroundColor: 'rgba(255, 255, 255, 0)',
                penColor: 'black'
            });
            
            // Recipient signature pad
            const recipientCanvas = document.getElementById('recipient-signature-pad');
            const recipientSignaturePad = new SignaturePad(recipientCanvas, {
                backgroundColor: 'rgba(255, 255, 255, 0)',
                penColor: 'black'
            });
            
            // Clear buttons
            document.getElementById('clear-releaser').addEventListener('click', function() {
                releaserSignaturePad.clear();
            });
            
            document.getElementById('clear-recipient').addEventListener('click', function() {
                recipientSignaturePad.clear();
            });
            
            // Initialize signatures from existing data if available
            <?php if (!empty($transfer_data['releaser_signature'])): ?>
            if (releaserSignaturePad) {
                releaserSignaturePad.fromDataURL('<?= $transfer_data['releaser_signature'] ?>');
            }
            <?php endif; ?>
            
            <?php if (!empty($transfer_data['recipient_signature'])): ?>
            if (recipientSignaturePad) {
                recipientSignaturePad.fromDataURL('<?= $transfer_data['recipient_signature'] ?>');
            }
            <?php endif; ?>
            
            // Form submission - capture signatures before submit
            document.getElementById('transferForm').addEventListener('submit', function(e) {
                // Check for selected devices
                const selectedDevices = document.querySelectorAll('input[name="device_ids[]"]:checked');
                if (selectedDevices.length === 0) {
                    alert('Please select at least one device for the transfer');
                    e.preventDefault();
                    return false;
                }
                
                // Check if signatures are provided
                if (releaserSignaturePad.isEmpty()) {
                    alert('Please provide releaser signature');
                    e.preventDefault();
                    return false;
                }
                
                if (recipientSignaturePad.isEmpty()) {
                    alert('Please provide recipient signature');
                    e.preventDefault();
                    return false;
                }
                
                // Save signature data to hidden fields
                document.getElementById('releaser-signature-data').value = 
                    releaserSignaturePad.toDataURL('image/png');
                document.getElementById('recipient-signature-data').value = 
                    recipientSignaturePad.toDataURL('image/png');
                
                console.log("Signatures captured successfully");
                console.log("Releaser signature length: " + document.getElementById('releaser-signature-data').value.length);
                console.log("Recipient signature length: " + document.getElementById('recipient-signature-data').value.length);
                
                // Allow form submission
                return true;
            });
            
            // Resize signature pads when window resizes
            window.addEventListener('resize', resizeCanvas);
            function resizeCanvas() {
                const ratio = Math.max(window.devicePixelRatio || 1, 1);
                
                // Releaser canvas
                releaserCanvas.width = releaserCanvas.offsetWidth * ratio;
                releaserCanvas.height = releaserCanvas.offsetHeight * ratio;
                releaserCanvas.getContext('2d').scale(ratio, ratio);
                releaserSignaturePad.clear();
                
                // Recipient canvas
                recipientCanvas.width = recipientCanvas.offsetWidth * ratio;
                recipientCanvas.height = recipientCanvas.offsetHeight * ratio;
                recipientCanvas.getContext('2d').scale(ratio, ratio);
                recipientSignaturePad.clear();
                
                // Reinitialize signatures if we're editing
                <?php if (!empty($transfer_data['releaser_signature'])): ?>
                releaserSignaturePad.fromDataURL('<?= $transfer_data['releaser_signature'] ?>');
                <?php endif; ?>
                
                <?php if (!empty($transfer_data['recipient_signature'])): ?>
                recipientSignaturePad.fromDataURL('<?= $transfer_data['recipient_signature'] ?>');
                <?php endif; ?>
            }
            
            resizeCanvas();
            
            // Update selected count
            updateSelectedCount();
            
            // Listen for changes to checkboxes
            const checkboxes = document.querySelectorAll('.device-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.addEventListener('change', updateSelectedCount);
            });
        });
        
        // Function to remove a device from selection
        function removeDevice(deviceId) {
            const checkbox = document.getElementById(`device_${deviceId}`);
            if (checkbox) {
                checkbox.checked = false;
                updateSelectedCount();
                
                // Visually indicate removed item
                const deviceItem = document.getElementById(`device-item-${deviceId}`);
                if (deviceItem) {
                    deviceItem.style.opacity = '0.6';
                    deviceItem.style.backgroundColor = '#f8f9fa';
                    
                    // Reset after animation
                    setTimeout(() => {
                        deviceItem.style.opacity = '1';
                    }, 1000);
                }
            }
        }
        
        // Function to update selected devices count
        function updateSelectedCount() {
            const selectedDevices = document.querySelectorAll('input[name="device_ids[]"]:checked');
            const countElement = document.getElementById('selectedCount');
            
            if (countElement) {
                countElement.textContent = selectedDevices.length;
                
                // Update visual feedback
                if (selectedDevices.length === 0) {
                    countElement.style.color = '#e03131'; // Red if none selected
                } else {
                    countElement.style.color = '#2b8a3e'; // Green if some selected
                }
            }
        }
    </script>
</body>
</html>