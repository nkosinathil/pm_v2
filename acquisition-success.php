<?php
session_start();
define('CURRENT_TIMESTAMP', '2025-06-13 19:11:32');
define('CURRENT_USER', 'nkosinathil');

// Database connection setup
include_once('db-connection.php');

$acquisition_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$acquisition = [];

if ($acquisition_id > 0) {
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        
        $stmt = $pdo->prepare("SELECT * FROM acquisition_forms WHERE id = ?");
        $stmt->execute([$acquisition_id]);
        $acquisition = $stmt->fetch();
        
        if (!$acquisition) {
            header("Location: error.php?error=not_found&message=Acquisition record not found");
            exit;
        }
        
    } catch (PDOException $e) {
        error_log("Database error in acquisition-success.php: " . $e->getMessage());
        header("Location: error.php?error=db&message=Database error");
        exit;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Acquisition Completed Successfully</title>
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
        
        .success-container {
            background-color: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            text-align: center;
            max-width: 800px;
            margin: 0 auto;
        }
        
        .success-icon {
            font-size: 64px;
            color: #2ecc71;
            margin-bottom: 20px;
        }
        
        .success-title {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #2d3436;
        }
        
        .acquisition-details {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin: 25px 0;
            text-align: left;
        }
        
        .details-row {
            display: flex;
            margin-bottom: 10px;
            border-bottom: 1px solid #e9ecef;
            padding-bottom: 10px;
        }
        
        .details-label {
            flex: 0 0 180px;
            font-weight: 500;
            color: #495057;
        }
        
        .details-value {
            flex: 1;
        }
        
        .actions {
            margin-top: 30px;
        }
        
        .btn {
            padding: 10px 20px;
            margin: 0 5px;
            border-radius: 4px;
            border: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-block;
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
        
        .btn-outline {
            background-color: transparent;
            border: 1px solid #6c757d;
            color: #6c757d;
        }
        
        .btn-outline:hover {
            background-color: #f8f9fa;
        }
        
        .print-header {
            display: none;
        }
        
        @media print {
            body {
                background-color: white;
                color: black;
                margin: 0;
                padding: 0;
            }
            
            .header-bar, .breadcrumb, .actions {
                display: none;
            }
            
            .print-header {
                display: block;
                text-align: center;
                margin-bottom: 20px;
                padding-bottom: 15px;
                border-bottom: 1px solid #ddd;
            }
            
            .print-header h1 {
                margin: 0;
                font-size: 18pt;
            }
            
            .success-container {
                box-shadow: none;
                border: none;
                padding: 0;
                margin: 0;
                width: 100%;
                max-width: 100%;
            }
            
            .success-icon, .success-title {
                display: none;
            }
            
            .acquisition-details {
                background-color: white;
                padding: 0;
                margin: 0;
            }
        }
    </style>
</head>
<body>

<div class="header-bar">
    <h1>Forensic Acquisition Form</h1>
    <div class="user-info">
        <span>Current Date and Time (UTC - YYYY-MM-DD HH:MM:SS formatted): <?= CURRENT_TIMESTAMP ?></span>
        <span>Current User's Login: <?= CURRENT_USER ?></span>
    </div>
</div>

<div class="breadcrumb">
    <a href="dashboard.php">Dashboard</a> / 
    <a href="coc.php">Chain of Custody</a> /
    <a href="acquisition-form.php">Acquisition Form</a> /
    <span>Success</span>
</div>

<div class="success-container">
    <div class="print-header">
        <h1>Forensic Acquisition Report</h1>
        <p>Case ID: <?= htmlspecialchars($acquisition['case_id'] ?? '') ?></p>
        <p>Generated: <?= CURRENT_TIMESTAMP ?></p>
    </div>
    
    <i class="fas fa-check-circle success-icon"></i>
    <h2 class="success-title">Acquisition Data Saved Successfully!</h2>
    
    <p>Your forensic acquisition information has been successfully saved to the system. Below is a summary of the key details:</p>
    
    <div class="acquisition-details">
        <div class="details-row">
            <div class="details-label">Case ID:</div>
            <div class="details-value"><?= htmlspecialchars($acquisition['case_id'] ?? '') ?></div>
        </div>
        
        <div class="details-row">
            <div class="details-label">Investigator:</div>
            <div class="details-value"><?= htmlspecialchars($acquisition['investigator_name'] ?? '') ?></div>
        </div>
        
        <div class="details-row">
            <div class="details-label">Acquisition Date:</div>
            <div class="details-value"><?= htmlspecialchars($acquisition['acquisition_date'] ?? '') ?></div>
        </div>
        
        <div class="details-row">
            <div class="details-label">Device:</div>
            <div class="details-value"><?= htmlspecialchars($acquisition['make_model'] ?? '') ?> (<?= htmlspecialchars($acquisition['serial_number'] ?? '') ?>)</div>
        </div>
        
        <div class="details-row">
            <div class="details-label">Imaging Tool:</div>
            <div class="details-value"><?= htmlspecialchars($acquisition['imaging_tool'] ?? '') ?> <?= htmlspecialchars($acquisition['tool_version'] ?? '') ?></div>
        </div>
        
        <div class="details-row">
            <div class="details-label">Acquisition Start:</div>
            <div class="details-value"><?= htmlspecialchars($acquisition['start_time'] ?? '') ?></div>
        </div>
        
        <div class="details-row">
            <div class="details-label">Acquisition End:</div>
            <div class="details-value"><?= htmlspecialchars($acquisition['end_time'] ?? '') ?></div>
        </div>
        
        <div class="details-row">
            <div class="details-label">Verification:</div>
            <div class="details-value"><?= htmlspecialchars($acquisition['verification'] ?? '') ?></div>
        </div>
        
        <div class="details-row">
            <div class="details-label">Hash Match:</div>
            <div class="details-value"><?= htmlspecialchars($acquisition['hash_match'] ?? '') ?></div>
        </div>
        
        <?php if (!empty($acquisition['original_hash'])): ?>
        <div class="details-row">
            <div class="details-label">Original Hash (<?= htmlspecialchars($acquisition['hash_type'] ?? '') ?>):</div>
            <div class="details-value"><?= htmlspecialchars($acquisition['original_hash'] ?? '') ?></div>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($acquisition['uploaded_log_file'])): ?>
        <div class="details-row">
            <div class="details-label">Uploaded Log File:</div>
            <div class="details-value"><?= htmlspecialchars($acquisition['uploaded_log_file'] ?? '') ?></div>
        </div>
        <?php endif; ?>
    </div>
    
    <p>The acquisition record has been assigned ID: <strong><?= $acquisition_id ?></strong></p>
    
    <div class="actions">
        <a href="acquisition-form.php" class="btn btn-outline">
            <i class="fas fa-plus"></i> New Acquisition
        </a>
        
        <a href="view-acquisition-form.php?id=<?= $acquisition_id ?>" class="btn btn-primary">
            <i class="fas fa-eye"></i> View Full Details
        </a>
        
        <a href="#" class="btn btn-secondary" onclick="window.print(); return false;">
            <i class="fas fa-print"></i> Print Report
        </a>
        
        <?php if (!empty($acquisition['custody_id'])): ?>
        <a href="view-coc.php?id=<?= htmlspecialchars($acquisition['custody_id']) ?>" class="btn btn-primary">
            <i class="fas fa-link"></i> Return to Chain of Custody
        </a>
        <?php endif; ?>
    </div>
</div>

<script>
    // Optional: Add any JavaScript functionality here
    document.addEventListener('DOMContentLoaded', function() {
        console.log('Acquisition success page loaded');
    });
</script>

</body>
</html>