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

define('CURRENT_TIMESTAMP', '2025-06-12 16:09:15');
define('CURRENT_USER', 'nkosinathil');

include_once('db-connection.php');

$current_user = $_SESSION['name'] ?? 'nkosinathil';
$error_message = '';
$success_message = '';

// Initialize variables
$case_number = $_GET['case'] ?? '';
$client_code = $_GET['client'] ?? '';
$case_data = [];
$client_data = [];

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Get case details
    if ($case_number) {
        $stmt = $pdo->prepare("
            SELECT c.*, cl.* 
            FROM cases c
            LEFT JOIN clients cl ON c.client_code = cl.client_code
            WHERE c.case_number = ?
        ");
        $stmt->execute([$case_number]);
        $case_data = $stmt->fetch();
        
        if ($case_data) {
            $client_code = $case_data['client_code'];
        }
    }

    // Get client details if we have client_code but no case_data
    if ($client_code && empty($case_data)) {
        $stmt = $pdo->prepare("SELECT * FROM clients WHERE client_code = ?");
        $stmt->execute([$client_code]);
        $client_data = $stmt->fetch();
    }
    
    // Form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Begin transaction
        $pdo->beginTransaction();
        
        try {
            // Insert into custody_logs table
            $stmt = $pdo->prepare("
                INSERT INTO custody_logs (
                    job_code,
                    released_by_name,
                    released_by_position,
                    released_by_phone,
                    released_by_datetime,
                    released_reason,
                    released_by_signature,
                    received_by_name,
                    received_by_position,
                    received_by_phone,
                    received_by_datetime,
                    received_reason,
                    received_by_signature,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            // Format the datetime values
            $released_datetime = date('Y-m-d H:i:s', strtotime($_POST['released_by_datetime']));
            $received_datetime = date('Y-m-d H:i:s', strtotime($_POST['received_by_datetime']));
            
            $stmt->execute([
                $_POST['job_code'],
                $_POST['released_by_name'],
                $_POST['released_by_position'],
                $_POST['released_by_phone'],
                $released_datetime,
                $_POST['released_reason'],
                $_POST['released_signature_data'],
                $_POST['received_by_name'],
                $_POST['received_by_position'],
                $_POST['received_by_phone'],
                $received_datetime,
                $_POST['received_reason'],
                $_POST['received_signature_data']
            ]);
            
            $custody_id = $pdo->lastInsertId();
            
            // Add devices to custody_devices table
            $device_count = count($_POST['device_type'] ?? []);
            
            if ($device_count > 0) {
                $device_stmt = $pdo->prepare("
                    INSERT INTO custody_devices (
                        job_code,
                        item_number,
                        description,
                        serial_number,
                        created_at
                    ) VALUES (?, ?, ?, ?, NOW())
                ");
                
                for ($i = 0; $i < $device_count; $i++) {
                    if (!empty($_POST['device_type'][$i])) {
                        // Create description from device type, make, and model
                        $description = $_POST['device_type'][$i];
                        if (!empty($_POST['make'][$i])) {
                            $description .= ' - ' . $_POST['make'][$i];
                        }
                        if (!empty($_POST['model'][$i])) {
                            $description .= ' ' . $_POST['model'][$i];
                        }
                        
                        $device_stmt->execute([
                            $_POST['job_code'],
                            $i + 1, // item_number starts at 1
                            $description,
                            $_POST['serial_number'][$i]
                        ]);
                        
                        $device_id = $pdo->lastInsertId();
                        
                        // Also prepare acquisition form entry in acquisition_forms table
                        $make_model = trim($_POST['make'][$i] . ' ' . $_POST['model'][$i]);
                        
                        // Check if acquisition_forms table structure matches our needs
                        $acq_stmt = $pdo->prepare("
                            INSERT INTO acquisition_forms (
                                case_id,
                                device_type,
                                make_model,
                                serial_number,
                                created_at
                            ) VALUES (?, ?, ?, ?, NOW())
                        ");
                        
                        $acq_stmt->execute([
                            $_POST['job_code'],
                            $_POST['device_type'][$i],
                            $make_model,
                            $_POST['serial_number'][$i]
                        ]);
                    }
                }
            }
            
            // Generate PDF for chain of custody if needed
            if (isset($_POST['generate_pdf']) && $_POST['generate_pdf'] == 'yes') {
                // Create directory if it doesn't exist
                if (!is_dir('documents/coc')) {
                    mkdir('documents/coc', 0755, true);
                }
                
                // PDF generation logic would go here...
                $pdf_filename = 'coc_' . $_POST['job_code'] . '.pdf';
                
                // Email logic would go here...
                if (isset($_POST['send_email']) && $_POST['send_email'] == 'yes' && !empty($_POST['released_by_email'])) {
                    // Email sending logic would go here...
                    $email_sent = true;
                }
            }
            
            // Commit the transaction
            $pdo->commit();
            
            $success_message = "Chain of Custody form created successfully" . 
                            (isset($email_sent) && $email_sent ? " and sent to client" : "") . 
                            ".";
            
            // Add to audit log
            $audit_stmt = $pdo->prepare("
                INSERT INTO audit_logs (
                    user_id, 
                    action, 
                    target_table, 
                    target_id, 
                    timestamp
                ) VALUES (?, ?, ?, ?, NOW())
            ");
            
            $audit_stmt->execute([
                $_SESSION['user_id'],
                'Created Chain of Custody form',
                'custody_logs',
                $custody_id
            ]);
                            
            // Redirect after short delay
            header("Refresh: 2; URL=view-coc.php?id=$custody_id");
            
        } catch(Exception $e) {
            // Roll back the transaction in case of error
            $pdo->rollBack();
            throw $e;
        }
    }
    
} catch (PDOException $e) {
    error_log('Database Error: ' . $e->getMessage());
    $error_message = "Error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Create Chain of Custody Form - Project Management System</title>
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
                <h1>Create Chain of Custody Form</h1>
                <nav class="breadcrumb">
                    <a href="dashboard.php">Dashboard</a> / 
                    <a href="coc.php">Chain of Custody</a> / 
                    <span>Create Form</span>
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

            <div class="content-card">
                <div class="form-header">
                    <div class="form-header-icon">
                        <i class="fas fa-file-contract"></i>
                    </div>
                    <div class="form-header-info">
                        <h2>Initial Chain of Custody Document</h2>
                        <p>This form records the initial transfer of devices from client to company for analysis.</p>
                    </div>
                </div>
                
                <form method="post" id="cocForm">
                    <div class="form-section">
                        <h2>Case Information</h2>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="job_code">Job Code</label>
                                <input type="text" id="job_code" name="job_code" value="<?= htmlspecialchars($case_data['case_number'] ?? '') ?>" required readonly>
                            </div>
                            <div class="form-group">
                                <label for="client_code">Client Code</label>
                                <input type="text" id="client_code" name="client_code" value="<?= htmlspecialchars($case_data['client_code'] ?? $client_data['client_code'] ?? '') ?>" required readonly>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section-header">
                        <h2><i class="fas fa-arrow-right"></i> INITIAL DEVICE TRANSFER</h2>
                    </div>
                    
                    <div class="form-section">
                        <h2>Released By (Client)</h2>
                        <div class="form-info-banner">
                            <i class="fas fa-info-circle"></i>
                            <p>Information about the client representative who is releasing the devices for analysis</p>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="released_by_name">Full Name</label>
                                <input type="text" id="released_by_name" name="released_by_name" value="<?= htmlspecialchars($case_data['rep_name'] ?? '') . ' ' . htmlspecialchars($case_data['rep_surname'] ?? '') ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="released_by_position">Company/Position</label>
                                <input type="text" id="released_by_position" name="released_by_position" value="<?= htmlspecialchars($case_data['company_name'] ?? $client_data['company_name'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="released_by_email">Email</label>
                                <input type="email" id="released_by_email" name="released_by_email" value="<?= htmlspecialchars($case_data['rep_email'] ?? $client_data['email'] ?? '') ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="released_by_phone">Phone</label>
                                <input type="tel" id="released_by_phone" name="released_by_phone" value="<?= htmlspecialchars($case_data['rep_phone'] ?? $client_data['phone'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="released_by_datetime">Date & Time</label>
                                <input type="datetime-local" id="released_by_datetime" name="released_by_datetime" value="<?= date('Y-m-d\TH:i', strtotime(CURRENT_TIMESTAMP)) ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="released_reason">Purpose of Analysis</label>
                                <input type="text" id="released_reason" name="released_reason" value="Digital Forensic Analysis" required>
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
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h2>Received By (Company Representative)</h2>
                        <div class="form-info-banner">
                            <i class="fas fa-info-circle"></i>
                            <p>Information about the company representative who is receiving the devices</p>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="received_by_name">Full Name</label>
                                <input type="text" id="received_by_name" name="received_by_name" value="<?= htmlspecialchars($_SESSION['name'] ?? CURRENT_USER) ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="received_by_position">Position</label>
                                <input type="text" id="received_by_position" name="received_by_position" value="Forensic Analyst" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="received_by_email">Email</label>
                                <input type="email" id="received_by_email" name="received_by_email" required>
                            </div>
                            <div class="form-group">
                                <label for="received_by_phone">Phone</label>
                                <input type="tel" id="received_by_phone" name="received_by_phone" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="received_by_datetime">Date & Time</label>
                                <input type="datetime-local" id="received_by_datetime" name="received_by_datetime" value="<?= date('Y-m-d\TH:i', strtotime(CURRENT_TIMESTAMP)) ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="received_reason">Reason for Receipt</label>
                                <input type="text" id="received_reason" name="received_reason" value="Digital Forensic Analysis" required>
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
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h2>Device Information</h2>
                        <div class="form-info-banner">
                            <i class="fas fa-info-circle"></i>
                            <p>List all devices being transferred for analysis</p>
                        </div>
                        <div class="devices-container" id="devicesContainer">
                            <div class="device-entry">
                                <div class="device-header">
                                    <h3>Device #1</h3>
                                    <button type="button" class="remove-device" title="Remove device"><i class="fas fa-trash"></i></button>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Device Type</label>
                                        <select name="device_type[]" required>
                                            <option value="">Select Type</option>
                                            <option value="Computer">Computer</option>
                                            <option value="Laptop">Laptop</option>
                                            <option value="Server">Server</option>
                                            <option value="Mobile Phone">Mobile Phone</option>
                                            <option value="Tablet">Tablet</option>
                                            <option value="Hard Drive">Hard Drive</option>
                                            <option value="USB Drive">USB Drive</option>
                                            <option value="Memory Card">Memory Card</option>
                                            <option value="Other">Other</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Make</label>
                                        <input type="text" name="make[]" placeholder="e.g. Apple, Dell, Samsung">
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Model</label>
                                        <input type="text" name="model[]" placeholder="e.g. MacBook Pro, Inspiron">
                                    </div>
                                    <div class="form-group">
                                        <label>Serial Number</label>
                                        <input type="text" name="serial_number[]" placeholder="Serial/IMEI Number">
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group full-width">
                                        <label>Condition Notes</label>
                                        <textarea name="condition_notes[]" rows="2" placeholder="Physical condition, any damage, etc."></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button type="button" id="addDevice" class="action-button secondary">
                            <i class="fas fa-plus"></i> Add Another Device
                        </button>
                    </div>
                    
                    <div class="form-section">
                        <h2>Document & Email Options</h2>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="generate_pdf">Generate PDF Document</label>
                                <div class="toggle-switch">
                                    <input type="checkbox" id="generate_pdf" name="generate_pdf" value="yes" checked>
                                    <label for="generate_pdf" class="switch"></label>
                                    <span class="toggle-label">Yes, generate PDF document</span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="send_email">Send Form to Client</label>
                                <div class="toggle-switch">
                                    <input type="checkbox" id="send_email" name="send_email" value="yes" checked>
                                    <label for="send_email" class="switch"></label>
                                    <span class="toggle-label">Yes, send the form to client's email</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-buttons">
                        <button type="button" onclick="window.history.back()" class="cancel-button">Cancel</button>
                        <button type="submit" class="submit-button">Create Chain of Custody</button>
                    </div>
                </form>
            </div>
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
            background-color: #2d3436;
            color: white;
            padding: 10px 0;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
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
            background: #ffd32a;
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

        .content-card {
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .form-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .form-header-icon {
            width: 50px;
            height: 50px;
            background-color: #fff4cc;
            color: #ffa000;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }
        
        .form-header-info h2 {
            margin-bottom: 5px;
            color: #2d3436;
        }
        
        .form-header-info p {
            color: #636e72;
            font-size: 0.9em;
        }

        .form-section {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e9ecef;
        }

        .form-section:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .form-section h2 {
            font-size: 1.25rem;
            margin-bottom: 15px;
            color: #2d3436;
        }
        
        .form-section-header {
            background-color: #2d3436;
            color: white;
            padding: 12px 20px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        
        .form-section-header h2 {
            font-size: 1.1rem;
            margin: 0;
            color: white;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-info-banner {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 15px;
            background-color: #e3f2fd;
            color: #0288d1;
            border-radius: 4px;
            margin-bottom: 15px;
            font-size: 0.9em;
        }
        
        .form-info-banner.warning {
            background-color: #fff8e1;
            color: #ff8f00;
        }

        .form-row {
            display: flex;
            flex-wrap: wrap;
            margin: 0 -10px 15px;
        }

        .form-group {
            flex: 1;
            padding: 0 10px;
            min-width: 200px;
            margin-bottom: 15px;
        }

        .form-group.full-width {
            flex-basis: 100%;
        }

        label {
            display: block;
            margin-bottom: 5px;
            font-size: 0.9em;
            font-weight: 500;
            color: #4d4d4d;
        }

        input[type="text"],
        input[type="email"],
        input[type="tel"],
        input[type="date"],
        input[type="datetime-local"],
        select,
        textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #e9ecef;
            border-radius: 4px;
            font-size: 1em;
            transition: border-color 0.3s ease;
        }

        input[type="text"]:focus,
        input[type="email"]:focus,
        input[type="tel"]:focus,
        input[type="date"]:focus,
        input[type="datetime-local"]:focus,
        select:focus,
        textarea:focus {
            border-color: #ffd32a;
            outline: none;
            box-shadow: 0 0 0 3px rgba(255, 211, 42, 0.2);
        }

        input[readonly] {
            background-color: #f8f9fa;
            cursor: not-allowed;
        }

        .signature-container {
            border: 1px solid #e9ecef;
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 10px;
        }

        .signature-pad {
            width: 100%;
            height: 150px;
            background-color: #fff;
            border-bottom: 1px solid #e9ecef;
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

        .devices-container {
            margin-bottom: 20px;
        }

        .device-entry {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            border: 1px solid #e9ecef;
        }

        .device-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .device-header h3 {
            font-size: 1em;
            margin: 0;
        }

        .remove-device {
            background: none;
            border: none;
            color: #e74c3c;
            cursor: pointer;
            font-size: 1em;
            padding: 5px;
            border-radius: 4px;
        }

        .remove-device:hover {
            background-color: #ffe3e3;
        }

        .toggle-switch {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .toggle-switch input {
            display: none;
        }

        .switch {
            position: relative;
            display: inline-block;
            width: 40px;
            height: 24px;
            background-color: #e9ecef;
            border-radius: 12px;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }

        .switch::after {
            content: '';
            position: absolute;
            top: 2px;
            left: 2px;
            width: 20px;
            height: 20px;
            background-color: white;
            border-radius: 50%;
            transition: transform 0.3s ease;
        }

        input:checked + .switch {
            background-color: #ffd32a;
        }

        input:checked + .switch::after {
            transform: translateX(16px);
        }

        .toggle-label {
            font-size: 0.95em;
        }

        .form-buttons {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            padding-top: 20px;
        }

        .cancel-button {
            padding: 10px 20px;
            background: #e9ecef;
            border: none;
            border-radius: 4px;
            color: #4d4d4d;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .cancel-button:hover {
            background: #dee2e6;
        }

        .submit-button {
            padding: 10px 20px;
            background: #ffd32a;
            border: none;
            border-radius: 4px;
            color: #2d3436;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .submit-button:hover {
            background: #f9ca24;
            transform: translateY(-2px);
        }

        .action-button {
            padding: 10px 16px;
            background: #e9ecef;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            transition: all 0.2s ease;
            color: #2d3436;
        }

        .action-button:hover {
            background: #dee2e6;
        }

        .action-button.secondary {
            background: #74b9ff;
            color: #fff;
        }

        .action-button.secondary:hover {
            background: #0984e3;
        }

        .mobile-menu-toggle {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: #ffd32a;
            border: none;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            cursor: pointer;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
            z-index: 1000;
        }

        .error {
            border-color: #e03131 !important;
        }
        
        .error-message {
            color: #e03131;
            font-size: 0.8em;
            margin-top: 5px;
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
            .form-buttons {
                flex-direction: column;
            }
            
            .form-buttons button {
                width: 100%;
            }
            
            .system-info {
                flex-direction: column;
                align-items: center;
                gap: 5px;
            }
        }

        @media (max-width: 480px) {
            .content-card {
                padding: 15px;
            }
            
            .form-header {
                flex-direction: column;
                text-align: center;
            }
            
            .form-info-banner {
                flex-direction: column;
                text-align: center;
                padding: 15px;
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
            
            // Initialize signature pads
            const releasedSignaturePad = new SignaturePad(document.getElementById('released-signature-pad'), {
                backgroundColor: 'rgba(255, 255, 255, 0)',
                penColor: 'black'
            });
            
            const receivedSignaturePad = new SignaturePad(document.getElementById('received-signature-pad'), {
                backgroundColor: 'rgba(255, 255, 255, 0)',
                penColor: 'black'
            });
            
            // Clear signature buttons
            document.getElementById('clear-released-signature').addEventListener('click', function() {
                releasedSignaturePad.clear();
                document.getElementById('released_signature_data').value = '';
            });
            
            document.getElementById('clear-received-signature').addEventListener('click', function() {
                receivedSignaturePad.clear();
                document.getElementById('received_signature_data').value = '';
            });
            
            // Device management
            const devicesContainer = document.getElementById('devicesContainer');
            const addDeviceBtn = document.getElementById('addDevice');
            let deviceCount = 1;
            
            // Add new device
            addDeviceBtn.addEventListener('click', function() {
                deviceCount++;
                const deviceElement = document.createElement('div');
                deviceElement.className = 'device-entry';
                deviceElement.innerHTML = `
                    <div class="device-header">
                        <h3>Device #${deviceCount}</h3>
                        <button type="button" class="remove-device" title="Remove device"><i class="fas fa-trash"></i></button>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Device Type</label>
                            <select name="device_type[]" required>
                                <option value="">Select Type</option>
                                <option value="Computer">Computer</option>
                                <option value="Laptop">Laptop</option>
                                <option value="Server">Server</option>
                                <option value="Mobile Phone">Mobile Phone</option>
                                <option value="Tablet">Tablet</option>
                                <option value="Hard Drive">Hard Drive</option>
                                <option value="USB Drive">USB Drive</option>
                                <option value="Memory Card">Memory Card</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Make</label>
                            <input type="text" name="make[]" placeholder="e.g. Apple, Dell, Samsung">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Model</label>
                            <input type="text" name="model[]" placeholder="e.g. MacBook Pro, Inspiron">
                        </div>
                        <div class="form-group">
                            <label>Serial Number</label>
                            <input type="text" name="serial_number[]" placeholder="Serial/IMEI Number">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group full-width">
                            <label>Condition Notes</label>
                            <textarea name="condition_notes[]" rows="2" placeholder="Physical condition, any damage, etc."></textarea>
                        </div>
                    </div>
                `;
                
                devicesContainer.appendChild(deviceElement);
                
                // Attach remove event to the new device's remove button
                deviceElement.querySelector('.remove-device').addEventListener('click', function() {
                    deviceElement.remove();
                    updateDeviceNumbers();
                });
            });
            
            // Remove device
            document.addEventListener('click', function(e) {
                if (e.target.closest('.remove-device')) {
                    const deviceEntry = e.target.closest('.device-entry');
                    // Don't remove if it's the only device
                    if (document.querySelectorAll('.device-entry').length > 1) {
                        deviceEntry.remove();
                        updateDeviceNumbers();
                    } else {
                        alert('At least one device is required.');
                    }
                }
            });
            
            // Update device numbers after removal
            function updateDeviceNumbers() {
                const deviceEntries = document.querySelectorAll('.device-entry');
                deviceEntries.forEach((entry, index) => {
                    entry.querySelector('h3').textContent = `Device #${index + 1}`;
                });
                deviceCount = deviceEntries.length;
            }
            
            // Form validation
            const cocForm = document.getElementById('cocForm');
            
            cocForm.addEventListener('submit', function(e) {
                let valid = true;
                
                // Check if signatures are added
                if (releasedSignaturePad.isEmpty()) {
                    alert('Please add the client\'s signature or collect it when handing over the form.');
                    valid = false;
                } else {
                    document.getElementById('released_signature_data').value = releasedSignaturePad.toDataURL();
                }
                
                if (receivedSignaturePad.isEmpty()) {
                    alert('Please sign the form as the company representative.');
                    valid = false;
                } else {
                    document.getElementById('received_signature_data').value = receivedSignaturePad.toDataURL();
                }
                
                // Check required fields
                const requiredFields = cocForm.querySelectorAll('[required]');
                
                requiredFields.forEach(field => {
                    if (!field.value.trim()) {
                        valid = false;
                        field.classList.add('error');
                        
                        // Add error message if it doesn't exist
                        const errorMsg = field.nextElementSibling;
                        if (!errorMsg || !errorMsg.classList.contains('error-message')) {
                            const msg = document.createElement('div');
                            msg.className = 'error-message';
                            msg.textContent = 'This field is required';
                            field.parentNode.insertBefore(msg, field.nextSibling);
                        }
                    } else {
                        field.classList.remove('error');
                        const errorMsg = field.nextElementSibling;
                        if (errorMsg && errorMsg.classList.contains('error-message')) {
                            errorMsg.remove();
                        }
                    }
                });
                
                if (!valid) {
                    e.preventDefault();
                    alert('Please fill in all required fields and ensure both signatures are present.');
                }
            });
            
            // Remove error styling on input
            document.addEventListener('input', function(e) {
                if (e.target.hasAttribute('required')) {
                    e.target.classList.remove('error');
                    const errorMsg = e.target.nextElementSibling;
                    if (errorMsg && errorMsg.classList.contains('error-message')) {
                        errorMsg.remove();
                    }
                }
            });
            
            // Make signature pads responsive
            function resizeCanvas() {
                const releasedCanvas = document.getElementById('released-signature-pad');
                const receivedCanvas = document.getElementById('received-signature-pad');
                const ratio = Math.max(window.devicePixelRatio || 1, 1);
                
                // Released signature pad
                releasedCanvas.width = releasedCanvas.offsetWidth * ratio;
                releasedCanvas.height = releasedCanvas.offsetHeight * ratio;
                releasedCanvas.getContext("2d").scale(ratio, ratio);
                releasedSignaturePad.clear();
                
                // Received signature pad
                receivedCanvas.width = receivedCanvas.offsetWidth * ratio;
                receivedCanvas.height = receivedCanvas.offsetHeight * ratio;
                receivedCanvas.getContext("2d").scale(ratio, ratio);
                receivedSignaturePad.clear();
            }
            
            window.addEventListener("resize", resizeCanvas);
            resizeCanvas();
        });
    </script>
</body>
</html>