<?php
session_start();
define('CURRENT_TIMESTAMP', '2025-06-13 20:11:21');
define('CURRENT_USER', 'nkosinathil');

// Database connection setup
include_once('db-connection.php');

// Get ID from URL parameter
$acquisition_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Check if ID is valid
if ($acquisition_id <= 0) {
    header("Location: error.php?msg=Invalid or missing acquisition ID");
    exit;
}

$acquisition = [];
$error_message = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    // Fetch acquisition record
    $stmt = $pdo->prepare("SELECT * FROM acquisition_forms WHERE id = ?");
    $stmt->execute([$acquisition_id]);
    $acquisition = $stmt->fetch();
    
    if (!$acquisition) {
        $error_message = "Acquisition record #$acquisition_id not found.";
    }
} catch (PDOException $e) {
    $error_message = "Database error: " . $e->getMessage();
    error_log("Error in view-acquisition.php: " . $e->getMessage());
}

// Helper function to display value safely
function displayValue($value) {
    if (empty($value) || $value === '0000-00-00' || $value === '0000-00-00 00:00:00' || $value === '00:00:00') {
        return '<span class="not-available">Not available</span>';
    }
    return htmlspecialchars($value);
}

// Format date if available
function formatDate($date) {
    if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
        return '<span class="not-available">Not available</span>';
    }
    return date("Y-m-d", strtotime($date));
}

// Format time if available
function formatTime($time) {
    if (empty($time) || $time === '00:00:00') {
        return '<span class="not-available">Not available</span>';
    }
    return date("H:i", strtotime($time));
}

// Generate a badge based on value
function generateBadge($value, $positive = 'Yes', $positiveClass = 'success', $negativeClass = 'danger') {
    if ($value === $positive) {
        return "<span class=\"badge badge-$positiveClass\">$value</span>";
    } else {
        return "<span class=\"badge badge-$negativeClass\">$value</span>";
    }
}

// Get device types as array
function getDeviceTypes($deviceType) {
    if (empty($deviceType)) return [];
    return explode(', ', $deviceType);
}

// Get interface types as array
function getInterfaceTypes($interfaceType) {
    if (empty($interfaceType)) return [];
    return explode(', ', $interfaceType);
}

