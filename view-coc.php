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

define('CURRENT_TIMESTAMP', '2025-06-15 12:22:24');
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
$movements = []; // To store device movements

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    // Create device_movements table if it doesn't exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS device_movements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            custody_id INT NOT NULL,
            device_id INT NOT NULL,
            from_person VARCHAR(255) NOT NULL,
            to_person VARCHAR(255) NOT NULL,
            movement_date DATETIME NOT NULL,
            reason TEXT,
            signature_data LONGTEXT,
            created_by VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            notes TEXT,
            FOREIGN KEY (custody_id) REFERENCES custody_logs(id) ON DELETE CASCADE
        )
    ");

    // Check if needed columns exist in custody_logs table and add them if they don't
    $columns_check = [
        'returned_at' => 'ALTER TABLE custody_logs ADD COLUMN returned_at DATETIME NULL',
        'returned_by' => 'ALTER TABLE custody_logs ADD COLUMN returned_by VARCHAR(255) NULL',
        'returned_notes' => 'ALTER TABLE custody_logs ADD COLUMN returned_notes TEXT NULL',
        'return_status' => 'ALTER TABLE custody_logs ADD COLUMN return_status VARCHAR(50) NULL'
    ];

    foreach ($columns_check as $column => $alter_sql) {
        $column_exists = $pdo->query("SHOW COLUMNS FROM custody_logs LIKE '$column'")->rowCount() > 0;
        if (!$column_exists) {
            $pdo->exec($alter_sql);
        }
    }

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
        
        // Fetch device movements
        $stmt = $pdo->prepare("
            SELECT m.*, d.description as device_description, d.serial_number
            FROM device_movements m
            JOIN custody_devices d ON m.device_id = d.id
            WHERE m.custody_id = ?
            ORDER BY m.movement_date DESC
        ");
        $stmt->execute([$custody_id]);
        $movements = $stmt->fetchAll();
        
        // Check if any acquisition forms exist for these devices
        if (!empty($devices)) {
            $all_device_status = [];
            
            // First, check if the acquisition_forms table has a status column
            $table_check = $pdo->query("SHOW COLUMNS FROM acquisition_forms LIKE 'status'");
            $has_status_column = ($table_check->rowCount() > 0);
            
            // Check if evidence_number column exists in acquisition_forms table
            $evidence_check = $pdo->query("SHOW COLUMNS FROM acquisition_forms LIKE 'evidence_number'");
            $has_evidence_column = ($evidence_check->rowCount() > 0);
            
            // Check if evidence_number column exists in custody_devices table
            $device_evidence_check = $pdo->query("SHOW COLUMNS FROM custody_devices LIKE 'evidence_number'");
            $has_device_evidence_column = ($device_evidence_check->rowCount() > 0);
            
            foreach ($devices as &$device) {
                // Initialize acquisition_status with a default value
                $device['acquisition_status'] = 'pending';
                $device['has_acquisition_form'] = false;
                
                // Only try to get status if the column exists
                if ($has_status_column) {
                    $stmt = $pdo->prepare("
                        SELECT status 
                        FROM acquisition_forms 
                        WHERE case_id = ? AND serial_number = ?
                        ORDER BY id DESC LIMIT 1
                    ");
                    $stmt->execute([$custody_data['job_code'], $device['serial_number']]);
                    $status_result = $stmt->fetch();
                    
                    // Only update if we got a result
                    if ($status_result && isset($status_result['status'])) {
                        $device['acquisition_status'] = $status_result['status'];
                        $device['has_acquisition_form'] = true;
                    }
                }
                
                // Check for acquisition form by evidence_number if available
                if ($has_evidence_column && $has_device_evidence_column && !empty($device['evidence_number'])) {
                    $stmt = $pdo->prepare("
                        SELECT id 
                        FROM acquisition_forms 
                        WHERE evidence_number = ?
                        LIMIT 1
                    ");
                    $stmt->execute([$device['evidence_number']]);
                    if ($stmt->fetch()) {
                        $device['has_acquisition_form'] = true;
                    }
                }
                
                // Count device movements and check email domains
                $stmt = $pdo->prepare("
                    SELECT movement_date, from_person, to_person
                    FROM device_movements 
                    WHERE device_id = ? AND custody_id = ?
                    ORDER BY movement_date DESC
                ");
                $stmt->execute([$device['id'], $custody_id]);
                $device_movements = $stmt->fetchAll();
                $movement_count = count($device_movements);
                $device['has_movements'] = $movement_count > 0;
                $device['movement_count'] = $movement_count;
                
                // Check if any movement involves transfer between domains
                $device['has_cross_domain_movement'] = false;
                if ($movement_count > 0) {
                    foreach ($device_movements as $move) {
                        $from_email = filter_var($move['from_person'], FILTER_VALIDATE_EMAIL);
                        $to_email = filter_var($move['to_person'], FILTER_VALIDATE_EMAIL);
                        
                        if ($from_email && $to_email) {
                            $from_domain = explode('@', $from_email)[1];
                            $to_domain = explode('@', $to_email)[1];
                            
                            if ($from_domain !== $to_domain && 
                                ($from_domain === 'gint.africa' || $to_domain === 'gint.africa')) {
                                $device['has_cross_domain_movement'] = true;
                                break;
                            }
                        }
                    }
                }
                
                // If the Chain of Custody has been returned AND this device has 
                // an evidence number AND multiple movements or cross-domain movement, mark it as "delivered"
                if (isReturned($custody_data) && 
                    $has_device_evidence_column && 
                    !empty($device['evidence_number']) && 
                    ($movement_count > 1 || $device['has_cross_domain_movement'])) {
                    $device['acquisition_status'] = 'delivered';
                }
                // If it has been returned but doesn't meet criteria for "delivered"
                else if (isReturned($custody_data)) {
                    $device['acquisition_status'] = 'returned';
                }
                
                // Count devices by status
                if (!isset($all_device_status[$device['acquisition_status']])) {
                    $all_device_status[$device['acquisition_status']] = 0;
                }
                $all_device_status[$device['acquisition_status']]++;
            }
            
            $custody_data['device_status_summary'] = $all_device_status;
            
            // Check if movement documentation is complete for each device
            // For already returned CoCs, we consider the movement documentation complete regardless
            if (isReturned($custody_data)) {
                $custody_data['all_devices_have_movements'] = true;
            } else {
                $all_devices_have_movements = true;
                foreach ($devices as $device) {
                    if (!$device['has_movements']) {
                        $all_devices_have_movements = false;
                        break;
                    }
                }
                $custody_data['all_devices_have_movements'] = $all_devices_have_movements;
            }
        }
        
        // Check if PDF exists
        $pdf_path = "documents/coc/coc_" . $custody_data['job_code'] . ".pdf";
        $custody_data['pdf_exists'] = file_exists($pdf_path);
        $custody_data['pdf_path'] = $pdf_path;
    }
    
    // Handle PDF download
    if (isset($_GET['download']) && $_GET['download'] == 'pdf' && !empty($custody_data['pdf_path']) && file_exists($custody_data['pdf_path'])) {
        $file = $custody_data['pdf_path'];
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }
    
    // Handle print request
    if (isset($_GET['print']) && $_GET['print'] == 'pdf' && !empty($custody_data['pdf_path']) && file_exists($custody_data['pdf_path'])) {
        $file = $custody_data['pdf_path'];
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($file) . '"');
        readfile($file);
        exit;
    }
    
    // Handle "mark as returned" action
    if (isset($_POST['action']) && $_POST['action'] == 'mark_returned') {
        // Ignore movement check if already returned
        if (isReturned($custody_data)) {
            $error_message = "This Chain of Custody has already been marked as returned.";
        } else {
            // Check if all devices have movement records
            $all_devices_have_movements = true;
            if (!empty($devices)) {
                foreach ($devices as $device) {
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) as movement_count 
                        FROM device_movements 
                        WHERE device_id = ? AND custody_id = ?
                    ");
                    $stmt->execute([$device['id'], $custody_id]);
                    if ($stmt->fetch()['movement_count'] == 0) {
                        $all_devices_have_movements = false;
                        break;
                    }
                }
            }
            
            // If not all devices have movement records, show error
            if (!$all_devices_have_movements && count($devices) > 0) {
                $error_message = "Chain of Custody documentation is incomplete. Please record movement history for each device before marking as returned.";
            } else {
                $pdo->beginTransaction();
                
                try {
                    // First check if return_status column exists in custody_logs table
                    $return_status_exists = $pdo->query("SHOW COLUMNS FROM custody_logs LIKE 'return_status'")->rowCount() > 0;
                    $returned_at_exists = $pdo->query("SHOW COLUMNS FROM custody_logs LIKE 'returned_at'")->rowCount() > 0;
                    
                    // Get current timestamp for consistent usage
                    $current_time = date('Y-m-d H:i:s');
                    
                    // Build the SQL query dynamically based on which columns exist
                    $sql = "UPDATE custody_logs SET ";
                    $params = [];
                    
                    if ($return_status_exists) {
                        $sql .= "return_status = 'returned', ";
                    }
                    
                    if ($returned_at_exists) {
                        $sql .= "returned_at = ?, ";
                        $params[] = $current_time;
                    }
                    
                    // We already checked and added returned_by column if needed
                    $sql .= "returned_by = ?, ";
                    $params[] = $current_user;
                    
                    // We already checked and added returned_notes column if needed
                    $sql .= "returned_notes = ? ";
                    $params[] = $_POST['return_notes'] ?? '';
                    
                    $sql .= "WHERE id = ?";
                    $params[] = $custody_id;
                    
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    
                    // Update acquisition forms with appropriate status based on evidence number and movement count
                    if ($has_status_column) {
                        foreach ($devices as $device) {
                            // Get device movements
                            $stmt = $pdo->prepare("
                                SELECT movement_date, from_person, to_person
                                FROM device_movements 
                                WHERE device_id = ? AND custody_id = ?
                                ORDER BY movement_date DESC
                            ");
                            $stmt->execute([$device['id'], $custody_id]);
                            $device_movements = $stmt->fetchAll();
                            $movement_count = count($device_movements);
                            
                            // Check if any movement involves transfer between domains
                            $has_cross_domain_movement = false;
                            if ($movement_count > 0) {
                                foreach ($device_movements as $move) {
                                    $from_email = filter_var($move['from_person'], FILTER_VALIDATE_EMAIL);
                                    $to_email = filter_var($move['to_person'], FILTER_VALIDATE_EMAIL);
                                    
                                    if ($from_email && $to_email) {
                                        $from_domain = explode('@', $from_email)[1];
                                        $to_domain = explode('@', $to_email)[1];
                                        
                                        if ($from_domain !== $to_domain && 
                                            ($from_domain === 'gint.africa' || $to_domain === 'gint.africa')) {
                                            $has_cross_domain_movement = true;
                                            break;
                                        }
                                    }
                                }
                            }
                            
                            // Determine device status
                            $device_status = 'returned';
                            if ($has_device_evidence_column && 
                                !empty($device['evidence_number']) && 
                                ($movement_count > 1 || $has_cross_domain_movement)) {
                                $device_status = 'delivered';
                            }
                            
                            // Check if device has an acquisition form
                            $stmt = $pdo->prepare("
                                SELECT id FROM acquisition_forms 
                                WHERE case_id = ? AND serial_number = ?
                                ORDER BY id DESC LIMIT 1
                            ");
                            $stmt->execute([$custody_data['job_code'], $device['serial_number']]);
                            $form = $stmt->fetch();
                            
                            if ($form) {
                                // Update existing form status
                                $stmt = $pdo->prepare("
                                    UPDATE acquisition_forms
                                    SET status = ?
                                    WHERE id = ?
                                ");
                                $stmt->execute([$device_status, $form['id']]);
                            }
                        }
                    }
                    
                    // Log in audit trail if the table exists
                    $audit_table_exists = $pdo->query("SHOW TABLES LIKE 'audit_logs'")->rowCount() > 0;
                    if ($audit_table_exists) {
                        $stmt = $pdo->prepare("
                            INSERT INTO audit_logs (
                                user_id,
                                action,
                                target_table,
                                target_id,
                                timestamp,
                                details
                            ) VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $_SESSION['user_id'],
                            'Marked Chain of Custody as returned',
                            'custody_logs',
                            $custody_id,
                            $current_time,
                            'Chain of Custody marked as returned. Notes: ' . ($_POST['return_notes'] ?? 'None')
                        ]);
                    }
                    
                    $pdo->commit();
                    $success_message = "Chain of Custody has been successfully marked as returned.";
                    
                    // Refresh data
                    $stmt = $pdo->prepare("
                        SELECT cl.*, c.case_description
                        FROM custody_logs cl
                        LEFT JOIN cases c ON cl.job_code = c.case_number
                        WHERE cl.id = ?
                    ");
                    $stmt->execute([$custody_id]);
                    $custody_data = $stmt->fetch();
                    
                    // Set movement documentation as complete for returned CoC
                    $custody_data['all_devices_have_movements'] = true;
                    
                    // Refresh device movements data
                    $stmt = $pdo->prepare("
                        SELECT m.*, d.description as device_description, d.serial_number
                        FROM device_movements m
                        JOIN custody_devices d ON m.device_id = d.id
                        WHERE m.custody_id = ?
                        ORDER BY m.movement_date DESC
                    ");
                    $stmt->execute([$custody_id]);
                    $movements = $stmt->fetchAll();
                    
                    // Refresh devices data to show updated status
                    $stmt = $pdo->prepare("
                        SELECT * 
                        FROM custody_devices 
                        WHERE job_code = ?
                        ORDER BY item_number
                    ");
                    $stmt->execute([$custody_data['job_code']]);
                    $devices = $stmt->fetchAll();
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error_message = "Error processing return: " . $e->getMessage();
                }
            }
        }
    }
    
} catch (PDOException $e) {
    error_log('Database Error: ' . $e->getMessage());
    $error_message = "Database error: " . $e->getMessage();
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

// Determine if all devices have proper chain of custody records
// IMPORTANT FIX: If the CoC is already returned, we don't need to show the warning or disable the button
$can_mark_as_returned = !isReturned($custody_data) && 
                        !empty($devices) && 
                        isset($custody_data['all_devices_have_movements']) && 
                        $custody_data['all_devices_have_movements'];

// Only show the warning if the CoC is NOT returned
$show_movement_warning = !isReturned($custody_data) && 
                        !empty($devices) && 
                        isset($custody_data['all_devices_have_movements']) && 
                        !$custody_data['all_devices_have_movements'];
?>

<!DOCTYPE html>
<html>
<head>
    <title>View Chain of Custody - Project Management System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Signature Pad Library -->
    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.0.0/dist/signature_pad.umd.min.js"></script>
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
                <h1>Chain of Custody Details</h1>
                <nav class="breadcrumb">
                    <a href="dashboard.php">Dashboard</a> / 
                    <a href="coc.php">Chain of Custody</a> / 
                    <span>View Details</span>
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
                    <div class="document-actions">
                        <h2>Chain of Custody - <?= htmlspecialchars($custody_data['job_code']) ?></h2>
                        <div class="action-buttons">
                            <?php if (!empty($custody_data['pdf_exists']) && $custody_data['pdf_exists']): ?>
                                <a href="?id=<?= $custody_id ?>&download=pdf" class="action-button">
                                    <i class="fas fa-download"></i> Download PDF
                                </a>
                                <a href="?id=<?= $custody_id ?>&print=pdf" class="action-button" target="_blank">
                                    <i class="fas fa-print"></i> Print
                                </a>
                            <?php endif; ?>
                            <a href="edit-coc.php?id=<?= $custody_id ?>" class="action-button">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                            <?php if (!isReturned($custody_data)): ?>
                                <?php if ($can_mark_as_returned): ?>
                                    <button type="button" class="action-button primary" id="markReturnedBtn">
                                        <i class="fas fa-undo-alt"></i> Mark as Returned
                                    </button>
                                <?php else: ?>
                                    <span class="action-button disabled" title="Movement records must be documented for all devices before marking as returned">
                                        <i class="fas fa-undo-alt"></i> Mark as Returned
                                    </span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Add a warning message if there are devices without movement records -->
                    <!-- UPDATED: Only show warning if CoC is not already returned -->
                    <?php if ($show_movement_warning): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            <div>
                                <strong>Chain of Custody Documentation Incomplete</strong>
                                <p>Movement records must be documented for all devices before marking this Chain of Custody as returned. 
                                Use the "Record Movement" buttons for each device to document their chain of custody.</p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Status Banner -->
                    <?php if (isReturned($custody_data)): ?>
                        <div class="status-banner returned">
                            <i class="fas fa-check-circle"></i>
                            <div class="status-info">
                                <h3>Devices Returned</h3>
                                <p>All devices have been returned to the client on <?= date('F j, Y', strtotime($custody_data['returned_at'])) ?></p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="status-banner active">
                            <i class="fas fa-exclamation-circle"></i>
                            <div class="status-info">
                                <h3>Active Chain of Custody</h3>
                                <p>Devices are currently in custody since <?= date('F j, Y', strtotime($custody_data['created_at'])) ?></p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Case Details -->
                    <div class="detail-section">
                        <h3>Case Information</h3>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <span class="detail-label">Job Code</span>
                                <span class="detail-value"><?= htmlspecialchars($custody_data['job_code']) ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Created On</span>
                                <span class="detail-value"><?= date('F j, Y H:i:s', strtotime($custody_data['created_at'])) ?></span>
                            </div>
                            <div class="detail-item full-width">
                                <span class="detail-label">Case Description</span>
                                <span class="detail-value"><?= nl2br(htmlspecialchars($custody_data['case_description'] ?? 'No description available')) ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Released By (Client) -->
                    <div class="detail-section">
                        <h3>Released By (Client)</h3>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <span class="detail-label">Name</span>
                                <span class="detail-value"><?= htmlspecialchars($custody_data['released_by_name']) ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Position</span>
                                <span class="detail-value"><?= htmlspecialchars($custody_data['released_by_position']) ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Phone</span>
                                <span class="detail-value"><?= htmlspecialchars($custody_data['released_by_phone']) ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Date & Time</span>
                                <span class="detail-value"><?= date('F j, Y H:i:s', strtotime($custody_data['released_by_datetime'])) ?></span>
                            </div>
                            <div class="detail-item full-width">
                                <span class="detail-label">Purpose</span>
                                <span class="detail-value"><?= nl2br(htmlspecialchars($custody_data['released_reason'])) ?></span>
                            </div>
                            <?php if (!empty($custody_data['released_by_signature'])): ?>
                                <div class="detail-item full-width">
                                    <span class="detail-label">Signature</span>
                                    <div class="signature-image">
                                        <img src="<?= $custody_data['released_by_signature'] ?>" alt="Client Signature">
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Received By (Company) -->
                    <div class="detail-section">
                        <h3>Received By (Company Representative)</h3>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <span class="detail-label">Name</span>
                                <span class="detail-value"><?= htmlspecialchars($custody_data['received_by_name']) ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Position</span>
                                <span class="detail-value"><?= htmlspecialchars($custody_data['received_by_position']) ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Phone</span>
                                <span class="detail-value"><?= htmlspecialchars($custody_data['received_by_phone']) ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Date & Time</span>
                                <span class="detail-value"><?= date('F j, Y H:i:s', strtotime($custody_data['received_by_datetime'])) ?></span>
                            </div>
                            <div class="detail-item full-width">
                                <span class="detail-label">Purpose</span>
                                <span class="detail-value"><?= nl2br(htmlspecialchars($custody_data['received_reason'] ?? 'Digital Forensic Analysis')) ?></span>
                            </div>
                            <?php if (!empty($custody_data['received_by_signature'])): ?>
                                <div class="detail-item full-width">
                                    <span class="detail-label">Signature</span>
                                    <div class="signature-image">
                                        <img src="<?= $custody_data['received_by_signature'] ?>" alt="Company Representative Signature">
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Devices section -->
                    <div class="detail-section">
                        <div class="section-header-with-actions">
                            <h3>Devices</h3>
                            <div class="section-actions">
                                <a href="view-acquisition-form.php?job_code=<?= urlencode($custody_data['job_code']) ?>" class="btn-text">
                                    <i class="fas fa-laptop-medical"></i> View Acquisition Forms
                                </a>
                                <?php if (!empty($devices) && !isReturned($custody_data)): ?>
                                    <a href="custody_transfer_form.php?custody_id=<?= $custody_id ?>&job_code=<?= urlencode($custody_data['job_code']) ?>&move_all=1" class="btn-text">
                                        <i class="fas fa-exchange-alt"></i> Move All Devices
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if (!empty($devices)): ?>
                            <div class="table-responsive">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Description</th>
                                            <th>Serial Number</th>
                                            <?php if ($has_device_evidence_column): ?>
                                            <th>Evidence Number</th>
                                            <?php endif; ?>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($devices as $index => $device): ?>
                                            <tr>
                                                <td><?= $device['item_number'] ?></td>
                                                <td><?= htmlspecialchars($device['description']) ?></td>
                                                <td><?= htmlspecialchars($device['serial_number']) ?></td>
                                                <?php if ($has_device_evidence_column): ?>
                                                <td><?= htmlspecialchars($device['evidence_number'] ?? '') ?></td>
                                                <?php endif; ?>
                                                <td>
                                                    <span class="status-badge <?= htmlspecialchars($device['acquisition_status']) ?>">
                                                        <?= ucfirst(htmlspecialchars($device['acquisition_status'])) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($device['has_acquisition_form']): ?>
                                                        <!-- Updated link text for devices with acquisition forms -->
                                                        <a href="view-acquisition.php?id=<?= $device['id'] ?>" class="btn-text">
                                                            <i class="fas fa-clipboard-list"></i> View Acquisition Form
                                                        </a>
                                                    <?php else: ?>
                                                        <!-- Original link for devices without acquisition forms -->
                                                        <a href="acquisition-form.php?device_id=<?= $device['id'] ?>&job_code=<?= urlencode($custody_data['job_code']) ?>" class="btn-text">
                                                            <i class="fas fa-clipboard-list"></i> Acquisition Form
                                                        </a>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (!isReturned($custody_data)): ?>
                                                        <a href="custody_transfer_form.php?custody_id=<?= $custody_id ?>&job_code=<?= urlencode($custody_data['job_code']) ?>&device_id=<?= $device['id'] ?>&device_name=<?= urlencode($device['description']) ?>" class="btn-text">
                                                            <i class="fas fa-exchange-alt"></i> Record Movement
                                                        </a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-laptop"></i>
                                <p>No devices have been added to this Chain of Custody</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Device Movement History -->
                    <div class="detail-section">
                        <h3>Device Movement History</h3>
                        
                        <?php if (!empty($movements)): ?>
                            <div class="table-responsive">
                                <table class="data-table movements-table">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Device</th>
                                            <th>From</th>
                                            <th>To</th>
                                            <th>Reason</th>
                                            <th>Recorded By</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($movements as $movement): ?>
                                            <tr>
                                                <td><?= date('Y-m-d H:i', strtotime($movement['movement_date'])) ?></td>
                                                <td>
                                                    <strong><?= htmlspecialchars($movement['device_description']) ?></strong><br>
                                                    <small>SN: <?= htmlspecialchars($movement['serial_number']) ?></small>
                                                </td>
                                                <td><?= htmlspecialchars($movement['from_person']) ?></td>
                                                <td><?= htmlspecialchars($movement['to_person']) ?></td>
                                                <td><?= htmlspecialchars($movement['reason']) ?></td>
                                                <td><?= htmlspecialchars($movement['created_by']) ?></td>
                                                <td>
                                                    <button type="button" class="btn-text view-movement" data-movement-id="<?= $movement['id'] ?>" data-movement-signature="<?= htmlspecialchars($movement['signature_data'] ?? '') ?>">
                                                        <i class="fas fa-eye"></i> Details
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
                                <p>No movement history recorded for these devices</p>
                                <?php if (!isReturned($custody_data)): ?>
                                    <a href="custody_transfer_form.php?custody_id=<?= $custody_id ?>&job_code=<?= urlencode($custody_data['job_code']) ?>" class="action-button">
                                        <i class="fas fa-plus"></i> Record First Movement
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Internal Transfers -->
                    <div class="detail-section">
                        <div class="section-header-with-actions">
                            <h3>Device Transfers</h3>
                            <div class="section-actions">
                                <a href="edit-coc.php?id=<?= $custody_id ?>" class="btn-text">
                                    <i class="fas fa-edit"></i> Manage Transfers
                                </a>
                            </div>
                        </div>
                        
                        <?php
                        // Fetch internal transfers
                        $transfers = [];
                        try {
                            $stmt = $pdo->prepare("
                                SELECT * FROM custody_transfers
                                WHERE custody_id = ?
                                ORDER BY transfer_date DESC
                            ");
                            $stmt->execute([$custody_id]);
                            $transfers = $stmt->fetchAll();
                        } catch (PDOException $e) {
                            error_log('Error fetching transfers: ' . $e->getMessage());
                        }
                        ?>
                        
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
                                        <?php foreach($transfers as $transfer): ?>
                                            <tr>
                                                <td><?= date('Y-m-d H:i', strtotime($transfer['transfer_date'])) ?></td>
                                                <td><?= htmlspecialchars($transfer['transfer_reason']) ?></td>
                                                <td>
                                                    <?= htmlspecialchars($transfer['released_by_name']) ?>
                                                    <?php if (!empty($transfer['released_by_position'])): ?>
                                                        <br><small><?= htmlspecialchars($transfer['released_by_position']) ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?= htmlspecialchars($transfer['received_by_name']) ?>
                                                    <?php if (!empty($transfer['received_by_position'])): ?>
                                                        <br><small><?= htmlspecialchars($transfer['received_by_position']) ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($transfer['releaser_signature']) && !empty($transfer['recipient_signature'])): ?>
                                                        <span class="status-badge completed">Signed</span>
                                                    <?php else: ?>
                                                        <span class="status-badge pending">Not signed</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <button type="button" class="btn-text view-transfer" data-id="<?= $transfer['id'] ?>">
                                                        <i class="fas fa-eye"></i> View
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-file-signature"></i>
                                <p>No internal transfers recorded for this Chain of Custody</p>
                                <?php if (!isReturned($custody_data)): ?>
                                    <a href="custody_transfer_form.php?custody_id=<?= $custody_id ?>&job_code=<?= urlencode($custody_data['job_code']) ?>" class="action-button">
                                        <i class="fas fa-plus"></i> Add Internal Transfer
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Return Information (if available) -->
                    <?php if (isReturned($custody_data)): ?>
                        <div class="detail-section">
                            <h3>Return Information</h3>
                            <div class="detail-grid">
                                <div class="detail-item">
                                    <span class="detail-label">Returned On</span>
                                    <span class="detail-value"><?= date('F j, Y H:i:s', strtotime($custody_data['returned_at'])) ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Returned By</span>
                                    <span class="detail-value"><?= htmlspecialchars($custody_data['returned_by'] ?? $current_user) ?></span>
                                </div>
                                <?php if(!empty($custody_data['returned_notes'])): ?>
                                    <div class="detail-item full-width">
                                        <span class="detail-label">Notes</span>
                                        <span class="detail-value"><?= nl2br(htmlspecialchars($custody_data['returned_notes'])) ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Mark as Returned Modal -->
                <div id="returnModal" class="modal">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2>Mark Chain of Custody as Returned</h2>
                            <span class="close">&times;</span>
                        </div>
                        <div class="modal-body">
                            <form id="returnForm" method="post" action="view-coc.php?id=<?= $custody_id ?>">
                                <input type="hidden" name="action" value="mark_returned">
                                
                                <p class="modal-message">
                                    You are about to mark this Chain of Custody as returned. This indicates that all documented 
                                    device movements have been completed and the chain of custody is now closed.
                                </p>
                                
                                <div class="form-group">
                                    <label for="return_notes">Return Notes (optional)</label>
                                    <textarea name="return_notes" id="return_notes" rows="4" placeholder="Enter any notes about the return process..."></textarea>
                                </div>
                                
                                <div class="form-actions">
                                    <button type="button" class="cancel-button" id="closeModal">Cancel</button>
                                    <button type="submit" class="submit-button">Confirm Return</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- View Movement Details Modal -->
                <div id="viewMovementModal" class="modal">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2>Movement Details</h2>
                            <span class="close view-movement-close">&times;</span>
                        </div>
                        <div class="modal-body" id="movementDetails">
                            <!-- Content will be populated via JavaScript -->
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

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }

        body {
            background-color: #f5f6fa;
            color: #2d3436;
            padding-top: 60px;
        }

        .top-bar {
            background-color: #fff;
            color: #000;
            padding: 10px 30px;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .system-info {
            display: flex;
            justify-content: space-between;
            font-size: 0.85em;
        }

        .layout {
            display: flex;
            min-height: calc(100vh - 60px);
        }

        .sidebar {
            width: 280px;
            background: white;
            padding: 20px;
            border-right: 1px solid #e9ecef;
            position: fixed;
            height: calc(100vh - 60px);
            overflow-y: auto;
            z-index: 990;
            transition: transform 0.3s ease;
        }

        .main-content {
            flex: 1;
            padding: 20px;
            margin-left: 280px;
            transition: margin-left 0.3s ease;
        }

        .sidebar-header {
            padding: 20px 0;
            border-bottom: 1px solid #e9ecef;
            margin-bottom: 20px;
            text-align: center;
        }

        .logo {
            max-width: 120px;
            margin-bottom: 10px;
        }

        .menu-section {
            margin-bottom: 25px;
        }

        .menu-section h3 {
            font-size: 0.9em;
            color: #636e72;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 10px;
        }

        .menu-section ul {
            list-style: none;
        }

        .menu-section ul li {
            margin-bottom: 2px;
        }

        .menu-section ul li a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            color: #2d3436;
            text-decoration: none;
            border-radius: 4px;
            transition: all 0.3s ease;
        }

        .menu-section ul li a:hover {
            background: #f5f6fa;
            transform: translateX(5px);
        }

        .menu-section ul li.active a {
            background: #e9ecef;
            color: #2d3436;
            font-weight: 500;
        }

        .sidebar-footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
        }

        .logout-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            color: #e74c3c;
            text-decoration: none;
            border-radius: 4px;
            transition: all 0.3s ease;
        }

        .logout-btn:hover {
            background: #fff3f3;
        }

        .page-header {
            margin-bottom: 25px;
        }

        .page-header h1 {
            font-size: 1.75rem;
            margin-bottom: 10px;
            color: #2d3436;
        }

        .breadcrumb {
            font-size: 0.9em;
            color: #636e72;
        }

        .breadcrumb a {
            color: #636e72;
            text-decoration: none;
        }

        .breadcrumb a:hover {
            color: #2d3436;
            text-decoration: underline;
        }

        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-danger {
            background-color: #ffe3e3;
            color: #e03131;
        }

        .alert-success {
            background-color: #d3f9d8;
            color: #2b8a3e;
        }

        .alert i {
            font-size: 18px;
        }
        
        .alert-warning {
            background-color: #fff3bf;
            color: #e67700;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            display: flex;
            align-items: flex-start;
            gap: 15px;
        }
        
        .alert-warning i {
            font-size: 24px;
            margin-top: 2px;
        }
        
        .alert-warning strong {
            display: block;
            margin-bottom: 5px;
        }
        
        .alert-warning p {
            margin: 0;
            font-size: 0.9em;
            line-height: 1.4;
        }

        .content-card {
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .document-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .document-actions h2 {
            font-size: 1.5rem;
            color: #2d3436;
            margin: 0;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .action-button {
            padding: 8px 16px;
            background: #e9ecef;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9em;
            font-weight: 500;
            color: #495057;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .action-button:hover {
            background: #dee2e6;
            transform: translateY(-2px);
        }

        .action-button.primary {
            background: #007bff;
            color: #fff;
        }

        .action-button.primary:hover {
            background: #0069d9;
        }
        
        .action-button.disabled {
            background-color: #e9ecef;
            color: #adb5bd;
            cursor: not-allowed;
        }
        
        .action-button.disabled:hover {
            background-color: #e9ecef;
            transform: none;
        }

        .status-banner {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px 20px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .status-banner.active {
            background-color: #e3f2fd;
            color: #0288d1;
        }

        .status-banner.returned {
            background-color: #d3f9d8;
            color: #2b8a3e;
        }

        .status-banner i {
            font-size: 24px;
        }

        .status-info h3 {
            font-size: 1rem;
            margin-bottom: 5px;
        }

        .status-info p {
            margin: 0;
            font-size: 0.9rem;
        }

        .detail-section {
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e9ecef;
        }

        .detail-section:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }

        .detail-section h3 {
            font-size: 1.1rem;
            color: #2d3436;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e9ecef;
        }

        .section-header-with-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e9ecef;
        }

        .section-header-with-actions h3 {
            font-size: 1.1rem;
            color: #2d3436;
            margin: 0;
            padding: 0;
            border: none;
        }

        .section-actions {
            display: flex;
            gap: 10px;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .detail-item.full-width {
            grid-column: 1 / -1;
        }

        .detail-label {
            font-size: 0.8em;
            color: #868e96;
            font-weight: 500;
        }

        .detail-value {
            font-size: 0.95em;
            color: #2d3436;
        }

        .signature-image {
            max-width: 250px;
            border: 1px solid #e9ecef;
            border-radius: 4px;
            overflow: hidden;
            padding: 10px;
            background: #f8f9fa;
        }

        .signature-image img {
            width: 100%;
            height: auto;
            display: block;
        }

        .table-responsive {
            overflow-x: auto;
            margin-bottom: 20px;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th,
        .data-table td {
            padding: 12px 15px;
            text-align: left;
        }

        .data-table th {
            background: #f8f9fa;
            font-weight: 500;
            font-size: 0.9em;
            color: #495057;
        }

        .data-table tbody tr {
            border-bottom: 1px solid #e9ecef;
        }

        .data-table tbody tr:last-child {
            border-bottom: none;
        }

        .data-table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: 500;
        }

        .status-badge.pending {
            background-color: #fff3bf;
            color: #e67700;
        }

        .status-badge.completed {
            background-color: #d3f9d8;
            color: #2b8a3e;
        }
        
        .status-badge.returned {
            background-color: #e3f2fd;
            color: #1971c2;
        }
        
        .status-badge.delivered {
            background-color: #e3fafc;
            color: #0c8599;
        }

        .status-badge.in_progress {
            background-color: #e3f2fd;
            color: #1971c2;
        }

        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 15px;
            padding: 40px 20px;
            text-align: center;
        }

        .empty-state i {
            font-size: 48px;
            color: #adb5bd;
        }

        .empty-state p {
            color: #868e96;
        }

        .btn-text {
            background: none;
            border: none;
            color: #1098ad;
            cursor: pointer;
            padding: 5px 8px;
            font-size: 0.9em;
            border-radius: 4px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .btn-text:hover {
            background-color: #e3f2fd;
            color: #0c8599;
        }

        .mobile-menu-toggle {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: #007bff;
            border: none;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
            cursor: pointer;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
            z-index: 1000;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1050;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
        }

        .modal-content {
            position: relative;
            background-color: #fff;
            margin: 10% auto;
            padding: 0;
            border-radius: 8px;
            width: 500px;
            max-width: 90%;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            animation: modalFadeIn 0.3s;
        }

        @keyframes modalFadeIn {
            from {transform: translateY(-30px); opacity: 0;}
            to {transform: translateY(0); opacity: 1;}
        }

        .modal-header {
            padding: 15px 20px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .modal-header h2 {
            margin: 0;
            font-size: 1.25rem;
            color: #2d3436;
        }

        .close {
            color: #adb5bd;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
        }

        .close:hover {
            color: #495057;
        }

        .modal-body {
            padding: 20px;
            max-height: 70vh;
            overflow-y: auto;
        }

        .modal-message {
            margin-bottom: 20px;
            color: #495057;
            line-height: 1.5;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-row {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
        }

        .form-row .form-group {
            flex: 1;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 0.9em;
            font-weight: 500;
            color: #495057;
        }

        .form-group input[type="text"],
        .form-group input[type="datetime-local"],
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #e9ecef;
            border-radius: 4px;
            font-size: 14px;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }

        .cancel-button {
            padding: 10px 20px;
            background: #e9ecef;
            border: none;
            border-radius: 4px;
            color: #495057;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .cancel-button:hover {
            background: #dee2e6;
        }

        .submit-button {
            padding: 10px 20px;
            background: #007bff;
            border: none;
            border-radius: 4px;
            color: white;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .submit-button:hover {
            background: #0069d9;
        }

        /* Movement details */
        .movement-detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }

        .movement-detail-item {
            margin-bottom: 15px;
        }

        .movement-detail-label {
            font-weight: 500;
            margin-bottom: 5px;
            color: #495057;
        }

        .movement-detail-value {
            color: #2d3436;
        }

        .movement-signature {
            margin-top: 20px;
            border-top: 1px solid #e9ecef;
            padding-top: 15px;
        }

        .movement-signature img {
            max-width: 200px;
            border: 1px solid #e9ecef;
            border-radius: 4px;
            padding: 5px;
        }

        /* Responsive styles */
        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .main-content {
                margin-left: 0;
            }

            .mobile-menu-toggle {
                display: flex;
            }

            .sidebar.active {
                transform: translateX(0);
            }
        }

        @media (max-width: 768px) {
            .document-actions {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .action-buttons {
                width: 100%;
            }
            
            .detail-grid {
                grid-template-columns: 1fr;
            }
            
            .system-info {
                flex-direction: column;
                align-items: center;
                gap: 5px;
            }
            
            .form-row {
                flex-direction: column;
                gap: 0;
            }
            
            .movement-detail-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .top-bar {
                padding: 10px 15px;
            }
            
            .container {
                padding: 0 10px;
            }
            
            .main-content {
                padding: 15px 10px;
            }
            
            .content-card {
                padding: 15px;
            }
            
            .data-table th,
            .data-table td {
                padding: 8px;
            }
            
            .btn-text {
                padding: 4px;
            }
            
            .action-button {
                padding: 6px 12px;
                font-size: 0.85em;
            }
            
            .modal-content {
                width: 95%;
                margin: 5% auto;
            }
        }
    </style>
    
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
            
           	// Return Modal functionality
            const returnModal = document.getElementById('returnModal');
            const markReturnedBtn = document.getElementById('markReturnedBtn');
            const closeReturnModal = document.getElementById('closeModal');
            const closeReturnSpan = document.querySelector('.close');
            
            if (markReturnedBtn) {
                markReturnedBtn.addEventListener('click', function() {
                    if (returnModal) returnModal.style.display = 'block';
                });
            }
            
            if (closeReturnSpan) {
                closeReturnSpan.addEventListener('click', function() {
                    if (returnModal) returnModal.style.display = 'none';
                });
            }
            
            if (closeReturnModal) {
                closeReturnModal.addEventListener('click', function() {
                    if (returnModal) returnModal.style.display = 'none';
                });
            }
            
            // View Movement Details Modal
            const viewMovementModal = document.getElementById('viewMovementModal');
            const viewMovementButtons = document.querySelectorAll('.view-movement');
            const closeViewMovementSpan = document.querySelector('.view-movement-close');
            
            if (viewMovementButtons.length > 0) {
                viewMovementButtons.forEach(function(button) {
                    button.addEventListener('click', function() {
                        const movementId = this.getAttribute('data-movement-id');
                        const signatureData = this.getAttribute('data-movement-signature') || '';
                        
                        // Get the movement data from PHP-generated array
                        const movementsData = <?= json_encode($movements) ?>;
                        const movement = movementsData.find(m => m.id == movementId) || {};
                        
                        populateMovementDetails(movement, signatureData);
                        viewMovementModal.style.display = 'block';
                    });
                });
            }
            
            if (closeViewMovementSpan) {
                closeViewMovementSpan.addEventListener('click', function() {
                    viewMovementModal.style.display = 'none';
                });
            }
            
            // Close modals if clicking outside
            window.addEventListener('click', function(event) {
                if (event.target === returnModal) {
                    returnModal.style.display = 'none';
                }
                if (event.target === viewMovementModal) {
                    viewMovementModal.style.display = 'none';
                }
            });
            
            // Helper function to populate movement details modal
            function populateMovementDetails(movement, signatureData) {
                const detailsContainer = document.getElementById('movementDetails');
                let html = `
                    <div class="movement-detail-grid">
                        <div class="movement-detail-item">
                            <div class="movement-detail-label">Device</div>
                            <div class="movement-detail-value">${movement.device_description || 'N/A'}</div>
                        </div>
                        <div class="movement-detail-item">
                            <div class="movement-detail-label">Serial Number</div>
                            <div class="movement-detail-value">${movement.serial_number || 'N/A'}</div>
                        </div>
                        <div class="movement-detail-item">
                            <div class="movement-detail-label">Date & Time</div>
                            <div class="movement-detail-value">${formatDate(movement.movement_date) || 'N/A'}</div>
                        </div>
                        <div class="movement-detail-item">
                            <div class="movement-detail-label">Recorded By</div>
                            <div class="movement-detail-value">${movement.created_by || 'N/A'}</div>
                        </div>
                        <div class="movement-detail-item">
                            <div class="movement-detail-label">From Person</div>
                            <div class="movement-detail-value">${movement.from_person || 'N/A'}</div>
                        </div>
                        <div class="movement-detail-item">
                            <div class="movement-detail-label">To Person</div>
                            <div class="movement-detail-value">${movement.to_person || 'N/A'}</div>
                        </div>
                    </div>
                    
                    <div class="movement-detail-item full-width">
                        <div class="movement-detail-label">Reason for Movement</div>
                        <div class="movement-detail-value">${movement.reason || 'N/A'}</div>
                    </div>
                    
                    ${movement.notes ? `
                    <div class="movement-detail-item full-width">
                        <div class="movement-detail-label">Notes</div>
                        <div class="movement-detail-value">${movement.notes}</div>
                    </div>` : ''}
                `;
                
                // Add signature if available
                if (movement.signature_data || signatureData) {
                    const sigData = movement.signature_data || signatureData;
                    html += `
                        <div class="movement-signature">
                            <div class="movement-detail-label">Recipient Signature</div>
                            <img src="${sigData}" alt="Signature">
                        </div>
                    `;
                }
                
                html += `
                    <div class="form-actions">
                        <button type="button" class="action-button" onclick="document.querySelector('.view-movement-close').click()">
                            Close
                        </button>
                    </div>
                `;
                
                detailsContainer.innerHTML = html;
            }
            
            // Helper function to format dates nicely
            function formatDate(dateString) {
                if (!dateString) return '';
                
                const date = new Date(dateString);
                if (isNaN(date)) return dateString;
                
                const options = { 
                    year: 'numeric', 
                    month: 'long', 
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                };
                
                return date.toLocaleDateString(undefined, options);
            }
            
            // Handle viewing transfer details
            const viewTransferButtons = document.querySelectorAll('.view-transfer');
            
            if (viewTransferButtons.length > 0) {
                viewTransferButtons.forEach(function(button) {
                    button.addEventListener('click', function() {
                        const transferId = this.getAttribute('data-id');
                        // Redirect to transfer details page or show modal
                        window.location.href = `view-transfer.php?id=${transferId}&custody_id=<?= $custody_id ?>`;
                    });
                });
            }
        });
    </script>
</body>
</html>