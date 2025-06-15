<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Session check
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

define('CURRENT_TIMESTAMP', '2025-06-12 19:10:11');
define('CURRENT_USER', 'nkosinathil');

include_once('db-connection.php');

$current_user = $_SESSION['username'] ?? 'nkosinathil';
$error_message = '';
$success_message = '';

// Get custody ID from URL parameter
$custody_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Check if ID is valid
if ($custody_id <= 0) {
    $error_message = "Invalid Chain of Custody ID.";
}

$custody_data = [];
$devices = [];
$transfers = []; // Array to store internal transfers

// Function to check if the custody_transfers table has signature columns
function ensureTransferTableHasSignatureColumns($pdo) {
    try {
        // Check if the columns exist
        $stmt = $pdo->query("SHOW COLUMNS FROM custody_transfers LIKE 'releaser_signature'");
        $hasReleaserSignature = $stmt->fetch() !== false;
        
        $stmt = $pdo->query("SHOW COLUMNS FROM custody_transfers LIKE 'recipient_signature'");
        $hasRecipientSignature = $stmt->fetch() !== false;
        
        // If either column is missing, add them
        if (!$hasReleaserSignature || !$hasRecipientSignature) {
            $alterQuery = "ALTER TABLE custody_transfers ";
            
            if (!$hasReleaserSignature) {
                $alterQuery .= "ADD COLUMN releaser_signature LONGTEXT";
                if (!$hasRecipientSignature) {
                    $alterQuery .= ", ";
                }
            }
            
            if (!$hasRecipientSignature) {
                $alterQuery .= "ADD COLUMN recipient_signature LONGTEXT";
            }
            
            $pdo->exec($alterQuery);
            return true;
        }
        
        return false;
    } catch (PDOException $e) {
        error_log('Error checking/adding signature columns: ' . $e->getMessage());
        return false;
    }
}

// Handle adding a new internal transfer with signatures
function handleInternalTransfer($pdo, $custody_id, $custody_data, $current_user) {
    // Debug output
    error_log("====== SIGNATURE DEBUG START ======");
    error_log("POST data received:");
    foreach ($_POST as $key => $value) {
        if ($key === 'transfer_releaser_signature' || $key === 'transfer_recipient_signature') {
            error_log("$key: " . (isset($value) ? "present with length: " . strlen($value) : "NOT PRESENT"));
            // Output first 100 characters to see what we're dealing with
            if (isset($value) && !empty($value)) {
                error_log("$key starts with: " . substr($value, 0, 100) . "...");
            }
        } else {
            error_log("$key: " . (isset($value) ? $value : "NOT PRESENT"));
        }
    }
    
    try {
        // Debug - what we're about to insert
        $dataToInsert = [
            'custody_id' => $custody_id,
            'job_code' => $custody_data['job_code'],
            'transfer_date' => $_POST['transfer_date'] ?? 'MISSING',
            'transfer_reason' => $_POST['transfer_reason'] ?? 'MISSING',
            'released_by_name' => $_POST['transfer_released_by_name'] ?? 'MISSING',
            'released_by_position' => $_POST['transfer_released_by_position'] ?? 'MISSING',
            'received_by_name' => $_POST['transfer_received_by_name'] ?? 'MISSING',
            'received_by_position' => $_POST['transfer_received_by_position'] ?? 'MISSING',
            'releaser_signature' => isset($_POST['transfer_releaser_signature']) ? "Present (" . strlen($_POST['transfer_releaser_signature']) . " bytes)" : "MISSING",
            'recipient_signature' => isset($_POST['transfer_recipient_signature']) ? "Present (" . strlen($_POST['transfer_recipient_signature']) . " bytes)" : "MISSING",
        ];
        error_log("Data being inserted into custody_transfers table: " . print_r($dataToInsert, true));
        
        // Create SQL statement
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
        error_log("SQL query: " . $sql);
        
        $stmt = $pdo->prepare($sql);
        
        // Execute with all parameters including signatures
        $result = $stmt->execute([
            $custody_id,
            $custody_data['job_code'],
            $_POST['transfer_date'],
            $_POST['transfer_reason'],
            $_POST['transfer_released_by_name'],
            $_POST['transfer_released_by_position'],
            $_POST['transfer_received_by_name'],
            $_POST['transfer_received_by_position'],
            $_POST['transfer_releaser_signature'] ?? null,
            $_POST['transfer_recipient_signature'] ?? null
        ]);
        
        // Debug result
        if ($result) {
            $transfer_id = $pdo->lastInsertId();
            error_log("Insert successful! New transfer ID: $transfer_id");
            
            // Verify what was actually saved
            $verify = $pdo->prepare("SELECT * FROM custody_transfers WHERE id = ?");
            $verify->execute([$transfer_id]);
            $saved_transfer = $verify->fetch(PDO::FETCH_ASSOC);
            
            error_log("Verification of saved record:");
            error_log("Releaser signature: " . (empty($saved_transfer['releaser_signature']) ? "Empty" : "Present (" . strlen($saved_transfer['releaser_signature']) . " bytes)"));
            error_log("Recipient signature: " . (empty($saved_transfer['recipient_signature']) ? "Empty" : "Present (" . strlen($saved_transfer['recipient_signature']) . " bytes)"));
            
            if (empty($saved_transfer['releaser_signature']) || empty($saved_transfer['recipient_signature'])) {
                error_log("ERROR: One or both signatures are missing in the saved record!");
            } else {
                error_log("SUCCESS: Both signatures appear to be saved correctly!");
            }
        } else {
            error_log("Insert failed. Error info: " . print_r($stmt->errorInfo(), true));
        }
        error_log("====== SIGNATURE DEBUG END ======");
        
        return "Internal transfer added " . ($result ? "successfully" : "with errors - check logs");
    } catch (PDOException $e) {
        error_log("PDO Exception: " . $e->getMessage());
        throw $e;
    }
}

