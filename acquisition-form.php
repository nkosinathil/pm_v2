<?php
include 'parser-ftk.php';

// Set constants for timestamp and username
define('CURRENT_TIMESTAMP', '2025-06-13 18:20:35');
define('CURRENT_USER', 'nkosinathil');

$parsed_data = [];
$fields = [];
$error_message = '';
$success_message = '';
$log_file_source = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['logfile']) && $_FILES['logfile']['error'] === 0) {
    $file = $_FILES['logfile'];
    
    // Check if the file is a text file
    $allowed_types = ['text/plain', 'application/octet-stream', 'text/x-log'];
    $file_type = mime_content_type($file['tmp_name']);
    
    if (in_array($file_type, $allowed_types) || pathinfo($file['name'], PATHINFO_EXTENSION) === 'txt' || pathinfo($file['name'], PATHINFO_EXTENSION) === 'log') {
        // Read file content
        $file_content = file_get_contents($file['tmp_name']);
        
        // Determine which format the log file is in
        if (strpos($file_content, 'Created By AccessData') !== false || 
            strpos($file_content, 'FTK® Imager') !== false ||
            strpos($file_content, 'FTK Imager') !== false) {
            
            $log_file_source = 'FTK Imager';
            
            // Use the provided parsing function for FTK logs
            list($parsed_data, $fields) = parseFtkImagerLog($file_content);
            
            $success_message = "FTK Imager log file processed successfully!";
        } 
        // Check if it's an ewfacquire/Paladin log
        else if (strpos($file_content, 'Imager Command Line') !== false || strpos($file_content, 'ewfacquire') !== false) {
            $log_file_source = 'Paladin';
            
            // If you have a Paladin parser function, use it here
            if (function_exists('parsePaladinLog')) {
                list($parsed_data, $fields) = parsePaladinLog($file_content);
            } else {
                $error_message = "Paladin log detected, but no parser is available.";
            }
            
            $success_message = "Paladin log file processed successfully!";
        } else {
            $error_message = "Unknown log file format. Please upload an FTK Imager or Paladin log file.";
        }
    } else {
        $error_message = "Invalid file type. Please upload a text log file.";
    }
}

// Helper for checkbox or radio
function isSelected($expected, $actual) {
    return $expected === $actual ? 'checked' : '';
}

function isChecked($array, $value) {
    if (empty($array)) return false;
    return in_array($value, $array) ? 'checked' : '';
}

// Helper function to check if field was extracted
function wasExtracted($field, $fields) {
    return in_array($field, $fields) ? 'class="highlighted-field"' : '';
}

