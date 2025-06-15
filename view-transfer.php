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

define('CURRENT_TIMESTAMP', '2025-06-14 14:42:14');
define('CURRENT_USER', 'nkosinathil');

include_once('db-connection.php');

$error_message = '';
$success_message = '';

// Get transfer ID and custody ID from URL parameter
$transfer_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$custody_id = isset($_GET['custody_id']) ? (int)$_GET['custody_id'] : 0;

// Check if IDs are valid
if ($transfer_id <= 0 || $custody_id <= 0) {
    $error_message = "Invalid transfer or custody ID.";
}

$transfer_data = [];
$custody_data = [];
$devices = []; // Array to hold devices associated with this transfer

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    // Check if transfer_devices table exists
    $tableExists = false;
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'transfer_devices'");
        $tableExists = $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        // Table doesn't exist
    }
    
    // Create the table if it doesn't exist
    if (!$tableExists) {
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
    }
    
    // Fetch transfer data
    $stmt = $pdo->prepare("
        SELECT * FROM custody_transfers 
        WHERE id = ? AND custody_id = ?
    ");
    $stmt->execute([$transfer_id, $custody_id]);
    $transfer_data = $stmt->fetch();
    
    if (!$transfer_data) {
        $error_message = "Transfer record not found or does not belong to the specified custody record.";
    } else {
        // Fetch custody data
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
            // Try to get devices from transfer_devices table first
            if ($tableExists) {
                $stmt = $pdo->prepare("
                    SELECT d.*
                    FROM custody_devices d
                    JOIN transfer_devices td ON d.id = td.device_id
                    WHERE td.transfer_id = ?
                    ORDER BY d.item_number
                ");
                $stmt->execute([$transfer_id]);
                $devices = $stmt->fetchAll();
            }
            
            // If no devices found in transfer_devices table, get all devices for this job code
            // This is a fallback for transfers created before the transfer_devices table existed
            if (empty($devices)) {
                $stmt = $pdo->prepare("
                    SELECT * FROM custody_devices 
                    WHERE job_code = ? 
                    ORDER BY item_number
                ");
                $stmt->execute([$transfer_data['job_code']]);
                $devices = $stmt->fetchAll();
            }
            
            // Check which devices have acquisition forms
            // First check if evidence_number column exists in both tables
            $hasEvidenceColumn = false;
            $stmt = $pdo->query("SHOW COLUMNS FROM acquisition_forms LIKE 'evidence_number'");
            $hasEvidenceColumn = ($stmt->rowCount() > 0);
            
            $hasDeviceEvidenceColumn = false;
            $stmt = $pdo->query("SHOW COLUMNS FROM custody_devices LIKE 'evidence_number'");
            $hasDeviceEvidenceColumn = ($stmt->rowCount() > 0);
            
            foreach ($devices as &$device) {
                $device['has_acquisition_form'] = false;
                
                // Check by case_id and serial_number (always available method)
                $stmt = $pdo->prepare("
                    SELECT id 
                    FROM acquisition_forms 
                    WHERE case_id = ? AND serial_number = ? 
                    LIMIT 1
                ");
                $stmt->execute([$transfer_data['job_code'], $device['serial_number']]);
                if ($stmt->fetch()) {
                    $device['has_acquisition_form'] = true;
                } 
                // If not found, check by evidence_number if columns exist
                else if ($hasEvidenceColumn && $hasDeviceEvidenceColumn && !empty($device['evidence_number'])) {
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
?>

<!DOCTYPE html>
<html>
<head>
    <title>View Transfer Details - Project Management System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
                <h1>Transfer Details</h1>
                <nav class="breadcrumb">
                    <a href="dashboard.php">Dashboard</a> / 
                    <a href="coc.php">Chain of Custody</a> / 
                    <a href="view-coc.php?id=<?= $custody_id ?>">View Custody</a> / 
                    <span>Transfer Details</span>
                </nav>
            </div>

            <?php if ($error_message): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?= $error_message ?>
                    <div class="mt-3">
                        <a href="view-coc.php?id=<?= $custody_id ?>" class="action-button">Return to Chain of Custody</a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($transfer_data) && !empty($custody_data)): ?>
                <div class="content-card">
                    <div class="document-actions">
                        <h2>Transfer #<?= $transfer_id ?> - <?= htmlspecialchars($custody_data['job_code']) ?></h2>
                        <div class="action-buttons">
                            <a href="view-coc.php?id=<?= $custody_id ?>" class="action-button">
                                <i class="fas fa-arrow-left"></i> Back to Custody
                            </a>
                            <?php if (!isReturned($custody_data)): ?>
                                <a href="custody_transfer_form.php?custody_id=<?= $custody_id ?>&edit_transfer=<?= $transfer_id ?>" class="action-button">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                <button type="button" class="action-button danger" id="deleteTransferBtn" data-id="<?= $transfer_id ?>">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="detail-section">
                        <h3>Transfer Information</h3>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <span class="detail-label">Date & Time</span>
                                <span class="detail-value"><?= date('F j, Y H:i:s', strtotime($transfer_data['transfer_date'])) ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Job Code</span>
                                <span class="detail-value"><?= htmlspecialchars($transfer_data['job_code']) ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Created</span>
                                <span class="detail-value"><?= date('F j, Y H:i:s', strtotime($transfer_data['created_at'])) ?></span>
                            </div>
                            <div class="detail-item full-width">
                                <span class="detail-label">Purpose</span>
                                <span class="detail-value"><?= nl2br(htmlspecialchars($transfer_data['transfer_reason'])) ?></span>
                            </div>
                            <?php if (isset($transfer_data['notes']) && !empty($transfer_data['notes'])): ?>
                            <div class="detail-item full-width">
                                <span class="detail-label">Notes</span>
                                <span class="detail-value"><?= nl2br(htmlspecialchars($transfer_data['notes'])) ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Devices Section with Dynamic Action Text -->
                    <div class="detail-section">
                        <h3>Devices</h3>
                        <?php if (!empty($devices)): ?>
                            <div class="table-responsive">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Description</th>
                                            <th>Serial Number</th>
                                            <?php if ($hasDeviceEvidenceColumn): ?>
                                            <th>Evidence Number</th>
                                            <?php endif; ?>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($devices as $device): ?>
                                            <tr>
                                                <td><?= $device['item_number'] ?></td>
                                                <td><?= htmlspecialchars($device['description']) ?></td>
                                                <td><?= htmlspecialchars($device['serial_number']) ?></td>
                                                <?php if ($hasDeviceEvidenceColumn): ?>
                                                <td><?= htmlspecialchars($device['evidence_number'] ?? '') ?></td>
                                                <?php endif; ?>
                                                <td>
                                                    <?php if ($device['has_acquisition_form']): ?>
                                                        <!-- Show "View Acquisition" for devices that have forms -->
                                                        <a href="view-acquisition.php?id=<?= $device['id'] ?>" class="btn-text">
                                                            <i class="fas fa-clipboard-list"></i> View Acquisition
                                                        </a>
                                                    <?php else: ?>
                                                        <!-- Show "Create Acquisition" for devices without forms -->
                                                        <a href="acquisition-form.php?device_id=<?= $device['id'] ?>&job_code=<?= urlencode($transfer_data['job_code']) ?>" class="btn-text">
                                                            <i class="fas fa-plus"></i> Create Acquisition
                                                        </a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="device-count mt-3">
                                <span class="badge"><?= count($devices) ?> device(s) in this transfer</span>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-laptop"></i>
                                <p>No devices found for this transfer</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="detail-section">
                        <h3>Released By (Current Custodian)</h3>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <span class="detail-label">Name</span>
                                <span class="detail-value"><?= htmlspecialchars($transfer_data['released_by_name']) ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Position</span>
                                <span class="detail-value"><?= htmlspecialchars($transfer_data['released_by_position']) ?></span>
                            </div>
                            <?php if (!empty($transfer_data['releaser_signature'])): ?>
                                <div class="detail-item full-width">
                                    <span class="detail-label">Signature</span>
                                    <div class="signature-image">
                                        <img src="<?= $transfer_data['releaser_signature'] ?>" alt="Releaser Signature">
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="detail-section">
                        <h3>Received By (New Custodian)</h3>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <span class="detail-label">Name</span>
                                <span class="detail-value"><?= htmlspecialchars($transfer_data['received_by_name']) ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Position</span>
                                <span class="detail-value"><?= htmlspecialchars($transfer_data['received_by_position']) ?></span>
                            </div>
                            <?php if (!empty($transfer_data['recipient_signature'])): ?>
                                <div class="detail-item full-width">
                                    <span class="detail-label">Signature</span>
                                    <div class="signature-image">
                                        <img src="<?= $transfer_data['recipient_signature'] ?>" alt="Recipient Signature">
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Delete Confirmation Modal -->
                <div id="deleteModal" class="modal">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2>Delete Transfer</h2>
                            <span class="close">&times;</span>
                        </div>
                        <div class="modal-body">
                            <p class="modal-message">
                                Are you sure you want to delete this transfer record? This action cannot be undone.
                            </p>
                            
                            <div class="form-actions">
                                <button type="button" class="cancel-button" id="cancelDelete">Cancel</button>
                                <a href="delete-transfer.php?id=<?= $transfer_id ?>&custody_id=<?= $custody_id ?>&confirm=yes" class="danger-button">Delete Transfer</a>
                            </div>
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
            flex-direction: column;
            align-items: flex-start;
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

        .action-button.danger {
            background: #ffe3e3;
            color: #e03131;
        }

        .action-button.danger:hover {
            background: #ffc9c9;
        }

        .danger-button {
            padding: 10px 20px;
            background: #e03131;
            border: none;
            border-radius: 4px;
            color: white;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s ease;
        }

        .danger-button:hover {
            background: #c92a2a;
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
        
        /* Different styling for View vs Create buttons */
        .btn-text i.fa-plus {
            color: #2b8a3e;
        }
        
        .btn-text:has(i.fa-plus):hover {
            background-color: #d3f9d8;
            color: #2b8a3e;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 12px;
            background-color: #e9ecef;
            color: #495057;
            border-radius: 16px;
            font-size: 0.85em;
            font-weight: 500;
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
            color: #e03131;
            line-height: 1.5;
            font-weight: 500;
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
        
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 15px;
            padding: 30px;
            text-align: center;
            color: #868e96;
        }
        
        .empty-state i {
            font-size: 48px;
            color: #adb5bd;
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
        }

        .mt-3 {
            margin-top: 15px;
        }
        
        .device-count {
            margin-top: 10px;
            display: flex;
            justify-content: flex-end;
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
            
            // Delete modal functionality
            const deleteModal = document.getElementById('deleteModal');
            const deleteBtn = document.getElementById('deleteTransferBtn');
            const cancelDeleteBtn = document.getElementById('cancelDelete');
            const closeSpan = document.querySelector('.close');
            
            if (deleteBtn) {
                deleteBtn.addEventListener('click', function() {
                    if (deleteModal) deleteModal.style.display = 'block';
                });
            }
            
            if (closeSpan) {
                closeSpan.addEventListener('click', function() {
                    if (deleteModal) deleteModal.style.display = 'none';
                });
            }
            
            if (cancelDeleteBtn) {
                cancelDeleteBtn.addEventListener('click', function() {
                    if (deleteModal) deleteModal.style.display = 'none';
                });
            }
            
            // Close modal if clicking outside
            window.addEventListener('click', function(event) {
                if (event.target === deleteModal) {
                    deleteModal.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>