// Get imaging formats as array
function getImagingFormats($imagingFormat) {
    if (empty($imagingFormat)) return [];
    return explode(', ', $imagingFormat);
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>View Acquisition Details</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { 
            font-family: 'Inter', sans-serif; 
            margin: 20px;
            background-color: #f5f6fa;
            color: #2d3436;
            line-height: 1.6;
        }
        
        .header-bar {
            background-color: #fff;
            padding: 15px 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-bar h1 {
            margin: 0;
            font-size: 1.5rem;
            color: #2d3436;
        }
        
        .user-info {
            display: flex;
            flex-direction: column;
            font-size: 0.85em;
            color: #636e72;
        }
        
        .breadcrumb {
            margin-bottom: 15px;
            font-size: 0.9em;
            color: #636e72;
            padding: 10px 0;
        }
        
        .breadcrumb a {
            color: #636e72;
            text-decoration: none;
        }
        
        .breadcrumb a:hover {
            color: #2d3436;
            text-decoration: underline;
        }
        
        .container {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .sidebar {
            flex: 0 0 250px;
        }
        
        .main-content {
            flex: 1;
            min-width: 0;
        }
        
        .action-card {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        
        .action-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-top: 0;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .action-buttons {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 15px;
            border-radius: 6px;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
        }
        
        .btn-primary {
            background-color: #007bff;
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #0069d9;
        }
        
        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background-color: #5a6268;
        }
        
        .btn-success {
            background-color: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background-color: #218838;
        }
        
        .btn-danger {
            background-color: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background-color: #c82333;
        }
        
        .btn-outline {
            background-color: transparent;
            border: 1px solid #6c757d;
            color: #6c757d;
        }
        
        .btn-outline:hover {
            background-color: #f8f9fa;
        }
        
        .btn-block {
            width: 100%;
        }
        
        .section-card {
            background-color: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 25px;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .section-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin: 0;
            color: #2d3436;
        }
        
        .section-icon {
            color: #007bff;
            font-size: 1.3rem;
        }
        
        .details-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .details-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: top;
        }
        
        .details-table tr:last-child td {
            border-bottom: none;
        }
        
        .details-label {
            width: 35%;
            font-weight: 500;
            color: #495057;
        }
        
        .details-value {
            width: 65%;
        }
        
        .highlights-row {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .highlight-card {
            flex: 1;
            min-width: 200px;
            background-color: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            position: relative;
            overflow: hidden;
        }
        
        .highlight-card::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 4px;
            background-color: #007bff;
        }
        
        .highlight-label {
            font-size: 0.9rem;
            color: #636e72;
            margin-bottom: 8px;
        }
        
        .highlight-value {
            font-size: 1.2rem;
            font-weight: 600;
            color: #2d3436;
        }
        
        .highlight-icon {
            position: absolute;
            top: 15px;
            right: 15px;
            color: rgba(0, 123, 255, 0.15);
            font-size: 2rem;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .badge-success {
            background-color: #d3f9d8;
            color: #2b8a3e;
        }
        
        .badge-danger {
            background-color: #ffe3e3;
            color: #e03131;
        }
        
        .badge-warning {
            background-color: #fff3bf;
            color: #e67700;
        }
        
        .badge-info {
            background-color: #e3fafc;
            color: #1098ad;
        }
        
        .timeline {
            margin: 20px 0;
        }
        
        .timeline-item {
            padding-left: 30px;
            position: relative;
            margin-bottom: 20px;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 2px;
            background-color: #dee2e6;
        }
        
        .timeline-item::after {
            content: '';
            position: absolute;
            left: -4px;
            top: 0;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background-color: #007bff;
        }
        
        .timeline-time {
            font-size: 0.9rem;
            color: #636e72;
            margin-bottom: 5px;
        }
        
        .timeline-content {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            position: relative;
        }
        
        .timeline-content::before {
            content: '';
            position: absolute;
            left: -10px;
            top: 15px;
            width: 0;
            height: 0;
            border-top: 10px solid transparent;
            border-bottom: 10px solid transparent;
            border-right: 10px solid #f8f9fa;
        }
        
        .timeline-title {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .not-available {
            color: #adb5bd;
            font-style: italic;
        }
        
        .hash-value {
            font-family: monospace;
            word-break: break-all;
            background-color: #f8f9fa;
            padding: 8px;
            border-radius: 4px;
            font-size: 0.9em;
        }
        
        .tag-list {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin: 0;
            padding: 0;
            list-style: none;
        }
        
        .tag {
            background-color: #e9ecef;
            border-radius: 4px;
            padding: 4px 8px;
            font-size: 0.85rem;
            color: #495057;
        }
        
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        
        .alert-danger {
            background-color: #ffe3e3;
            color: #e03131;
        }
        
        .alert-icon {
            font-size: 1.5rem;
        }
        
        .alert-content {
            flex: 1;
        }
        
        .alert-title {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .alert-message {
            margin: 0;
        }
        
        @media (max-width: 768px) {
            .container {
                flex-direction: column;
            }
            
            .sidebar {
                flex: 0 0 100%;
                order: 2;
            }
            
            .main-content {
                flex: 0 0 100%;
                order: 1;
            }
            
            .highlights-row {
                flex-direction: column;
                gap: 15px;
            }
            
            .details-table td {
                display: block;
                width: 100%;
            }
            
            .details-label {
                padding-bottom: 0;
                border-bottom: none;
            }
            
            .details-value {
                padding-top: 5px;
            }
        }
        
        .section-card.case-info {
            border-left: 4px solid #1976d2;
        }
        
        .section-card.device-info {
            border-left: 4px solid #2196f3;
        }
        
        .section-card.imaging-details {
            border-left: 4px solid #00acc1;
        }
        
        .section-card.hash-verification {
            border-left: 4px solid #26a69a;
        }
        
        .section-card.acquisition-notes {
            border-left: 4px solid #66bb6a;
        }
        
        .print-header {
            display: none;
        }
        
        /* Print-specific styles - FIXED */
        @media print {
            body {
                background-color: white !important;
                color: black !important;
                margin: 0 !important;
                padding: 20px !important;
                font-size: 12pt !important;
            }
            
            .header-bar, .breadcrumb, .sidebar, .action-buttons {
                display: none !important;
            }
            
            .container {
                display: block !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            
            .main-content {
                width: 100% !important;
            }
            
            .section-card {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
                page-break-inside: avoid !important;
                margin-bottom: 15px !important;
                padding: 15px !important;
            }
            
            .print-header {
                display: block !important;
                text-align: center !important;
                margin-bottom: 20px !important;
                padding-bottom: 15px !important;
                border-bottom: 1px solid #ddd !important;
            }
            
            .print-header h1 {
                margin-bottom: 5px !important;
                font-size: 18pt !important;
            }
            
            .print-header p {
                margin: 0 0 5px 0 !important;
                font-size: 10pt !important;
            }
            
            .highlights-row {
                display: none !important;
            }
            
            .badge {
                border: 1px solid #ddd !important;
                color: #000 !important;
                background: #f9f9f9 !important;
            }
            
            .section-header {
                border-bottom: 1px solid #000 !important;
            }
            
            .details-table {
                border-collapse: collapse !important;
                width: 100% !important;
            }
            
            .details-table td {
                border-bottom: 1px solid #ddd !important;
                padding: 8px 5px !important;
            }
            
            .details-label {
                font-weight: bold !important;
            }
            
            a {
                text-decoration: none !important;
                color: #000 !important;
            }
            
            /* Force print background colors and images */
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
        }
    </style>
</head>
<body>

<div class="header-bar">
    <h1>Acquisition Details</h1>
    <div class="user-info">
        <span>Current Date and Time (UTC - YYYY-MM-DD HH:MM:SS formatted): <?= CURRENT_TIMESTAMP ?></span>
        <span>Current User's Login: <?= CURRENT_USER ?></span>
    </div>
</div>

<div class="breadcrumb">
    <a href="dashboard.php">Dashboard</a> / 
    <a href="coc.php">Chain of Custody</a> /
    <a href="view-acquisition-forms.php">Acquisition Forms</a> /
    <span>View Details</span>
</div>

<?php if (!empty($error_message)): ?>
<div class="alert alert-danger">
    <div class="alert-icon">
        <i class="fas fa-exclamation-triangle"></i>
    </div>
    <div class="alert-content">
        <div class="alert-title">Error</div>
        <p class="alert-message"><?= $error_message ?></p>
    </div>
</div>
<?php else: ?>

<div class="print-header">
    <h1>Forensic Acquisition Report</h1>
    <p>Case ID: <?= displayValue($acquisition['case_id']) ?></p>
    <p>Created: <?= displayValue($acquisition['created_at']) ?></p>
    <p>Report ID: <?= $acquisition_id ?></p>
</div>

<div class="container">
    <div class="main-content">
        <!-- Highlights -->
        <div class="highlights-row">
            <div class="highlight-card">
                <div class="highlight-label">Case ID</div>
                <div class="highlight-value"><?= displayValue($acquisition['case_id']) ?></div>
                <div class="highlight-icon">
                    <i class="fas fa-folder-open"></i>
                </div>
            </div>
            
            <div class="highlight-card">
                <div class="highlight-label">Investigator</div>
                <div class="highlight-value"><?= displayValue($acquisition['investigator_name']) ?></div>
                <div class="highlight-icon">
                    <i class="fas fa-user-shield"></i>
                </div>
            </div>
            
            <div class="highlight-card">
                <div class="highlight-label">Acquisition Date</div>
                <div class="highlight-value"><?= formatDate($acquisition['acquisition_date']) ?></div>
                <div class="highlight-icon">
                    <i class="fas fa-calendar-alt"></i>
                </div>
            </div>
            
            <div class="highlight-card">
                <div class="highlight-label">Hash Verification</div>
                <div class="highlight-value">
                    <?php if ($acquisition['hash_match'] === 'Yes'): ?>
                        <span style="color: #2b8a3e;"><i class="fas fa-check-circle"></i> Successful</span>
                    <?php elseif ($acquisition['hash_match'] === 'No'): ?>
                        <span style="color: #e03131;"><i class="fas fa-times-circle"></i> Failed</span>
                    <?php else: ?>
                        <span style="color: #868e96;">Not Performed</span>
                    <?php endif; ?>
                </div>
                <div class="highlight-icon">
                    <i class="fas fa-fingerprint"></i>
                </div>
            </div>
        </div>
        
        <!-- Case Information Section -->
        <div class="section-card case-info">
            <div class="section-header">
                <h2 class="section-title">Case Information</h2>
                <div class="section-icon">
                    <i class="fas fa-folder-open"></i>
                </div>
            </div>
            
            <table class="details-table">
                <tr>
                    <td class="details-label">Case ID</td>
                    <td class="details-value"><?= displayValue($acquisition['case_id']) ?></td>
                </tr>
                
                <?php if (isset($acquisition['evidence_number'])): ?>
                <tr>
                    <td class="details-label">Evidence Number</td>
                    <td class="details-value"><?= displayValue($acquisition['evidence_number']) ?></td>
                </tr>
                <?php endif; ?>
                
                <?php if (isset($acquisition['unique_description'])): ?>
                <tr>
                    <td class="details-label">Device Description</td>
                    <td class="details-value"><?= displayValue($acquisition['unique_description']) ?></td>
                </tr>
                <?php endif; ?>
                
                <tr>
                    <td class="details-label">Investigator Name</td>
                    <td class="details-value"><?= displayValue($acquisition['investigator_name']) ?></td>
                </tr>
                
                <tr>
                    <td class="details-label">Date of Acquisition</td>
                    <td class="details-value"><?= formatDate($acquisition['acquisition_date']) ?></td>
                </tr>
                
                <tr>
                    <td class="details-label">Location</td>
                    <td class="details-value"><?= displayValue($acquisition['location']) ?></td>
                </tr>
                
                <tr>
                    <td class="details-label">Imaging Tool</td>
                    <td class="details-value"><?= displayValue($acquisition['imaging_tool']) ?> <?= displayValue($acquisition['tool_version']) ?></td>
                </tr>
            </table>
        </div>
        
        <!-- Device Information Section -->
        <div class="section-card device-info">
            <div class="section-header">
                <h2 class="section-title">Device Information</h2>
                <div class="section-icon">
                    <i class="fas fa-hdd"></i>
                </div>
            </div>
            
            <table class="details-table">
                <tr>
                    <td class="details-label">Device Type</td>
                    <td class="details-value">
                        <?php if (!empty($acquisition['device_type'])): ?>
                            <ul class="tag-list">
                                <?php foreach(getDeviceTypes($acquisition['device_type']) as $type): ?>
                                    <li class="tag"><?= htmlspecialchars($type) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <?= displayValue('') ?>
                        <?php endif; ?>
                    </td>
                </tr>
                
                <tr>
                    <td class="details-label">Make & Model</td>
                    <td class="details-value"><?= displayValue($acquisition['make_model']) ?></td>
                </tr>
                
                <tr>
                    <td class="details-label">Serial Number</td>
                    <td class="details-value"><?= displayValue($acquisition['serial_number']) ?></td>
                </tr>
                
                <tr>
                    <td class="details-label">Capacity</td>
                    <td class="details-value"><?= displayValue($acquisition['capacity']) ?></td>
                </tr>
                
                <tr>
                    <td class="details-label">Interface Type</td>
                    <td class="details-value">
                        <?php if (!empty($acquisition['interface_type'])): ?>
                            <ul class="tag-list">
                                <?php foreach(getInterfaceTypes($acquisition['interface_type']) as $type): ?>
                                    <li class="tag"><?= htmlspecialchars($type) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <?= displayValue('') ?>
                        <?php endif; ?>
                    </td>
                </tr>
                
                <?php if (!empty($acquisition['interface_other'])): ?>
                <tr>
                    <td class="details-label">Interface - Other</td>
                    <td class="details-value"><?= displayValue($acquisition['interface_other']) ?></td>
                </tr>
                <?php endif; ?>
                
                <tr>
                    <td class="details-label">Write Blocker Used</td>
                    <td class="details-value">
                        <?php if ($acquisition['write_blocker'] === 'Yes'): ?>
                            <?= generateBadge('Yes', 'Yes', 'success') ?>
                        <?php else: ?>
                            <?= generateBadge('No', 'Yes', 'success', 'warning') ?>
                            <?php if (!empty($acquisition['write_blocker_reason'])): ?>
                                <div style="margin-top: 5px;"><?= displayValue($acquisition['write_blocker_reason']) ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- Imaging Details Section -->
        <div class="section-card imaging-details">
            <div class="section-header">
                <h2 class="section-title">Imaging Details</h2>
                <div class="section-icon">
                    <i class="fas fa-copy"></i>
                </div>
            </div>
            
            <table class="details-table">
                <tr>
                    <td class="details-label">Imaging Start Time</td>
                    <td class="details-value"><?= formatTime($acquisition['start_time']) ?></td>
                </tr>
                
                <tr>
                    <td class="details-label">Imaging End Time</td>
                    <td class="details-value"><?= formatTime($acquisition['end_time']) ?></td>
                </tr>
                
                <tr>
                    <td class="details-label">Imaging Format</td>
                    <td class="details-value">
                        <?php if (!empty($acquisition['imaging_format'])): ?>
                            <ul class="tag-list">
                                <?php foreach(getImagingFormats($acquisition['imaging_format']) as $format): ?>
                                    <li class="tag"><?= htmlspecialchars($format) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <?= displayValue('') ?>
                        <?php endif; ?>
                    </td>
                </tr>
                
                <?php if (!empty($acquisition['format_other'])): ?>
                <tr>
                    <td class="details-label">Format - Other</td>
                    <td class="details-value"><?= displayValue($acquisition['format_other']) ?></td>
                </tr>
                <?php endif; ?>
                
                <tr>
                    <td class="details-label">Compression</td>
                    <td class="details-value"><?= generateBadge($acquisition['compression'] ?? 'No', 'Yes', 'info', 'secondary') ?></td>
                </tr>
                
                <tr>
                    <td class="details-label">Verification</td>
                    <td class="details-value"><?= generateBadge($acquisition['verification'] ?? 'No', 'Yes', 'success', 'warning') ?></td>
                </tr>
            </table>
        </div>
        
        <!-- Hash Verification Section -->
        <div class="section-card hash-verification">
            <div class="section-header">
                <h2 class="section-title">Hash Verification</h2>
                <div class="section-icon">
                    <i class="fas fa-fingerprint"></i>
                </div>
            </div>
            
            <table class="details-table">
                <tr>
                    <td class="details-label">Hash Type</td>
                    <td class="details-value"><?= displayValue($acquisition['hash_type']) ?></td>
                </tr>
                
                <tr>
                    <td class="details-label">Original Hash</td>
                    <td class="details-value">
                        <?php if (!empty($acquisition['original_hash'])): ?>
                            <div class="hash-value"><?= displayValue($acquisition['original_hash']) ?></div>
                        <?php else: ?>
                            <?= displayValue('') ?>
                        <?php endif; ?>
                    </td>
                </tr>
                
                <tr>
                    <td class="details-label">Image Hash</td>
                    <td class="details-value">
                        <?php if (!empty($acquisition['image_hash'])): ?>
                            <div class="hash-value"><?= displayValue($acquisition['image_hash']) ?></div>
                        <?php else: ?>
                            <?= displayValue('') ?>
                        <?php endif; ?>
                    </td>
                </tr>
                
                <tr>
                    <td class="details-label">Hash Match</td>
                    <td class="details-value">
                        <?php if ($acquisition['hash_match'] === 'Yes'): ?>
                            <span style="color: #2b8a3e;"><i class="fas fa-check-circle"></i> Verified Successfully</span>
                        <?php elseif ($acquisition['hash_match'] === 'No'): ?>
                            <span style="color: #e03131;"><i class="fas fa-times-circle"></i> Verification Failed</span>
                        <?php else: ?>
                            <span style="color: #868e96;"><i class="fas fa-question-circle"></i> Not Verified</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- Acquisition Notes Section -->
        <div class="section-card acquisition-notes">
            <div class="section-header">
                <h2 class="section-title">Notes & Chain of Custody</h2>
                <div class="section-icon">
                    <i class="fas fa-clipboard-list"></i>
                </div>
            </div>
            
            <table class="details-table">
                <?php if (!empty($acquisition['notes'])): ?>
                <tr>
                    <td class="details-label">Notes</td>
                    <td class="details-value"><?= nl2br(displayValue($acquisition['notes'])) ?></td>
                </tr>
                <?php endif; ?>
                
                <?php if (!empty($acquisition['additional_notes'])): ?>
                <tr>
                    <td class="details-label">Additional Notes</td>
                    <td class="details-value"><?= nl2br(displayValue($acquisition['additional_notes'])) ?></td>
                </tr>
                <?php endif; ?>
                
                <tr>
                    <td class="details-label">Acquired By</td>
                    <td class="details-value">
                        <?= displayValue($acquisition['acquired_by_name']) ?>
                        <?php if (!empty($acquisition['acquired_by_date'])): ?>
                            <div style="margin-top: 5px; font-size: 0.9em; color: #636e72;">Date: <?= formatDate($acquisition['acquired_by_date']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($acquisition['acquired_by_signature'])): ?>
                            <div style="margin-top: 5px; font-style: italic;">Signature: <?= displayValue($acquisition['acquired_by_signature']) ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
                
                <?php if (!empty($acquisition['received_by_name'])): ?>
                <tr>
                    <td class="details-label">Received By</td>
                    <td class="details-value">
                        <?= displayValue($acquisition['received_by_name']) ?>
                        <?php if (!empty($acquisition['received_by_date'])): ?>
                            <div style="margin-top: 5px; font-size: 0.9em; color: #636e72;">Date: <?= formatDate($acquisition['received_by_date']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($acquisition['received_by_signature'])): ?>
                            <div style="margin-top: 5px; font-style: italic;">Signature: <?= displayValue($acquisition['received_by_signature']) ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
                
                <?php if (!empty($acquisition['uploaded_log_file'])): ?>
                <tr>
                    <td class="details-label">Log File</td>
                    <td class="details-value">
                        <a href="uploads/logs/<?= htmlspecialchars($acquisition['uploaded_log_file']) ?>" download class="btn btn-outline" style="padding: 5px 10px; display: inline-flex;">
                            <i class="fas fa-download"></i> Download Log File
                        </a>
                    </td>
                </tr>
                <?php endif; ?>
                
                <tr>
                    <td class="details-label">Record Created</td>
                    <td class="details-value"><?= displayValue($acquisition['created_at']) ?></td>
                </tr>
            </table>
        </div>
    </div>
    
    <div class="sidebar">
        <div class="action-card">
            <h3 class="action-title">Actions</h3>
            
            <div class="action-buttons">
                <a href="edit-acquisition.php?id=<?= $acquisition_id ?>" class="btn btn-primary btn-block">
                    <i class="fas fa-edit"></i> Edit Record
                </a>
                
                <button type="button" class="btn btn-secondary btn-block" id="print-button" onclick="window.print();">
                    <i class="fas fa-print"></i> Print Report
                </button>
                
                <?php if (!empty($acquisition['uploaded_log_file'])): ?>
                <a href="uploads/logs/<?= htmlspecialchars($acquisition['uploaded_log_file']) ?>" download class="btn btn-outline btn-block">
                    <i class="fas fa-file-download"></i> Download Log
                </a>
                <?php endif; ?>
                
                <a href="view-acquisition-forms.php<?= !empty($acquisition['case_id']) ? '?job_code=' . urlencode($acquisition['case_id']) : '' ?>" class="btn btn-outline btn-block">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
                
                <button type="button" class="btn btn-danger btn-block" onclick="confirmDelete(<?= $acquisition_id ?>)">
                    <i class="fas fa-trash"></i> Delete Record
                </button>
            </div>
        </div>
        
        <?php if (!empty($acquisition['case_id'])): ?>
        <div class="action-card">
            <h3 class="action-title">Related Records</h3>
            
            <div class="action-buttons">
                <a href="view-acquisition-forms.php?job_code=<?= urlencode($acquisition['case_id']) ?>" class="btn btn-outline btn-block">
                    <i class="fas fa-search"></i> Other Acquisitions for this Case
                </a>
                
                <a href="case-details.php?id=<?= urlencode($acquisition['case_id']) ?>" class="btn btn-outline btn-block">
                    <i class="fas fa-folder-open"></i> View Case Details
                </a>
                
                <a href="acquisition-form.php?job_code=<?= urlencode($acquisition['case_id']) ?>" class="btn btn-outline btn-block">
                    <i class="fas fa-plus"></i> New Acquisition for this Case
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Delete confirmation dialog -->
<div id="deleteConfirmDialog" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center;">
    <div style="background-color: white; padding: 25px; border-radius: 8px; width: 400px; max-width: 90%;">
        <h3 style="margin-top: 0;">Confirm Deletion</h3>
        <p>Are you sure you want to delete this acquisition record? This action cannot be undone.</p>
        <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
            <button class="btn btn-outline" onclick="cancelDelete()">Cancel</button>
            <form id="deleteForm" method="POST" action="delete-acquisition.php">
                <input type="hidden" id="deleteRecordId" name="acquisition_id" value="">
                <button type="submit" class="btn btn-danger">Delete</button>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Directly attach event to print button
        document.getElementById('print-button').addEventListener('click', function() {
            window.print();
        });
    });
    
    function confirmDelete(id) {
        document.getElementById('deleteRecordId').value = id;
        document.getElementById('deleteConfirmDialog').style.display = 'flex';
    }
    
    function cancelDelete() {
        document.getElementById('deleteConfirmDialog').style.display = 'none';
    }
    
    // Close the dialog if clicking outside
    document.getElementById('deleteConfirmDialog').addEventListener('click', function(event) {
        if (event.target === this) {
            cancelDelete();
        }
    });
</script>

</body>
</html>