// Initialize form data with defaults or parsed values
$form_data = [
    'case_id' => $parsed_data['case_id'] ?? '',
    'evidence_number' => $parsed_data['evidence_number'] ?? '',
    'unique_description' => $parsed_data['unique_description'] ?? '',
    'investigator_name' => $parsed_data['investigator_name'] ?? CURRENT_USER,
    'acquisition_date' => $parsed_data['acquisition_date'] ?? date('Y-m-d'),
    'location' => $parsed_data['location'] ?? 'Office',
    'imaging_tool' => $parsed_data['imaging_tool'] ?? 'FTK Imager',
    'device_type' => $parsed_data['device_type'] ?? [],
    'device_type_other' => $parsed_data['device_type_other'] ?? '',
    'make_model' => $parsed_data['make_model'] ?? '',
    'serial_number' => $parsed_data['serial_number'] ?? '',
    'capacity' => $parsed_data['capacity'] ?? '',
    'interface_type' => $parsed_data['interface_type'] ?? [],
    'interface_other' => $parsed_data['interface_other'] ?? '',
    'drive_interface' => $parsed_data['drive_interface'] ?? '',
    'write_blocker' => $parsed_data['write_blocker'] ?? 'Yes',
    'write_blocker_reason' => $parsed_data['write_blocker_reason'] ?? '',
    'start_time' => $parsed_data['start_time'] ?? date('H:i'),
    'end_time' => $parsed_data['end_time'] ?? '',
    'verification_start' => $parsed_data['verification_start'] ?? '',
    'verification_end' => $parsed_data['verification_end'] ?? '',
    'tool_version' => $parsed_data['tool_version'] ?? 'FTK Imager 4.5',
    'imaging_format' => $parsed_data['imaging_format'] ?? ['E01'],
    'format_other' => $parsed_data['format_other'] ?? '',
    'segment_count' => $parsed_data['segment_count'] ?? '',
    'compression' => $parsed_data['compression'] ?? 'Yes',
    'verification' => $parsed_data['verification'] ?? 'Yes',
    'notes' => $parsed_data['notes'] ?? '',
    'description' => $parsed_data['description'] ?? '',
    'hash_type' => $parsed_data['hash_type'] ?? 'MD5',
    'md5_hash' => $parsed_data['md5_hash'] ?? '',
    'sha1_hash' => $parsed_data['sha1_hash'] ?? '',
    'sha256_hash' => $parsed_data['sha256_hash'] ?? '',
    'original_hash' => $parsed_data['original_hash'] ?? '',
    'image_hash' => $parsed_data['image_hash'] ?? '',
    'hash_match' => $parsed_data['hash_match'] ?? 'Yes',
    'additional_notes' => $parsed_data['additional_notes'] ?? '',
    'chain_of_custody' => $parsed_data['chain_of_custody'] ?? [],
    'collection_method' => $parsed_data['collection_method'] ?? 'Forensic Image',
    'physical_location' => $parsed_data['physical_location'] ?? '',
    'legal_authority' => $parsed_data['legal_authority'] ?? '',
    'documentation_photos' => $parsed_data['documentation_photos'] ?? 'No',
    'device_state' => $parsed_data['device_state'] ?? 'Powered Off',
    'data_protection' => $parsed_data['data_protection'] ?? [],
    'connection_type' => $parsed_data['connection_type'] ?? [],
];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Forensic Acquisition Form</title>
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
        
        h2 { 
            background-color: #e9ecef; 
            padding: 12px 15px;
            margin-top: 25px;
            margin-bottom: 15px;
            border-radius: 6px;
            font-size: 1.1rem;
            color: #2d3436;
        }
        
        .form-container {
            background-color: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-bottom: 20px; 
        }
        
        td, th { 
            border: 1px solid #e9ecef; 
            padding: 12px 15px; 
            vertical-align: top; 
        }
        
        th {
            background-color: #f8f9fa;
            font-weight: 500;
            color: #495057;
        }
        
        textarea { 
            width: 100%; 
            height: 100px; 
            padding: 10px;
            border: 1px solid #e9ecef;
            border-radius: 4px;
            font-family: 'Inter', sans-serif;
        }
        
        input[type="text"], input[type="date"], input[type="time"], select {
            width: 100%; 
            padding: 8px 10px;
            border: 1px solid #e9ecef;
            border-radius: 4px;
        }
        
        input[type="radio"], input[type="checkbox"] {
            margin-right: 5px;
        }
        
        label {
            margin-right: 15px;
            display: inline-flex;
            align-items: center;
        }
        
        .checkbox-group, .radio-group {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .form-buttons {
            margin-top: 25px;
            display: flex;
            gap: 15px;
            justify-content: flex-end;
        }
        
        .submit-button {
            background-color: #007bff;
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 4px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .submit-button:hover {
            background-color: #0069d9;
            transform: translateY(-2px);
        }
        
        .cancel-button {
            background-color: #e9ecef;
            color: #495057;
            border: none;
            padding: 10px 25px;
            border-radius: 4px;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .cancel-button:hover {
            background-color: #dee2e6;
        }
        
        .alert {
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
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
        
        .log-file-section {
            border: 2px dashed #e9ecef;
            padding: 20px;
            border-radius: 6px;
            margin-bottom: 25px;
            text-align: center;
        }
        
        .log-file-section .drop-area {
            padding: 30px 20px;
            background: #f8f9fa;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .log-file-section .drop-area:hover {
            background: #e9ecef;
        }
        
        .log-file-section p {
            margin: 10px 0;
            color: #495057;
        }
        
        .log-file-section i {
            font-size: 36px;
            color: #adb5bd;
            margin-bottom: 10px;
        }
        
        #file_name {
            margin-top: 10px;
            font-weight: 500;
        }
        
        .extraction-results {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            margin-top: 15px;
            border-left: 3px solid #1098ad;
        }
        
        .extraction-results h4 {
            margin-top: 0;
            margin-bottom: 10px;
            font-size: 1rem;
        }
        
        .extraction-results ul {
            margin: 0;
            padding-left: 20px;
        }
        
        .extraction-results li {
            margin-bottom: 5px;
        }

        .file-format-badges {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 15px;
        }
        
        .file-format-badge {
            background-color: #f8f9fa;
            padding: 10px 15px;
            border-radius: 5px;
            font-size: 0.9em;
            border: 1px solid #e9ecef;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .file-format-badge i {
            font-size: 16px;
        }
        
        .file-format-badge.ftk i {
            color: #1971c2;
        }
        
        .file-format-badge.paladin i {
            color: #2b8a3e;
        }

        .highlighted-field {
            background-color: #e3f2fd;
            border: 1px solid #90caf9;
        }
        
        .hash-match {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.85em;
            font-weight: 500;
            margin-left: 10px;
        }
        
        .hash-match.yes {
            background-color: #d3f9d8;
            color: #2b8a3e;
        }
        
        .hash-match.no {
            background-color: #ffe3e3;
            color: #e03131;
        }
        
        .section-tabs {
            display: flex;
            margin-bottom: 20px;
            border-bottom: 1px solid #e9ecef;
            overflow-x: auto;
            white-space: nowrap;
        }
        
        .section-tab {
            padding: 10px 20px;
            cursor: pointer;
            border: 1px solid transparent;
            border-bottom: none;
            border-radius: 4px 4px 0 0;
            margin-right: 5px;
            font-weight: 500;
            background-color: #f8f9fa;
        }
        
        .section-tab.active {
            border-color: #e9ecef;
            background-color: white;
            border-bottom: 1px solid white;
            margin-bottom: -1px;
        }
        
        .form-section {
            display: none;
        }
        
        .form-section.active {
            display: block;
        }
        
        .form-row {
            display: flex;
            flex-wrap: wrap;
            margin: 0 -10px;
        }
        
        .form-col {
            flex: 1;
            padding: 0 10px;
            min-width: 250px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        
        .card {
            background-color: #fff;
            border-radius: 4px;
            padding: 15px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 15px;
        }
        
        .card-header {
            font-weight: 600;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .btn-icon {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-icon i {
            font-size: 14px;
        }
        
        .info-tooltip {
            display: inline-block;
            margin-left: 5px;
            color: #6c757d;
            cursor: pointer;
        }
        
        .progress-indicator {
            display: flex;
            margin-bottom: 25px;
        }
        
        .progress-step {
            flex: 1;
            text-align: center;
            position: relative;
            padding-bottom: 15px;
        }
        
        .progress-step::after {
            content: '';
            position: absolute;
            height: 2px;
            background-color: #e9ecef;
            top: 25px;
            left: 50%;
            right: -50%;
            z-index: 1;
        }
        
        .progress-step:last-child::after {
            display: none;
        }
        
        .step-number {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background-color: #e9ecef;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 5px;
            position: relative;
            z-index: 2;
        }
        
        .progress-step.active .step-number {
            background-color: #007bff;
            color: white;
        }
        
        .progress-step.completed .step-number {
            background-color: #28a745;
            color: white;
        }
        
        .progress-step.active .step-title,
        .progress-step.completed .step-title {
            font-weight: 600;
        }
        
        .step-title {
            font-size: 0.85em;
            color: #495057;
        }
        
        @media (max-width: 768px) {
            .checkbox-group, .radio-group {
                flex-direction: column;
                gap: 5px;
            }
            
            .file-format-badges {
                flex-direction: column;
                align-items: center;
                gap: 10px;
            }
            
            .form-col {
                flex: 0 0 100%;
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
    <span>Acquisition Form</span>
</div>

<?php if (!empty($error_message)): ?>
<div class="alert alert-danger">
    <i class="fas fa-exclamation-triangle"></i>
    <?= $error_message ?>
</div>
<?php endif; ?>

<?php if (!empty($success_message)): ?>
<div class="alert alert-success">
    <i class="fas fa-check-circle"></i>
    <?= $success_message ?>
</div>
<?php endif; ?>

<div class="form-container">
    <!-- Progress indicator -->
    <div class="progress-indicator">
        <div class="progress-step active">
            <div class="step-number">1</div>
            <div class="step-title">Upload Log</div>
        </div>
        <div class="progress-step <?= !empty($parsed_data) ? 'active' : '' ?>">
            <div class="step-number">2</div>
            <div class="step-title">Review Data</div>
        </div>
        <div class="progress-step">
            <div class="step-number">3</div>
            <div class="step-title">Submit Form</div>
        </div>
    </div>

    <!-- Log File Upload Section -->
    <div class="log-file-section">
        <form method="POST" enctype="multipart/form-data" id="log_upload_form">
            <h3>Upload Forensic Log File</h3>
            <p>Upload a log file to automatically extract case information</p>
            
            <div class="file-format-badges">
                <div class="file-format-badge ftk">
                    <i class="fas fa-file-alt"></i>
                    FTK Imager Log (.txt)
                </div>
                <div class="file-format-badge paladin">
                    <i class="fas fa-file-alt"></i>
                    Paladin Log (.log)
                </div>
            </div>
            
            <div class="drop-area" id="drop_area">
                <i class="fas fa-file-upload"></i>
                <p>Drag & drop your log file here or click to browse</p>
                <input type="file" name="logfile" id="log_file" style="display: none;" accept=".log,.txt">
            </div>
            
            <p id="file_name"></p>
            
            <button type="submit" class="submit-button" style="margin-top: 15px;">
                <i class="fas fa-upload"></i> Process Log File
            </button>
        </form>
        
        <?php if (!empty($parsed_data) && count($parsed_data) > 1): ?>
        <div class="extraction-results">
            <h4>Information Extracted from Log File:</h4>
            <ul>
                <?php if (!empty($log_file_source)): ?>
                <li><strong>Log file type:</strong> <?= $log_file_source ?></li>
                <?php endif; ?>
                
                <?php if(!empty($parsed_data['case_id'])): ?>
                <li><strong>Case Number:</strong> <?= htmlspecialchars($parsed_data['case_id']) ?></li>
                <?php endif; ?>
                
                <?php if(!empty($parsed_data['investigator_name'])): ?>
                <li><strong>Investigator:</strong> <?= htmlspecialchars($parsed_data['investigator_name']) ?></li>
                <?php endif; ?>
                
                <?php if(!empty($parsed_data['acquisition_date'])): ?>
                <li><strong>Acquisition date:</strong> <?= htmlspecialchars($parsed_data['acquisition_date']) ?></li>
                <?php endif; ?>
                
                <?php if(!empty($parsed_data['make_model'])): ?>
                <li><strong>Drive Model:</strong> <?= htmlspecialchars($parsed_data['make_model']) ?></li>
                <?php endif; ?>
                
                <?php if(!empty($parsed_data['drive_interface'])): ?>
                <li><strong>Drive Interface:</strong> <?= htmlspecialchars($parsed_data['drive_interface']) ?></li>
                <?php endif; ?>
                
                <?php if(!empty($parsed_data['capacity'])): ?>
                <li><strong>Capacity:</strong> <?= htmlspecialchars($parsed_data['capacity']) ?></li>
                <?php endif; ?>
                
                <?php if(!empty($parsed_data['hash_type'])): ?>
                <li><strong>Hash type:</strong> <?= htmlspecialchars($parsed_data['hash_type']) ?></li>
                <?php endif; ?>
                
                <?php if(!empty($parsed_data['md5_hash'])): ?>
                <li><strong>MD5 Hash:</strong> <?= htmlspecialchars($parsed_data['md5_hash']) ?></li>
                <?php endif; ?>
                
                <?php if(!empty($parsed_data['sha1_hash'])): ?>
                <li><strong>SHA1 Hash:</strong> <?= htmlspecialchars($parsed_data['sha1_hash']) ?></li>
                <?php endif; ?>
                
                <?php if(!empty($parsed_data['sha256_hash'])): ?>
                <li><strong>SHA256 Hash:</strong> <?= htmlspecialchars($parsed_data['sha256_hash']) ?></li>
                <?php endif; ?>
                
                <?php if(!empty($parsed_data['hash_match'])): ?>
                <li><strong>Hash Verification:</strong> <?= $parsed_data['hash_match'] === 'Yes' ? 'Successful' : 'Failed' ?></li>
                <?php endif; ?>
                
                <?php if(!empty($parsed_data['imaging_tool']) || !empty($parsed_data['tool_version'])): ?>
                <li><strong>Imaging Tool:</strong> <?= htmlspecialchars($parsed_data['tool_version'] ?? $parsed_data['imaging_tool']) ?></li>
                <?php endif; ?>
                
                <?php if(!empty($parsed_data['imaging_format'])): ?>
                <li><strong>Image Format:</strong> <?= is_array($parsed_data['imaging_format']) ? implode(', ', $parsed_data['imaging_format']) : $parsed_data['imaging_format'] ?></li>
                <?php endif; ?>
                
                <?php if(!empty($parsed_data['segment_count'])): ?>
                <li><strong>Number of Segments:</strong> <?= htmlspecialchars($parsed_data['segment_count']) ?></li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($parsed_data)): ?>
    <!-- Form tabs navigation -->
    <div class="section-tabs">
        <div class="section-tab active" data-section="case-info">1. Case Information</div>
        <div class="section-tab" data-section="device-info">2. Device Information</div>
        <div class="section-tab" data-section="imaging-details">3. Imaging Details</div>
        <div class="section-tab" data-section="hash-verification">4. Hash Verification</div>
        <div class="section-tab" data-section="acquisition-details">5. Acquisition Details</div>
        <div class="section-tab" data-section="additional-notes">6. Additional Notes</div>
    </div>
    
    <form method="POST" action="save_acquisition.php">
        <!-- Section 1: Case Information -->
        <div class="form-section active" id="case-info">
            <h2>1. Case Information</h2>
            <div class="card">
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Case Number <span class="required">*</span></label>
                            <input type="text" name="case_id" value="<?= htmlspecialchars($form_data['case_id']) ?>" 
                                <?= wasExtracted('case_id', $fields) ?> required>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Evidence Number</label>
                            <input type="text" name="evidence_number" value="<?= htmlspecialchars($form_data['evidence_number']) ?>"
                                <?= wasExtracted('evidence_number', $fields) ?>>
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Unique Description</label>
                            <input type="text" name="unique_description" value="<?= htmlspecialchars($form_data['unique_description']) ?>"
                                <?= wasExtracted('unique_description', $fields) ?>>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Investigation Type</label>
                            <select name="investigation_type">
                                <option value="Criminal">Criminal</option>
                                <option value="Civil">Civil</option>
                                <option value="Corporate">Corporate</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Investigator Name <span class="required">*</span></label>
                            <input type="text" name="investigator_name" value="<?= htmlspecialchars($form_data['investigator_name']) ?>"
                                <?= wasExtracted('investigator_name', $fields) ?> required>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Date of Acquisition <span class="required">*</span></label>
                            <input type="date" name="acquisition_date" value="<?= htmlspecialchars($form_data['acquisition_date']) ?>"
                                <?= wasExtracted('acquisition_date', $fields) ?> required>
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Location of Acquisition</label>
                            <input type="text" name="location" value="<?= htmlspecialchars($form_data['location']) ?>">
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Legal Authority</label>
                            <select name="legal_authority">
                                <option value="">-- Select --</option>
                                <option value="Search Warrant" <?= $form_data['legal_authority'] == 'Search Warrant' ? 'selected' : '' ?>>Search Warrant</option>
                                <option value="Court Order" <?= $form_data['legal_authority'] == 'Court Order' ? 'selected' : '' ?>>Court Order</option>
                                <option value="Consent" <?= $form_data['legal_authority'] == 'Consent' ? 'selected' : '' ?>>Consent</option>
                                <option value="Corporate Authority" <?= $form_data['legal_authority'] == 'Corporate Authority' ? 'selected' : '' ?>>Corporate Authority</option>
                                <option value="Other" <?= $form_data['legal_authority'] == 'Other' ? 'selected' : '' ?>>Other</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Imaging Tool Used <span class="required">*</span></label>
                            <input type="text" name="imaging_tool" value="<?= htmlspecialchars($form_data['imaging_tool']) ?>"
                                <?= wasExtracted('imaging_tool', $fields) ?> required>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Tool Version</label>
                            <input type="text" name="tool_version" value="<?= htmlspecialchars($form_data['tool_version']) ?>"
                                <?= wasExtracted('tool_version', $fields) ?>>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Physical Location Details</label>
                    <textarea name="physical_location"><?= htmlspecialchars($form_data['physical_location']) ?></textarea>
                </div>
            </div>
            
            <div class="form-buttons">
                <button type="button" class="submit-button next-section" data-next="device-info">
                    Next: Device Information <i class="fas fa-arrow-right"></i>
                </button>
            </div>
        </div>
        
        <!-- Section 2: Device Information -->
        <div class="form-section" id="device-info">
            <h2>2. Evidence Device Information</h2>
            <div class="card">
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Device Type <span class="required">*</span></label>
                            <div class="checkbox-group">
                                <label><input type="checkbox" name="device_type[]" value="HDD" <?= isChecked($form_data['device_type'], 'HDD') ?>> HDD</label>
                                <label><input type="checkbox" name="device_type[]" value="SSD" <?= isChecked($form_data['device_type'], 'SSD') ?>> SSD</label>
                                <label><input type="checkbox" name="device_type[]" value="USB" <?= isChecked($form_data['device_type'], 'USB') ?>> USB</label>
                                <label><input type="checkbox" name="device_type[]" value="Mobile" <?= isChecked($form_data['device_type'], 'Mobile') ?>> Mobile</label>
                                <label><input type="checkbox" name="device_type[]" value="Other" <?= isChecked($form_data['device_type'], 'Other') ?>> Other:</label>
                                <input type="text" name="device_type_other" value="<?= htmlspecialchars($form_data['device_type_other']) ?>" style="width: 150px;">
                            </div>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Device State at Acquisition</label>
                            <div class="radio-group">
                                <label><input type="radio" name="device_state" value="Powered On" <?= isSelected('Powered On', $form_data['device_state']) ?>> Powered On</label>
                                <label><input type="radio" name="device_state" value="Powered Off" <?= isSelected('Powered Off', $form_data['device_state']) ?>> Powered Off</label>
                                <label><input type="radio" name="device_state" value="Sleep/Hibernate" <?= isSelected('Sleep/Hibernate', $form_data['device_state']) ?>> Sleep/Hibernate</label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Make & Model <span class="required">*</span></label>
                            <input type="text" name="make_model" value="<?= htmlspecialchars($form_data['make_model']) ?>"
                                <?= wasExtracted('make_model', $fields) ?> required>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Serial Number</label>
                            <input type="text" name="serial_number" value="<?= htmlspecialchars($form_data['serial_number']) ?>"
                                <?= wasExtracted('serial_number', $fields) ?>>
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Capacity</label>
                            <input type="text" name="capacity" value="<?= htmlspecialchars($form_data['capacity']) ?>"
                                <?= wasExtracted('capacity', $fields) ?>>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Drive Interface</label>
                            <input type="text" name="drive_interface" value="<?= htmlspecialchars($form_data['drive_interface']) ?>"
                                <?= wasExtracted('drive_interface', $fields) ?>>
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Interface Type</label>
                            <div class="checkbox-group">
                                <label><input type="checkbox" name="interface_type[]" value="SATA" <?= isChecked($form_data['interface_type'], 'SATA') ?>> SATA</label>
                                <label><input type="checkbox" name="interface_type[]" value="USB" <?= isChecked($form_data['interface_type'], 'USB') ?>> USB</label>
                                <label><input type="checkbox" name="interface_type[]" value="NVMe" <?= isChecked($form_data['interface_type'], 'NVMe') ?>> NVMe</label>
                                <label><input type="checkbox" name="interface_type[]" value="IDE" <?= isChecked($form_data['interface_type'], 'IDE') ?>> IDE</label>
                                <label><input type="checkbox" name="interface_type[]" value="Other" <?= isChecked($form_data['interface_type'], 'Other') ?>> Other:</label>
                                <input type="text" name="interface_other" value="<?= htmlspecialchars($form_data['interface_other']) ?>" 
                                    <?= wasExtracted('interface_other', $fields) ?> style="width: 150px;">
                            </div>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Connection Type</label>
                            <div class="checkbox-group">
                                <label><input type="checkbox" name="connection_type[]" value="Direct" <?= isChecked($form_data['connection_type'], 'Direct') ?>> Direct</label>
                                <label><input type="checkbox" name="connection_type[]" value="Network" <?= isChecked($form_data['connection_type'], 'Network') ?>> Network</label>
                                <label><input type="checkbox" name="connection_type[]" value="Remote" <?= isChecked($form_data['connection_type'], 'Remote') ?>> Remote</label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Write Blocker Used <span class="required">*</span></label>
                            <div class="radio-group">
                                <label><input type="radio" name="write_blocker" value="Yes" <?= isSelected('Yes', $form_data['write_blocker']) ?>> Yes</label>
                                <label><input type="radio" name="write_blocker" value="No" <?= isSelected('No', $form_data['write_blocker']) ?>> No</label>
                            </div>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Reason (if No)</label>
                            <input type="text" name="write_blocker_reason" value="<?= htmlspecialchars($form_data['write_blocker_reason']) ?>">
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Data Protection Measures</label>
                            <div class="checkbox-group">
                                <label><input type="checkbox" name="data_protection[]" value="Write Blocker" <?= isChecked($form_data['data_protection'], 'Write Blocker') ?>> Write Blocker</label>
                                <label><input type="checkbox" name="data_protection[]" value="Sanitized Media" <?= isChecked($form_data['data_protection'], 'Sanitized Media') ?>> Sanitized Media</label>
                                <label><input type="checkbox" name="data_protection[]" value="Encryption" <?= isChecked($form_data['data_protection'], 'Encryption') ?>> Encryption</label>
                                <label><input type="checkbox" name="data_protection[]" value="Other" <?= isChecked($form_data['data_protection'], 'Other') ?>> Other</label>
                            </div>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Documentation Photos</label>
                            <div class="radio-group">
                                <label><input type="radio" name="documentation_photos" value="Yes" <?= isSelected('Yes', $form_data['documentation_photos']) ?>> Yes</label>
                                <label><input type="radio" name="documentation_photos" value="No" <?= isSelected('No', $form_data['documentation_photos']) ?>> No</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="form-buttons">
                <button type="button" class="cancel-button prev-section" data-prev="case-info">
                    <i class="fas fa-arrow-left"></i> Previous
                </button>
                <button type="button" class="submit-button next-section" data-next="imaging-details">
                    Next: Imaging Details <i class="fas fa-arrow-right"></i>
                </button>
            </div>
        </div>
        
        <!-- Section 3: Imaging Details -->
        <div class="form-section" id="imaging-details">
            <h2>3. Imaging Details</h2>
            <div class="card">
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Imaging Start Time</label>
                            <input type="time" name="start_time" value="<?= htmlspecialchars($form_data['start_time']) ?>"
                                <?= wasExtracted('start_time', $fields) ?>>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Imaging End Time</label>
                            <input type="time" name="end_time" value="<?= htmlspecialchars($form_data['end_time']) ?>"
                                <?= wasExtracted('end_time', $fields) ?>>
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Verification Start Time</label>
                            <input type="time" name="verification_start" value="<?= htmlspecialchars($form_data['verification_start']) ?>"
                                <?= wasExtracted('verification_start', $fields) ?>>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Verification End Time</label>
                            <input type="time" name="verification_end" value="<?= htmlspecialchars($form_data['verification_end']) ?>"
                                <?= wasExtracted('verification_end', $fields) ?>>
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Imaging Format</label>
                            <div class="checkbox-group">
                                <label><input type="checkbox" name="imaging_format[]" value="E01" <?= isChecked($form_data['imaging_format'], 'E01') ?>> E01</label>
                                <label><input type="checkbox" name="imaging_format[]" value="DD" <?= isChecked($form_data['imaging_format'], 'DD') ?>> DD</label>
                                <label><input type="checkbox" name="imaging_format[]" value="AFF" <?= isChecked($form_data['imaging_format'], 'AFF') ?>> AFF</label>
                                <label><input type="checkbox" name="imaging_format[]" value="Other" <?= isChecked($form_data['imaging_format'], 'Other') ?>> Other:</label>
                                <input type="text" name="format_other" value="<?= htmlspecialchars($form_data['format_other']) ?>" style="width: 150px;">
                            </div>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Number of Segments</label>
                            <input type="text" name="segment_count" value="<?= htmlspecialchars($form_data['segment_count']) ?>"
                                <?= wasExtracted('segment_count', $fields) ?>>
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Compression Enabled</label>
                            <div class="radio-group">
                                <label><input type="radio" name="compression" value="Yes" <?= isSelected('Yes', $form_data['compression']) ?>> Yes</label>
                                <label><input type="radio" name="compression" value="No" <?= isSelected('No', $form_data['compression']) ?>> No</label>
                            </div>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Verification Performed</label>
                            <div class="radio-group">
                                <label><input type="radio" name="verification" value="Yes" <?= isSelected('Yes', $form_data['verification']) ?>> Yes</label>
                                <label><input type="radio" name="verification" value="No" <?= isSelected('No', $form_data['verification']) ?>> No</label>
                                <?php if(in_array('hash_match', $fields)): ?>
                                <span class="hash-match <?= strtolower($form_data['hash_match']) ?>">
                                    <i class="fas fa-<?= $form_data['hash_match'] === 'Yes' ? 'check' : 'times' ?>"></i>
                                    <?= strpos($form_data['hash_type'], 'SHA1') !== false || strpos($form_data['hash_type'], 'MD5') !== false ? 
                                        'Hashes verified' : 'Verification ' . ($form_data['hash_match'] === 'Yes' ? 'successful' : 'failed') ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Collection Method</label>
                    <div class="radio-group">
                        <label><input type="radio" name="collection_method" value="Forensic Image" <?= isSelected('Forensic Image', $form_data['collection_method']) ?>> Forensic Image</label>
                        <label><input type="radio" name="collection_method" value="Logical Image" <?= isSelected('Logical Image', $form_data['collection_method']) ?>> Logical Image</label>
                        <label><input type="radio" name="collection_method" value="Live Acquisition" <?= isSelected('Live Acquisition', $form_data['collection_method']) ?>> Live Acquisition</label>
                        <label><input type="radio" name="collection_method" value="Other" <?= isSelected('Other', $form_data['collection_method']) ?>> Other</label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Comments/Notes</label>
                    <textarea name="notes" <?= wasExtracted('notes', $fields) ?>><?= htmlspecialchars($form_data['notes']) ?></textarea>
                </div>
            </div>
            
            <div class="form-buttons">
                <button type="button" class="cancel-button prev-section" data-prev="device-info">
                    <i class="fas fa-arrow-left"></i> Previous
                </button>
                <button type="button" class="submit-button next-section" data-next="hash-verification">
                    Next: Hash Verification <i class="fas fa-arrow-right"></i>
                </button>
            </div>
        </div>
        
        <!-- Section 4: Hash Verification -->
        <div class="form-section" id="hash-verification">
            <h2>4. Hash Verification</h2>
            <div class="card">
                <div class="form-group">
                    <label class="form-label">Hash Type</label>
                    <div class="checkbox-group">
                        <label><input type="radio" name="hash_type" value="MD5" 
                            <?= isSelected('MD5', $form_data['hash_type']) || strpos($form_data['hash_type'], 'MD5') !== false ? 'checked' : '' ?> 
                            <?= in_array('hash_type', $fields) && (strpos($form_data['hash_type'], 'MD5') !== false) ? 'class="highlighted-field"' : '' ?>> MD5</label>
                        <label><input type="radio" name="hash_type" value="SHA1" 
                            <?= isSelected('SHA1', $form_data['hash_type']) || (strpos($form_data['hash_type'], 'SHA1') !== false && strpos($form_data['hash_type'], 'MD5') === false) ? 'checked' : '' ?> 
                            <?= in_array('hash_type', $fields) && (strpos($form_data['hash_type'], 'SHA1') !== false) ? 'class="highlighted-field"' : '' ?>> SHA1</label>
                        <label><input type="radio" name="hash_type" value="SHA256" 
                            <?= isSelected('SHA256', $form_data['hash_type']) || strpos($form_data['hash_type'], 'SHA256') !== false ? 'checked' : '' ?>
                            <?= in_array('hash_type', $fields) && (strpos($form_data['hash_type'], 'SHA256') !== false) ? 'class="highlighted-field"' : '' ?>> SHA256</label>
                        <?php if (strpos($form_data['hash_type'], '+') !== false): ?>
                            <span style="color:#1971c2; font-style:italic; margin-left:10px;">Multiple hash types detected: <?= $form_data['hash_type'] ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">MD5 Hash</label>
                            <input type="text" name="md5_hash" value="<?= htmlspecialchars($form_data['md5_hash'] ?? $form_data['original_hash']) ?>"
                                <?= in_array('md5_hash', $fields) || (in_array('original_hash', $fields) && strpos($form_data['hash_type'], 'MD5') !== false) ? 'class="highlighted-field"' : '' ?>>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Image MD5 Hash</label>
                            <input type="text" name="image_hash" value="<?= htmlspecialchars($form_data['image_hash']) ?>"
                                <?= in_array('image_hash', $fields) ? 'class="highlighted-field"' : '' ?>>
                        </div>
                    </div>
                </div>
                
                <?php if (!empty($form_data['sha1_hash']) || strpos($form_data['hash_type'], 'SHA1') !== false): ?>
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">SHA1 Hash</label>
                            <input type="text" name="sha1_hash" value="<?= htmlspecialchars($form_data['sha1_hash'] ?? '') ?>" 
                                <?= in_array('sha1_hash', $fields) || (strpos($form_data['hash_type'], 'SHA1') !== false && empty($form_data['sha1_hash'])) ? 'class="highlighted-field"' : '' ?>>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Image SHA1 Hash</label>
                            <input type="text" name="sha1_image_hash" value="<?= htmlspecialchars($form_data['sha1_image_hash'] ?? $form_data['image_hash']) ?>"
                                <?= in_array('sha1_image_hash', $fields) || (in_array('image_hash', $fields) && strpos($form_data['hash_type'], 'SHA1') !== false) ? 'class="highlighted-field"' : '' ?>>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($form_data['sha256_hash']) || strpos($form_data['hash_type'], 'SHA256') !== false): ?>
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">SHA256 Hash</label>
                            <input type="text" name="sha256_hash" value="<?= htmlspecialchars($form_data['sha256_hash'] ?? '') ?>" 
                                <?= in_array('sha256_hash', $fields) ? 'class="highlighted-field"' : '' ?>>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Image SHA256 Hash</label>
                            <input type="text" name="sha256_image_hash" value="<?= htmlspecialchars($form_data['sha256_image_hash'] ?? '') ?>"
                                <?= in_array('sha256_image_hash', $fields) ? 'class="highlighted-field"' : '' ?>>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="form-group">
                    <label class="form-label">Hashes Match?</label>
                    <div class="radio-group">
                        <label><input type="radio" name="hash_match" value="Yes" <?= isSelected('Yes', $form_data['hash_match']) ?>> Yes</label>
                        <label><input type="radio" name="hash_match" value="No" <?= isSelected('No', $form_data['hash_match']) ?>> No</label>
                        <?php if(in_array('hash_match', $fields)): ?>
                        <span class="hash-match <?= strtolower($form_data['hash_match']) ?>">
                            <i class="fas fa-<?= $form_data['hash_match'] === 'Yes' ? 'check' : 'times' ?>"></i>
                            <?= $form_data['hash_match'] === 'Yes' ? 'Verified' : 'Verification failed' ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="form-buttons">
                <button type="button" class="cancel-button prev-section" data-prev="imaging-details">
                    <i class="fas fa-arrow-left"></i> Previous
                </button>
                <button type="button" class="submit-button next-section" data-next="acquisition-details">
                    Next: Acquisition Details <i class="fas fa-arrow-right"></i>
                </button>
            </div>
        </div>
        
        <!-- Section 5: Acquisition Details -->
        <div class="form-section" id="acquisition-details">
            <h2>5. Acquisition Details</h2>
            <div class="card">
                <div class="card-header">Environmental Conditions</div>
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Acquisition Environment</label>
                            <select name="acquisition_environment">
                                <option value="Laboratory">Laboratory</option>
                                <option value="Field">Field</option>
                                <option value="Office">Office</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Anti-Static Precautions</label>
                            <div class="radio-group">
                                <label><input type="radio" name="antistatic" value="Yes"> Yes</label>
                                <label><input type="radio" name="antistatic" value="No"> No</label>
                                <label><input type="radio" name="antistatic" value="N/A" checked> N/A</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">Destination Media</div>
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Storage Media Type</label>
                            <select name="storage_media_type">
                                <option value="External HDD">External HDD</option>
                                <option value="NAS">NAS</option>
                                <option value="Server">Server</option>
                                <option value="Tape">Tape</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Storage Media Serial Number</label>
                            <input type="text" name="storage_serial">
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Storage Location & Path</label>
                    <input type="text" name="storage_path">
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">Encryption</div>
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Source Encrypted?</label>
                            <div class="radio-group">
                                <label><input type="radio" name="source_encrypted" value="Yes"> Yes</label>
                                <label><input type="radio" name="source_encrypted" value="No" checked> No</label>
                                <label><input type="radio" name="source_encrypted" value="Unknown"> Unknown</label>
                            </div>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Image Encrypted?</label>
                            <div class="radio-group">
                                <label><input type="radio" name="image_encrypted" value="Yes"> Yes</label>
                                <label><input type="radio" name="image_encrypted" value="No" checked> No</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Encryption Details</label>
                    <textarea name="encryption_details"></textarea>
                </div>
            </div>
            
            <div class="form-buttons">
                <button type="button" class="cancel-button prev-section" data-prev="hash-verification">
                    <i class="fas fa-arrow-left"></i> Previous
                </button>
                                <button type="button" class="submit-button next-section" data-next="additional-notes">
                    Next: Additional Notes <i class="fas fa-arrow-right"></i>
                </button>
            </div>
        </div>
        
        <!-- Section 6: Additional Notes -->
        <div class="form-section" id="additional-notes">
            <h2>6. Additional Notes</h2>
            <div class="card">
                <div class="form-group">
                    <label class="form-label">Additional Notes & Observations</label>
                    <textarea name="additional_notes" rows="6"><?= htmlspecialchars($form_data['additional_notes']) ?></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Issues Encountered</label>
                    <textarea name="issues_encountered" rows="4"></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Deviations from Standard Procedures</label>
                    <div class="radio-group">
                        <label><input type="radio" name="has_deviations" value="Yes"> Yes</label>
                        <label><input type="radio" name="has_deviations" value="No" checked> No</label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Deviation Details</label>
                    <textarea name="deviation_details" rows="4"></textarea>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">Certification</div>
                <div class="form-group">
                    <label class="form-label">I certify that this forensic acquisition was performed according to standard procedures and best practices:</label>
                    <div class="checkbox-group">
                        <label><input type="checkbox" name="certification" value="Yes"> I certify</label>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Digital Signature/Name</label>
                            <input type="text" name="digital_signature" value="<?= htmlspecialchars($form_data['investigator_name']) ?>">
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Date</label>
                            <input type="date" name="signature_date" value="<?= date('Y-m-d') ?>">
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="form-buttons">
                <button type="button" class="cancel-button prev-section" data-prev="acquisition-details">
                    <i class="fas fa-arrow-left"></i> Previous
                </button>
                <button type="submit" class="submit-button">
                    <i class="fas fa-save"></i> Submit Acquisition Form
                </button>
            </div>
        </div>
    </form>
    <?php endif; ?>

</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Drop area functionality
        const dropArea = document.getElementById('drop_area');
        const fileInput = document.getElementById('log_file');
        const fileName = document.getElementById('file_name');
        
        // Trigger file input when clicking on drop area
        dropArea.addEventListener('click', function() {
            fileInput.click();
        });
        
        // Handle file selection
        fileInput.addEventListener('change', function() {
            if (fileInput.files.length > 0) {
                fileName.textContent = 'Selected file: ' + fileInput.files[0].name;
                
                // Auto-submit if file is selected
                document.getElementById('log_upload_form').submit();
            }
        });
        
        // Handle drag and drop
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropArea.addEventListener(eventName, preventDefaults, false);
        });
        
        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }
        
        ['dragenter', 'dragover'].forEach(eventName => {
            dropArea.addEventListener(eventName, highlight, false);
        });
        
        ['dragleave', 'drop'].forEach(eventName => {
            dropArea.addEventListener(eventName, unhighlight, false);
        });
        
        function highlight() {
            dropArea.style.backgroundColor = '#e3f2fd';
        }
        
        function unhighlight() {
            dropArea.style.backgroundColor = '#f8f9fa';
        }
        
        dropArea.addEventListener('drop', handleDrop, false);
        
        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            
            if (files.length > 0) {
                fileInput.files = files;
                fileName.textContent = 'Selected file: ' + files[0].name;
                
                // Auto-submit if file is dropped
                document.getElementById('log_upload_form').submit();
            }
        }
        
        // Section tabs functionality
        const sectionTabs = document.querySelectorAll('.section-tab');
        const formSections = document.querySelectorAll('.form-section');
        
        sectionTabs.forEach(tab => {
            tab.addEventListener('click', function() {
                // Remove active class from all tabs and sections
                sectionTabs.forEach(t => t.classList.remove('active'));
                formSections.forEach(s => s.classList.remove('active'));
                
                // Add active class to clicked tab
                this.classList.add('active');
                
                // Show corresponding section
                const sectionId = this.getAttribute('data-section');
                document.getElementById(sectionId).classList.add('active');
            });
        });
        
        // Next/Previous section buttons
        const nextButtons = document.querySelectorAll('.next-section');
        const prevButtons = document.querySelectorAll('.prev-section');
        
        nextButtons.forEach(button => {
            button.addEventListener('click', function() {
                const nextSectionId = this.getAttribute('data-next');
                
                // Hide all sections
                formSections.forEach(s => s.classList.remove('active'));
                
                // Show next section
                document.getElementById(nextSectionId).classList.add('active');
                
                // Update active tab
                sectionTabs.forEach(t => {
                    t.classList.remove('active');
                    if (t.getAttribute('data-section') === nextSectionId) {
                        t.classList.add('active');
                    }
                });
                
                // Scroll to top
                window.scrollTo(0, 0);
            });
        });
        
        prevButtons.forEach(button => {
            button.addEventListener('click', function() {
                const prevSectionId = this.getAttribute('data-prev');
                
                // Hide all sections
                formSections.forEach(s => s.classList.remove('active'));
                
                // Show previous section
                document.getElementById(prevSectionId).classList.add('active');
                
                // Update active tab
                sectionTabs.forEach(t => {
                    t.classList.remove('active');
                    if (t.getAttribute('data-section') === prevSectionId) {
                        t.classList.add('active');
                    }
                });
                
                // Scroll to top
                window.scrollTo(0, 0);
            });
        });
    });
</script>

</body>
</html>