// Function to determine if custody has been returned
function isReturned($custody_data) {
    // Check if returned_at exists and has a value
    if (!empty($custody_data['returned_at'])) {
        return true;
    }
    
    // If using return_status field
    if (isset($custody_data['return_status']) && $custody_data['return_status'] === 'returned') {
        return true;
    }
    
    return false;
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Create custody_transfers table if it doesn't exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS custody_transfers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            custody_id INT NOT NULL,
            job_code VARCHAR(50) NOT NULL,
            transfer_date DATETIME NOT NULL,
            transfer_reason TEXT,
            released_by_name VARCHAR(255) NOT NULL,
            released_by_position VARCHAR(255),
            received_by_name VARCHAR(255) NOT NULL,
            received_by_position VARCHAR(255),
            releaser_signature LONGTEXT,
            recipient_signature LONGTEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (custody_id) REFERENCES custody_logs(id) ON DELETE CASCADE
        )
    ");
    
    // Ensure the table has signature columns
    ensureTransferTableHasSignatureColumns($pdo);

    // Fetch custody log data
    $stmt = $pdo->prepare("
        SELECT cl.*, c.case_description
        FROM custody_logs cl
        LEFT JOIN cases c ON cl.job_code = c.case_number
        WHERE cl.id = ?
    ");
    $stmt->execute([$custody_id]);
    $custody_data = $stmt->fetch();
    
    if (!$custody_data) {
        $error_message = "Chain of Custody record not found.";
    } else {
        // Fetch devices
        $stmt = $pdo->prepare("
            SELECT * 
            FROM custody_devices 
            WHERE job_code = ?
            ORDER BY item_number
        ");
        $stmt->execute([$custody_data['job_code']]);
        $devices = $stmt->fetchAll();
        
        // Check if we have a custody_transfers table and fetch transfers
        try {
            $pdo->query("SELECT 1 FROM custody_transfers LIMIT 1");
            $has_transfers_table = true;
            
            $stmt = $pdo->prepare("
                SELECT * 
                FROM custody_transfers 
                WHERE custody_id = ?
                ORDER BY transfer_date DESC
            ");
            $stmt->execute([$custody_id]);
            $transfers = $stmt->fetchAll();
        } catch (PDOException $e) {
            // Table doesn't exist or other error
            $has_transfers_table = false;
        }
    }
    
    // Form submission - Update Chain of Custody
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $pdo->beginTransaction();
        
        try {
            // If action is mark_returned, handle return
            if (isset($_POST['action']) && $_POST['action'] === 'mark_returned') {
                // Check if return_status column exists
                try {
                    $pdo->query("SELECT return_status FROM custody_logs LIMIT 1");
                    $has_return_status = true;
                } catch (PDOException $e) {
                    $has_return_status = false;
                }
                
                if ($has_return_status) {
                    $stmt = $pdo->prepare("
                        UPDATE custody_logs
                        SET return_status = 'returned',
                            returned_at = NOW(),
                            returned_by = ?,
                            returned_notes = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $current_user,
                        $_POST['return_notes'] ?? '',
                        $custody_id
                    ]);
                } else {
                    // Alternative update if return_status doesn't exist
                    $stmt = $pdo->prepare("
                        UPDATE custody_logs
                        SET returned_at = NOW(),
                            returned_by = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $current_user,
                        $custody_id
                    ]);
                }
                
                // Log the return in audit_logs
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
                    'Marked chain of custody as returned: ' . ($_POST['return_notes'] ?? ''),
                    'custody_logs',
                    $custody_id
                ]);
                
                $success_message = "Devices have been marked as returned successfully.";
            }
            // If action is add_transfer, add new internal transfer
            else if (isset($_POST['action']) && $_POST['action'] === 'add_transfer') {
                if ($has_transfers_table) {
                    $success_message = handleInternalTransfer($pdo, $custody_id, $custody_data, $current_user);
                } else {
                    // If no transfers table, log in audit_logs
                    $transfer_details = "Transfer: " . $_POST['transfer_date'] . 
                                       " - From: " . $_POST['transfer_released_by_name'] .
                                       " (" . $_POST['transfer_released_by_position'] . ")" .
                                       " To: " . $_POST['transfer_received_by_name'] .
                                       " (" . $_POST['transfer_received_by_position'] . ")" .
                                       " - Reason: " . $_POST['transfer_reason'];
                                       
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
                        $transfer_details,
                        'custody_logs',
                        $custody_id
                    ]);
                }
            }
            // Regular form submission to update chain of custody details
            else {
                // Update custody_logs table with main info
                $stmt = $pdo->prepare("
                    UPDATE custody_logs SET
                        released_by_name = ?,
                        released_by_position = ?,
                        released_by_phone = ?,
                        released_by_email = ?,
                        released_by_datetime = ?,
                        released_reason = ?,
                        received_by_name = ?,
                        received_by_position = ?,
                        received_by_phone = ?,
                        received_by_email = ?,
                        received_by_datetime = ?,
                        received_reason = ?
                    WHERE id = ?
                ");
                
                // Format the datetime values
                $released_datetime = date('Y-m-d H:i:s', strtotime($_POST['released_by_datetime']));
                $received_datetime = date('Y-m-d H:i:s', strtotime($_POST['received_by_datetime']));
                
                $stmt->execute([
                    $_POST['released_by_name'],
                    $_POST['released_by_position'],
                    $_POST['released_by_phone'],
                    $_POST['released_by_email'],
                    $released_datetime,
                    $_POST['released_reason'],
                    $_POST['received_by_name'],
                    $_POST['received_by_position'],
                    $_POST['received_by_phone'],
                    $_POST['received_by_email'],
                    $received_datetime,
                    $_POST['received_reason'],
                    $custody_id
                ]);
                
                // Update signatures if new ones are provided
                if (!empty($_POST['released_signature_data'])) {
                    $stmt = $pdo->prepare("
                        UPDATE custody_logs SET
                            released_by_signature = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$_POST['released_signature_data'], $custody_id]);
                }
                
                if (!empty($_POST['received_signature_data'])) {
                    $stmt = $pdo->prepare("
                        UPDATE custody_logs SET
                            received_by_signature = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$_POST['received_signature_data'], $custody_id]);
                }
                
                // Handle device updates
                if (isset($_POST['device_id']) && is_array($_POST['device_id'])) {
                    foreach ($_POST['device_id'] as $index => $device_id) {
                        // Create description from device type, make, and model
                        $description = $_POST['device_type'][$index];
                        if (!empty($_POST['make'][$index])) {
                            $description .= ' - ' . $_POST['make'][$index];
                        }
                        if (!empty($_POST['model'][$index])) {
                            $description .= ' ' . $_POST['model'][$index];
                        }
                        
                        $stmt = $pdo->prepare("
                            UPDATE custody_devices SET
                                description = ?,
                                serial_number = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $description,
                            $_POST['serial_number'][$index],
                            $device_id
                        ]);
                    }
                }
                
                // Handle new devices
                if (isset($_POST['new_device_type']) && is_array($_POST['new_device_type'])) {
                    $device_stmt = $pdo->prepare("
                        INSERT INTO custody_devices (
                            job_code,
                            item_number,
                            description,
                            serial_number,
                            created_at
                        ) VALUES (?, ?, ?, ?, NOW())
                    ");
                    
                    for ($i = 0; $i < count($_POST['new_device_type']); $i++) {
                        if (!empty($_POST['new_device_type'][$i])) {
                            // Create description from device type, make, and model
                            $description = $_POST['new_device_type'][$i];
                            if (!empty($_POST['new_make'][$i])) {
                                $description .= ' - ' . $_POST['new_make'][$i];
                            }
                            if (!empty($_POST['new_model'][$i])) {
                                $description .= ' ' . $_POST['new_model'][$i];
                            }
                            
                            $item_number = count($devices) + $i + 1;
                            
                            $device_stmt->execute([
                                $custody_data['job_code'],
                                $item_number, // item_number starts at 1
                                $description,
                                $_POST['new_serial_number'][$i]
                            ]);
                        }
                    }
                }
                
                // Log the edit action
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
                    'Edited Chain of Custody form',
                    'custody_logs',
                    $custody_id
                ]);
                
                $success_message = "Chain of Custody updated successfully.";
            }
            
            $pdo->commit();
            
            // Refresh data
            $stmt = $pdo->prepare("
                SELECT cl.*, c.case_description
                FROM custody_logs cl
                LEFT JOIN cases c ON cl.job_code = c.case_number
                WHERE cl.id = ?
            ");
            $stmt->execute([$custody_id]);
            $custody_data = $stmt->fetch();
            
            // Refresh devices
            $stmt = $pdo->prepare("
                SELECT * 
                FROM custody_devices 
                WHERE job_code = ?
                ORDER BY item_number
            ");
            $stmt->execute([$custody_data['job_code']]);
            $devices = $stmt->fetchAll();
            
            // Refresh transfers if table exists
            if ($has_transfers_table) {
                $stmt = $pdo->prepare("
                    SELECT * 
                    FROM custody_transfers 
                    WHERE custody_id = ?
                    ORDER BY transfer_date DESC
                ");
                $stmt->execute([$custody_id]);
                $transfers = $stmt->fetchAll();
            }
            
            // Redirect to view page after short delay
            if (!isset($_POST['action']) || $_POST['action'] !== 'add_transfer') {
                header("Refresh: 2; URL=view-coc.php?id=$custody_id");
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Chain of Custody Error: " . $e->getMessage());
            $error_message = "Error updating Chain of Custody: " . $e->getMessage();
        }
    }
    
} catch (PDOException $e) {
    error_log('Database Error: ' . $e->getMessage());
    $error_message = "Database error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit Chain of Custody Form - Project Management System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="css/coc-styles.css" rel="stylesheet">
    <!-- Signature Pad Library -->
    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.0.0/dist/signature_pad.umd.min.js"></script>
    <script src="js/coc-script.js"></script>
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
                <h1>Edit Chain of Custody Form</h1>
                <nav class="breadcrumb">
                    <a href="dashboard.php">Dashboard</a> / 
                    <a href="coc.php">Chain of Custody</a> / 
                    <a href="view-coc.php?id=<?= $custody_id ?>">View Details</a> / 
                    <span>Edit Form</span>
                </nav>
            </div>

            <?php if ($error_message): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?= $error_message ?>
                </div>
            <?php endif; ?>

            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?= $success_message ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($custody_data)): ?>
                <div class="content-card">
                    <div class="form-header">
                        <div class="form-header-icon">
                            <i class="fas fa-edit"></i>
                        </div>
                        <div class="form-header-info">
                            <h2>Editing Chain of Custody - <?= htmlspecialchars($custody_data['job_code']) ?></h2>
                            <p>Manage device movement with internal transfers or mark devices as returned to the client.</p>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="action-buttons-container">
                        <button type="button" id="addTransferBtn" class="action-button secondary">
                            <i class="fas fa-exchange-alt"></i> Add Internal Transfer
                        </button>
                        
                        <?php if (!isReturned($custody_data)): ?>
                            <button type="button" id="markReturnedBtn" class="action-button primary">
                                <i class="fas fa-undo-alt"></i> Mark as Returned
                            </button>
                        <?php endif; ?>
                    </div>
                    
                    <form method="post" id="cocForm">
                        <div class="form-section">
                            <h2>Case Information</h2>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="job_code">Job Code</label>
                                    <input type="text" id="job_code" name="job_code" value="<?= htmlspecialchars($custody_data['job_code']) ?>" readonly>
                                </div>
                                <?php if (isset($custody_data['case_description'])): ?>
                                    <div class="form-group">
                                        <label>Case Description</label>
                                        <textarea readonly><?= htmlspecialchars($custody_data['case_description']) ?></textarea>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="form-section-header">
                            <h2><i class="fas fa-arrow-right"></i> INITIAL DEVICE TRANSFER</h2>
                        </div>
                        
                        <div class="form-section">
                            <h2>Released By (Client)</h2>
                            <div class="form-info-banner">
                                <i class="fas fa-info-circle"></i>
                                <p>Information about the client representative who released the devices for analysis</p>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="released_by_name">Full Name</label>
                                    <input type="text" id="released_by_name" name="released_by_name" value="<?= htmlspecialchars($custody_data['released_by_name']) ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="released_by_position">Company/Position</label>
                                    <input type="text" id="released_by_position" name="released_by_position" value="<?= htmlspecialchars($custody_data['released_by_position']) ?>" required>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="released_by_email">Email</label>
                                    <input type="email" id="released_by_email" name="released_by_email" value="<?= htmlspecialchars($custody_data['released_by_email'] ?? '') ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="released_by_phone">Phone</label>
                                    <input type="tel" id="released_by_phone" name="released_by_phone" value="<?= htmlspecialchars($custody_data['released_by_phone']) ?>" required>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="released_by_datetime">Date & Time</label>
                                    <input type="datetime-local" id="released_by_datetime" name="released_by_datetime" value="<?= date('Y-m-d\TH:i', strtotime($custody_data['released_by_datetime'])) ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="released_reason">Purpose of Analysis</label>
                                    <input type="text" id="released_reason" name="released_reason" value="<?= htmlspecialchars($custody_data['released_reason']) ?>" required>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group full-width">
                                    <label for="released-signature-pad">Client Signature</label>
                                    <div class="signature-container">
                                        <canvas id="released-signature-pad" class="signature-pad"></canvas>
                                        <input type="hidden" name="released_signature_data" id="released_signature_data">
                                        <div class="signature-actions">
                                            <button type="button" id="clear-released-signature" class="btn-text">Clear</button>
                                        </div>
                                    </div>
                                    <?php if (!empty($custody_data['released_by_signature'])): ?>
                                        <div class="current-signature">
                                            <p>Current signature:</p>
                                            <img src="<?= $custody_data['released_by_signature'] ?>" alt="Current Client Signature" class="signature-preview">
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-section">
                            <h2>Received By (Company Representative)</h2>
                            <div class="form-info-banner">
                                <i class="fas fa-info-circle"></i>
                                <p>Information about the company representative who received the devices</p>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="received_by_name">Full Name</label>
                                    <input type="text" id="received_by_name" name="received_by_name" value="<?= htmlspecialchars($custody_data['received_by_name']) ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="received_by_position">Position</label>
                                    <input type="text" id="received_by_position" name="received_by_position" value="<?= htmlspecialchars($custody_data['received_by_position']) ?>" required>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="received_by_email">Email</label>
                                    <input type="email" id="received_by_email" name="received_by_email" value="<?= htmlspecialchars($custody_data['received_by_email'] ?? '') ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="received_by_phone">Phone</label>
                                    <input type="tel" id="received_by_phone" name="received_by_phone" value="<?= htmlspecialchars($custody_data['received_by_phone']) ?>" required>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="received_by_datetime">Date & Time</label>
                                    <input type="datetime-local" id="received_by_datetime" name="received_by_datetime" value="<?= date('Y-m-d\TH:i', strtotime($custody_data['received_by_datetime'])) ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="received_reason">Reason for Receipt</label>
                                    <input type="text" id="received_reason" name="received_reason" value="<?= htmlspecialchars($custody_data['received_reason'] ?? 'Digital Forensic Analysis') ?>" required>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group full-width">
                                    <label for="received-signature-pad">Company Representative Signature</label>
                                    <div class="signature-container">
                                        <canvas id="received-signature-pad" class="signature-pad"></canvas>
                                        <input type="hidden" name="received_signature_data" id="received_signature_data">
                                        <div class="signature-actions">
                                            <button type="button" id="clear-received-signature" class="btn-text">Clear</button>
                                        </div>
                                    </div>
                                    <?php if (!empty($custody_data['received_by_signature'])): ?>
                                        <div class="current-signature">
                                            <p>Current signature:</p>
                                            <img src="<?= $custody_data['received_by_signature'] ?>" alt="Current Company Representative Signature" class="signature-preview">
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-section">
                            <div class="section-header-with-actions">
                                <h2>Device Information</h2>
                                <button type="button" id="addDevice" class="action-button secondary">
                                    <i class="fas fa-plus"></i> Add New Device
                                </button>
                            </div>
                            
                            <?php if (!empty($devices)): ?>
                                <div class="devices-container" id="existingDevices">
                                    <?php foreach ($devices as $index => $device): ?>
                                        <div class="device-entry">
                                            <div class="device-header">
                                                <h3>Device #<?= $device['item_number'] ?></h3>
                                                <span class="device-actions">
                                                    <button type="button" class="remove-device-btn" data-id="<?= $device['id'] ?>">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </span>
                                            </div>
                                            <input type="hidden" name="device_id[]" value="<?= $device['id'] ?>">
                                            <?php
                                            // Parse description to get type, make, model
                                            $type = $device['description'];
                                            $make = '';
                                            $model = '';
                                            
                                            if (strpos($device['description'], ' - ') !== false) {
                                                list($type, $remainder) = explode(' - ', $device['description'], 2);
                                                if (strpos($remainder, ' ') !== false) {
                                                    list($make, $model) = explode(' ', $remainder, 2);
                                                } else {
                                                    $make = $remainder;
                                                }
                                            }
                                            ?>
                                            <div class="form-row">
                                                <div class="form-group">
                                                    <label>Device Type</label>
                                                    <select name="device_type[]" required>
                                                        <option value="">Select Type</option>
                                                        <option value="Computer" <?= ($type == 'Computer') ? 'selected' : '' ?>>Computer</option>
                                                        <option value="Laptop" <?= ($type == 'Laptop') ? 'selected' : '' ?>>Laptop</option>
                                                        <option value="Server" <?= ($type == 'Server') ? 'selected' : '' ?>>Server</option>
                                                        <option value="Mobile Phone" <?= ($type == 'Mobile Phone') ? 'selected' : '' ?>>Mobile Phone</option>
                                                        <option value="Tablet" <?= ($type == 'Tablet') ? 'selected' : '' ?>>Tablet</option>
                                                        <option value="Hard Drive" <?= ($type == 'Hard Drive') ? 'selected' : '' ?>>Hard Drive</option>
                                                        <option value="USB Drive" <?= ($type == 'USB Drive') ? 'selected' : '' ?>>USB Drive</option>
                                                        <option value="Memory Card" <?= ($type == 'Memory Card') ? 'selected' : '' ?>>Memory Card</option>
                                                        <option value="Other" <?= ($type == 'Other') ? 'selected' : '' ?>>Other</option>
                                                    </select>
                                                </div>
                                                <div class="form-group">
                                                    <label>Make</label>
                                                    <input type="text" name="make[]" value="<?= htmlspecialchars($make) ?>" placeholder="e.g. Apple, Dell, Samsung">
                                                </div>
                                            </div>
                                            <div class="form-row">
                                                <div class="form-group">
                                                    <label>Model</label>
                                                    <input type="text" name="model[]" value="<?= htmlspecialchars($model) ?>" placeholder="e.g. MacBook Pro, Inspiron">
                                                </div>
                                                <div class="form-group">
                                                    <label>Serial Number</label>
                                                    <input type="text" name="serial_number[]" value="<?= htmlspecialchars($device['serial_number']) ?>" placeholder="Serial/IMEI Number">
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="form-info-banner warning">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <p>No devices are currently associated with this Chain of Custody. Add devices using the button above.</p>
                                </div>
                            <?php endif; ?>
                            
                            <div id="newDevices" class="devices-container">
                                <!-- New devices will be added here -->
                            </div>
                        </div>
                        
                        <!-- Internal Transfers Section -->
                        <div class="form-section-header form-section-header-alt">
                            <h2><i class="fas fa-exchange-alt"></i> INTERNAL TRANSFERS</h2>
                        </div>
                        
                        <div class="form-section">
                            <h2>Internal Transfer Records</h2>
                            <div class="form-info-banner">
                                <i class="fas fa-info-circle"></i>
                                <p>Records of internal transfers between staff members for this Chain of Custody</p>
                            </div>
                            
                            <?php if (!empty($transfers)): ?>
                                <div class="table-responsive">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Date & Time</th>
                                                <th>Purpose</th>
                                                <th>Released By</th>
                                                <th>Received By</th>
                                                <th>Signatures</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($transfers as $transfer): ?>
                                                <tr>
                                                    <td><?= date('Y-m-d H:i', strtotime($transfer['transfer_date'])) ?></td>
                                                    <td><?= htmlspecialchars($transfer['transfer_reason']) ?></td>
                                                    <td>
                                                        <?= htmlspecialchars($transfer['released_by_name']) ?>
                                                        <br>
                                                        <small><?= htmlspecialchars($transfer['released_by_position']) ?></small>
                                                    </td>
                                                    <td>
                                                        <?= htmlspecialchars($transfer['received_by_name']) ?>
                                                        <br>
                                                        <small><?= htmlspecialchars($transfer['received_by_position']) ?></small>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($transfer['releaser_signature']) && !empty($transfer['recipient_signature'])): ?>
                                                            <span class="badge badge-success"><i class="fas fa-check-circle"></i> Both signed</span>
                                                        <?php elseif (!empty($transfer['releaser_signature']) || !empty($transfer['recipient_signature'])): ?>
                                                            <span class="badge badge-warning"><i class="fas fa-exclamation-circle"></i> Partially signed</span>
                                                        <?php else: ?>
                                                            <span class="badge badge-danger"><i class="fas fa-times-circle"></i> Not signed</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <button type="button" class="btn-text view-transfer" data-id="<?= $transfer['id'] ?>">
                                                            <i class="fas fa-eye"></i> View
                                                        </button>
                                                        <button type="button" class="btn-text delete-transfer" data-id="<?= $transfer['id'] ?>">
                                                            <i class="fas fa-trash"></i> Delete
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-exchange-alt"></i>
                                    <p>No internal transfers have been recorded for this Chain of Custody.</p>
                                    <p>Use the "Add Internal Transfer" button at the top to record a transfer.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Return Status Section -->
                        <?php if (isReturned($custody_data)): ?>
                            <div class="form-section-header returned">
                                <h2><i class="fas fa-check-circle"></i> DEVICES RETURNED</h2>
                            </div>
                            
                            <div class="form-section">
                                <h2>Return Information</h2>
                                <div class="form-info-banner success">
                                    <i class="fas fa-check-circle"></i>
                                    <p>All devices have been returned to the client</p>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Returned On</label>
                                        <input type="text" value="<?= date('Y-m-d H:i:s', strtotime($custody_data['returned_at'])) ?>" readonly>
                                    </div>
                                    <div class="form-group">
                                        <label>Returned By</label>
                                        <input type="text" value="<?= htmlspecialchars($custody_data['returned_by'] ?? $current_user) ?>" readonly>
                                    </div>
                                </div>
                                
                                <?php if (!empty($custody_data['returned_notes'])): ?>
                                    <div class="form-row">
                                        <div class="form-group full-width">
                                            <label>Return Notes</label>
                                            <textarea readonly><?= htmlspecialchars($custody_data['returned_notes']) ?></textarea>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="form-buttons">
                            <a href="view-coc.php?id=<?= $custody_id ?>" class="cancel-button">Cancel</a>
                            <button type="submit" class="submit-button">Save Changes</button>
                        </div>
                    </form>
                </div>
                
                <!-- Delete Device Confirmation Modal -->
                <div id="deleteDeviceModal" class="modal">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2>Delete Device</h2>
                            <span class="close">&times;</span>
                        </div>
                        <div class="modal-body">
                            <p>Are you sure you want to delete this device from the Chain of Custody?</p>
                            <p class="warning-text">This action cannot be undone.</p>
                            <form id="deleteDeviceForm" action="delete-device.php" method="post">
                                <input type="hidden" name="device_id" id="delete_device_id">
                                <input type="hidden" name="custody_id" value="<?= $custody_id ?>">
                                <div class="form-actions">
                                    <button type="button" class="cancel-button" id="cancelDelete">Cancel</button>
                                    <button type="submit" class="danger-button">Delete Device</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Add Transfer Modal with Signatures -->
                <div id="addTransferModal" class="modal">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2>Add Internal Transfer</h2>
                            <span class="close close-transfer">&times;</span>
                        </div>
                        <div class="modal-body">
                            <p>Record an internal transfer between staff members for this Chain of Custody.</p>
                            
                            <form id="addTransferForm" action="edit-coc.php?id=<?= $custody_id ?>" method="post">
                                
														
								
								<input type="hidden" name="action" value="add_transfer">
                                <!-- CRITICAL: Hidden fields for signatures -->
                                <input type="hidden" name="transfer_releaser_signature" id="transfer_releaser_signature">
                                <input type="hidden" name="transfer_recipient_signature" id="transfer_recipient_signature">
                                <input type="hidden" id="current_user" value="<?= htmlspecialchars(CURRENT_USER) ?>">
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="transfer_date">Transfer Date & Time</label>
                                        <input type="datetime-local" id="transfer_date" name="transfer_date" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="transfer_reason">Purpose of Transfer</label>
                                        <input type="text" id="transfer_reason" name="transfer_reason" placeholder="e.g. Data Analysis, Device Imaging" required>
                                    </div>
                                </div>
                                
                                <div class="transfer-section">
                                    <h4><i class="fas fa-arrow-right"></i> Released By (Current Custodian)</h4>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="transfer_released_by_name">Full Name</label>
                                            <input type="text" id="transfer_released_by_name" name="transfer_released_by_name" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="transfer_released_by_position">Position</label>
                                            <input type="text" id="transfer_released_by_position" name="transfer_released_by_position" required>
                                        </div>
                                    </div>
                                    
                                    <!-- Releaser Signature Pad -->
                                    <div class="form-group full-width">
                                        <label for="transfer-releaser-signature-pad">Releaser Signature</label>
                                        <div class="signature-container">
                                            <canvas id="transfer-releaser-signature-pad" class="signature-pad"></canvas>
                                            <div class="signature-actions">
                                                <button type="button" id="clear-transfer-releaser-signature" class="btn-text">Clear</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="transfer-section">
                                    <h4><i class="fas fa-arrow-left"></i> Received By (New Custodian)</h4>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="transfer_received_by_name">Full Name</label>
                                            <input type="text" id="transfer_received_by_name" name="transfer_received_by_name" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="transfer_received_by_position">Position</label>
                                            <input type="text" id="transfer_received_by_position" name="transfer_received_by_position" required>
                                        </div>
                                    </div>
                                    
                                    <!-- Recipient Signature Pad -->
                                    <div class="form-group full-width">
                                        <label for="transfer-recipient-signature-pad">Recipient Signature</label>
                                        <div class="signature-container">
                                            <canvas id="transfer-recipient-signature-pad" class="signature-pad"></canvas>
                                            <div class="signature-actions">
                                                <button type="button" id="clear-transfer-recipient-signature" class="btn-text">Clear</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="form-actions">
                                    <button type="button" class="cancel-button cancel-transfer">Cancel</button>
                                    <button type="submit" class="submit-button" id="submitTransferBtn">Add Transfer</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- View Transfer Details Modal -->
                <div id="viewTransferModal" class="modal">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2>Transfer Details</h2>
                            <span class="close view-transfer-close">&times;</span>
                        </div>
                        <div class="modal-body" id="transferDetails">
                            <!-- Content will be populated via JavaScript -->
                        </div>
                    </div>
                </div>
                
                <!-- Mark as Returned Modal -->
                <div id="returnModal" class="modal">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2>Mark Devices as Returned</h2>
                            <span class="close close-return">&times;</span>
                        </div>
                        <div class="modal-body">
                            <form id="returnForm" method="post" action="edit-coc.php?id=<?= $custody_id ?>">
                                <input type="hidden" name="action" value="mark_returned">
                                
                                <p class="modal-message">
                                    You are about to mark all devices in this Chain of Custody as returned to the client. 
                                    This action will permanently update the custody status.
                                </p>
                                
                                <div class="form-group">
                                    <label for="return_notes">Return Notes (optional)</label>
                                    <textarea name="return_notes" id="return_notes" rows="4" placeholder="Enter any notes about the return process..."></textarea>
                                </div>
                                
                                <div class="form-actions">
                                    <button type="button" class="cancel-button cancel-return">Cancel</button>
                                    <button type="submit" class="submit-button">Confirm Return</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="content-card">
                    <div class="empty-state">
                        <i class="fas fa-exclamation-circle"></i>
                        <p>Chain of Custody record not found</p>
                        <a href="coc.php" class="action-button">Return to Chain of Custody List</a>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Mobile Menu Toggle Button -->
    <button id="mobileMenuToggle" class="mobile-menu-toggle">
        <i class="fas fa-bars"></i>
    </button>

</body>
</html>