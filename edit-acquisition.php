<?php
session_start();
define('CURRENT_TIMESTAMP', '2025-06-13 20:19:34');
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
$success_message = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Extract form data
        $case_id = $_POST['case_id'] ?? '';
        $evidence_number = $_POST['evidence_number'] ?? '';
        $unique_description = $_POST['unique_description'] ?? $evidence_number ?? '';
        $investigator_name = $_POST['investigator_name'] ?? CURRENT_USER;
        $acquisition_date = $_POST['acquisition_date'] ?? date('Y-m-d');
        $location = $_POST['location'] ?? '';
        $imaging_tool = $_POST['imaging_tool'] ?? '';
        $device_type = isset($_POST['device_type']) ? implode(', ', $_POST['device_type']) : '';
        $make_model = $_POST['make_model'] ?? '';
        $serial_number = $_POST['serial_number'] ?? '';
        $capacity = $_POST['capacity'] ?? '';
        $interface_type = isset($_POST['interface_type']) ? implode(', ', $_POST['interface_type']) : '';
        $interface_other = $_POST['interface_other'] ?? '';
        $write_blocker = $_POST['write_blocker'] ?? '';
        $write_blocker_reason = $_POST['write_blocker_reason'] ?? '';
        $start_time = $_POST['start_time'] ?? '';
        $end_time = $_POST['end_time'] ?? '';
        $tool_version = $_POST['tool_version'] ?? '';
        $imaging_format = isset($_POST['imaging_format']) ? implode(', ', $_POST['imaging_format']) : '';
        $format_other = $_POST['format_other'] ?? '';
        $compression = $_POST['compression'] ?? '';
        $verification = $_POST['verification'] ?? '';
        $notes = $_POST['notes'] ?? '';
        $hash_type = $_POST['hash_type'] ?? '';
        $original_hash = $_POST['original_hash'] ?? $_POST['md5_hash'] ?? '';
        $image_hash = $_POST['image_hash'] ?? '';
        $hash_match = $_POST['hash_match'] ?? '';
        $acquired_by_name = $_POST['acquired_by_name'] ?? $investigator_name;
        $acquired_by_date = $_POST['acquired_by_date'] ?? date('Y-m-d');
        $acquired_by_signature = $_POST['acquired_by_signature'] ?? '';
        $received_by_name = $_POST['received_by_name'] ?? '';
        $received_by_date = $_POST['received_by_date'] ?? '';
        $received_by_signature = $_POST['received_by_signature'] ?? '';
        $additional_notes = $_POST['additional_notes'] ?? '';

        // Check for uploaded log file
        $uploaded_log_file = null;
        if (isset($_FILES['logfile']) && $_FILES['logfile']['error'] === 0) {
            $upload_dir = 'uploads/logs/';
            
            // Create directory if it doesn't exist
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_name = basename($_FILES['logfile']['name']);
            $timestamp = date('YmdHis');
            $new_filename = $case_id . '_' . $timestamp . '_' . $file_name;
            $upload_path = $upload_dir . $new_filename;
            
            if (move_uploaded_file($_FILES['logfile']['tmp_name'], $upload_path)) {
                $uploaded_log_file = $new_filename;
            }
        }

        // Build SQL query dynamically based on table structure
        try {
            // Get current table structure
            $columns = $pdo->query("SHOW COLUMNS FROM acquisition_forms")->fetchAll(PDO::FETCH_COLUMN);

            // Build update statement
            $updateFields = [];
            $params = [];

            // Core fields
            $fields = [
                'case_id' => $case_id,
                'investigator_name' => $investigator_name, 
                'acquisition_date' => $acquisition_date,
                'location' => $location,
                'imaging_tool' => $imaging_tool,
                'device_type' => $device_type,
                'make_model' => $make_model,
                'serial_number' => $serial_number,
                'capacity' => $capacity,
                'interface_type' => $interface_type,
                'interface_other' => $interface_other,
                'write_blocker' => $write_blocker,
                'write_blocker_reason' => $write_blocker_reason,
                'start_time' => $start_time,
                'end_time' => $end_time,
                'tool_version' => $tool_version,
                'imaging_format' => $imaging_format,
                'format_other' => $format_other,
                'compression' => $compression, 
                'verification' => $verification,
                'notes' => $notes,
                'hash_type' => $hash_type,
                'original_hash' => $original_hash,
                'image_hash' => $image_hash,
                'hash_match' => $hash_match,
                'acquired_by_name' => $acquired_by_name,
                'acquired_by_date' => $acquired_by_date,
                'acquired_by_signature' => $acquired_by_signature,
                'received_by_name' => $received_by_name,
                'received_by_date' => $received_by_date,
                'received_by_signature' => $received_by_signature,
                'additional_notes' => $additional_notes
            ];
            
            // Add evidence_number and unique_description if they exist
            if (in_array('evidence_number', $columns)) {
                $fields['evidence_number'] = $evidence_number;
            }
            
            if (in_array('unique_description', $columns)) {
                $fields['unique_description'] = $unique_description;
            }

            // Only include fields that exist in table
            foreach ($fields as $key => $value) {
                if (in_array($key, $columns)) {
                    $updateFields[] = "$key = :$key";
                    $params[":$key"] = $value;
                }
            }

            // Add log file if uploaded
            if ($uploaded_log_file !== null && in_array('uploaded_log_file', $columns)) {
                $updateFields[] = "uploaded_log_file = :uploaded_log_file";
                $params[":uploaded_log_file"] = $uploaded_log_file;
            }

            // Add updated timestamp
            if (in_array('updated_at', $columns)) {
                $updateFields[] = "updated_at = NOW()";
            }

            // Build and execute query
            $sql = "UPDATE acquisition_forms SET " . implode(', ', $updateFields) . " WHERE id = :id";
            $params[":id"] = $acquisition_id;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $success_message = "Acquisition record has been successfully updated.";
            
            // Reload acquisition data after update
            $stmt = $pdo->prepare("SELECT * FROM acquisition_forms WHERE id = ?");
            $stmt->execute([$acquisition_id]);
            $acquisition = $stmt->fetch();
            
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
            error_log("Error in edit-acquisition.php update: " . $e->getMessage());
        }
    } else {
        // Fetch acquisition record for display
        $stmt = $pdo->prepare("SELECT * FROM acquisition_forms WHERE id = ?");
        $stmt->execute([$acquisition_id]);
        $acquisition = $stmt->fetch();
        
        if (!$acquisition) {
            $error_message = "Acquisition record #$acquisition_id not found.";
        }
    }
} catch (PDOException $e) {
    $error_message = "Database error: " . $e->getMessage();
    error_log("Error in edit-acquisition.php: " . $e->getMessage());
}

// Helper for checkbox or radio
function isSelected($expected, $actual) {
    if (is_null($actual)) return '';
    return $expected === $actual ? 'checked' : '';
}

function isChecked($array, $value) {
    if (empty($array)) return '';
    $items = is_array($array) ? $array : explode(', ', $array);
    return in_array($value, $items) ? 'checked' : '';
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
    <title>Edit Acquisition Record</title>
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
            display: inline-flex;
            align-items: center;
            gap: 8px;
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
            display: inline-flex;
            align-items: center;
            gap: 8px;
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
        
        .current-file {
            padding: 10px;
            background-color: #f8f9fa;
            border-radius: 4px;
            margin-top: 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .file-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .file-icon {
            color: #007bff;
            font-size: 24px;
        }
        
        .file-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn-xs {
            padding: 3px 8px;
            font-size: 0.85em;
        }
        
        .required {
            color: #e03131;
        }
        
        .hash-container {
            font-family: monospace;
            background-color: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
            margin-top: 5px;
            word-break: break-all;
        }
        
        @media (max-width: 768px) {
            .checkbox-group, .radio-group {
                flex-direction: column;
                gap: 5px;
            }
            
            .form-col {
                flex: 0 0 100%;
            }
            
            .form-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>

<div class="header-bar">
    <h1>Edit Acquisition Record</h1>
    <div class="user-info">
        <span>Current Date and Time (UTC - YYYY-MM-DD HH:MM:SS formatted): <?= CURRENT_TIMESTAMP ?></span>
        <span>Current User's Login: <?= CURRENT_USER ?></span>
    </div>
</div>

<div class="breadcrumb">
    <a href="dashboard.php">Dashboard</a> / 
    <a href="coc.php">Chain of Custody</a> /
    <a href="view-acquisition-forms.php">Acquisition Forms</a> /
    <a href="view-acquisition.php?id=<?= $acquisition_id ?>">View Details</a> /
    <span>Edit</span>
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

<?php if (!empty($acquisition)): ?>
<div class="form-container">
    <!-- Form tabs navigation -->
    <div class="section-tabs">
        <div class="section-tab active" data-section="case-info">1. Case Information</div>
        <div class="section-tab" data-section="device-info">2. Device Information</div>
        <div class="section-tab" data-section="imaging-details">3. Imaging Details</div>
        <div class="section-tab" data-section="hash-verification">4. Hash Verification</div>
        <div class="section-tab" data-section="acquisition-details">5. Acquisition Details</div>
        <div class="section-tab" data-section="additional-notes">6. Additional Notes</div>
    </div>

    <form method="POST" enctype="multipart/form-data">
        <!-- Section 1: Case Information -->
        <div class="form-section active" id="case-info">
            <h2>1. Case Information</h2>
            <div class="card">
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Case Number <span class="required">*</span></label>
                            <input type="text" name="case_id" value="<?= htmlspecialchars($acquisition['case_id'] ?? '') ?>" required>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Evidence Number</label>
                            <input type="text" name="evidence_number" value="<?= htmlspecialchars($acquisition['evidence_number'] ?? '') ?>" id="evidence_number">
                            <!-- Hidden field for unique_description -->
                            <input type="hidden" name="unique_description" value="<?= htmlspecialchars($acquisition['unique_description'] ?? $acquisition['evidence_number'] ?? '') ?>" id="unique_description">
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Location of Acquisition</label>
                            <input type="text" name="location" value="<?= htmlspecialchars($acquisition['location'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Investigator Name <span class="required">*</span></label>
                            <input type="text" name="investigator_name" value="<?= htmlspecialchars($acquisition['investigator_name'] ?? CURRENT_USER) ?>" required>
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Date of Acquisition <span class="required">*</span></label>
                            <input type="date" name="acquisition_date" value="<?= htmlspecialchars($acquisition['acquisition_date'] ?? date('Y-m-d')) ?>" required>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Imaging Tool Used <span class="required">*</span></label>
                            <input type="text" name="imaging_tool" value="<?= htmlspecialchars($acquisition['imaging_tool'] ?? 'FTK Imager') ?>" required>
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Tool Version</label>
                            <input type="text" name="tool_version" value="<?= htmlspecialchars($acquisition['tool_version'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-col">
                        <?php if (!empty($acquisition['uploaded_log_file'])): ?>
                        <div class="form-group">
                            <label class="form-label">Current Log File</label>
                            <div class="current-file">
                                <div class="file-info">
                                    <i class="fas fa-file-alt file-icon"></i>
                                    <span><?= htmlspecialchars($acquisition['uploaded_log_file']) ?></span>
                                </div>
                                <div class="file-actions">
                                    <a href="uploads/logs/<?= htmlspecialchars($acquisition['uploaded_log_file']) ?>" download class="btn btn-outline btn-xs">
                                        <i class="fas fa-download"></i> Download
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="form-group">
                            <label class="form-label">Upload New Log File</label>
                            <input type="file" name="logfile" class="form-control">
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="form-buttons">
                <a href="view-acquisition.php?id=<?= $acquisition_id ?>" class="cancel-button">
                    <i class="fas fa-times"></i> Cancel
                </a>
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
                                <label><input type="checkbox" name="device_type[]" value="HDD" <?= isChecked($acquisition['device_type'] ?? [], 'HDD') ?>> HDD</label>
                                <label><input type="checkbox" name="device_type[]" value="SSD" <?= isChecked($acquisition['device_type'] ?? [], 'SSD') ?>> SSD</label>
                                <label><input type="checkbox" name="device_type[]" value="USB" <?= isChecked($acquisition['device_type'] ?? [], 'USB') ?>> USB</label>
                                <label><input type="checkbox" name="device_type[]" value="Mobile" <?= isChecked($acquisition['device_type'] ?? [], 'Mobile') ?>> Mobile</label>
                                <label><input type="checkbox" name="device_type[]" value="Other" <?= isChecked($acquisition['device_type'] ?? [], 'Other') ?>> Other:</label>
                            </div>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Make & Model <span class="required">*</span></label>
                            <input type="text" name="make_model" value="<?= htmlspecialchars($acquisition['make_model'] ?? '') ?>" required>
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Serial Number</label>
                            <input type="text" name="serial_number" value="<?= htmlspecialchars($acquisition['serial_number'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Capacity</label>
                            <input type="text" name="capacity" value="<?= htmlspecialchars($acquisition['capacity'] ?? '') ?>">
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Interface Type</label>
                            <div class="checkbox-group">
                                <label><input type="checkbox" name="interface_type[]" value="SATA" <?= isChecked($acquisition['interface_type'] ?? [], 'SATA') ?>> SATA</label>
                                <label><input type="checkbox" name="interface_type[]" value="USB" <?= isChecked($acquisition['interface_type'] ?? [], 'USB') ?>> USB</label>
                                <label><input type="checkbox" name="interface_type[]" value="NVMe" <?= isChecked($acquisition['interface_type'] ?? [], 'NVMe') ?>> NVMe</label>
                                <label><input type="checkbox" name="interface_type[]" value="IDE" <?= isChecked($acquisition['interface_type'] ?? [], 'IDE') ?>> IDE</label>
                                <label><input type="checkbox" name="interface_type[]" value="Other" <?= isChecked($acquisition['interface_type'] ?? [], 'Other') ?>> Other:</label>
                            </div>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Interface (Other)</label>
                            <input type="text" name="interface_other" value="<?= htmlspecialchars($acquisition['interface_other'] ?? '') ?>">
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Write Blocker Used <span class="required">*</span></label>
                            <div class="radio-group">
                                <label><input type="radio" name="write_blocker" value="Yes" <?= isSelected('Yes', $acquisition['write_blocker'] ?? 'Yes') ?>> Yes</label>
                                <label><input type="radio" name="write_blocker" value="No" <?= isSelected('No', $acquisition['write_blocker'] ?? '') ?>> No</label>
                            </div>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Reason (if No)</label>
                            <input type="text" name="write_blocker_reason" value="<?= htmlspecialchars($acquisition['write_blocker_reason'] ?? '') ?>">
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
                            <input type="time" name="start_time" value="<?= htmlspecialchars($acquisition['start_time'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Imaging End Time</label>
                            <input type="time" name="end_time" value="<?= htmlspecialchars($acquisition['end_time'] ?? '') ?>">
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Imaging Format</label>
                            <div class="checkbox-group">
                                <label><input type="checkbox" name="imaging_format[]" value="E01" <?= isChecked($acquisition['imaging_format'] ?? [], 'E01') ?>> E01</label>
                                <label><input type="checkbox" name="imaging_format[]" value="DD" <?= isChecked($acquisition['imaging_format'] ?? [], 'DD') ?>> DD</label>
                                <label><input type="checkbox" name="imaging_format[]" value="AFF" <?= isChecked($acquisition['imaging_format'] ?? [], 'AFF') ?>> AFF</label>
                                <label><input type="checkbox" name="imaging_format[]" value="Other" <?= isChecked($acquisition['imaging_format'] ?? [], 'Other') ?>> Other:</label>
                            </div>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Format (Other)</label>
                            <input type="text" name="format_other" value="<?= htmlspecialchars($acquisition['format_other'] ?? '') ?>">
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Compression Enabled</label>
                            <div class="radio-group">
                                <label><input type="radio" name="compression" value="Yes" <?= isSelected('Yes', $acquisition['compression'] ?? 'Yes') ?>> Yes</label>
                                <label><input type="radio" name="compression" value="No" <?= isSelected('No', $acquisition['compression'] ?? '') ?>> No</label>
                            </div>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Verification Performed</label>
                            <div class="radio-group">
                                <label><input type="radio" name="verification" value="Yes" <?= isSelected('Yes', $acquisition['verification'] ?? 'Yes') ?>> Yes</label>
                                <label><input type="radio" name="verification" value="No" <?= isSelected('No', $acquisition['verification'] ?? '') ?>> No</label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Comments/Notes</label>
                    <textarea name="notes"><?= htmlspecialchars($acquisition['notes'] ?? '') ?></textarea>
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
                    <div class="radio-group">
                        <label><input type="radio" name="hash_type" value="MD5" <?= isSelected('MD5', $acquisition['hash_type'] ?? '') || strpos($acquisition['hash_type'] ?? '', 'MD5') !== false ? 'checked' : '' ?>> MD5</label>
                        <label><input type="radio" name="hash_type" value="SHA1" <?= isSelected('SHA1', $acquisition['hash_type'] ?? '') || (strpos($acquisition['hash_type'] ?? '', 'SHA1') !== false && strpos($acquisition['hash_type'] ?? '', 'MD5') === false) ? 'checked' : '' ?>> SHA1</label>
                        <label><input type="radio" name="hash_type" value="SHA256" <?= isSelected('SHA256', $acquisition['hash_type'] ?? '') || strpos($acquisition['hash_type'] ?? '', 'SHA256') !== false ? 'checked' : '' ?>> SHA256</label>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">MD5 Hash</label>
                            <input type="text" name="md5_hash" value="<?= htmlspecialchars($acquisition['original_hash'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Image MD5 Hash</label>
                            <input type="text" name="image_hash" value="<?= htmlspecialchars($acquisition['image_hash'] ?? '') ?>">
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Hashes Match?</label>
                    <div class="radio-group">
                        <label><input type="radio" name="hash_match" value="Yes" <?= isSelected('Yes', $acquisition['hash_match'] ?? 'Yes') ?>> Yes</label>
                        <label><input type="radio" name="hash_match" value="No" <?= isSelected('No', $acquisition['hash_match'] ?? '') ?>> No</label>
                    </div>
                </div>
                
                <!-- Add hidden field for original_hash to maintain compatibility -->
                <input type="hidden" name="original_hash" value="<?= htmlspecialchars($acquisition['original_hash'] ?? '') ?>" id="original_hash">
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
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Acquired By Name</label>
                            <input type="text" name="acquired_by_name" value="<?= htmlspecialchars($acquisition['acquired_by_name'] ?? $acquisition['investigator_name'] ?? CURRENT_USER) ?>">
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Acquired By Date</label>
                            <input type="date" name="acquired_by_date" value="<?= htmlspecialchars($acquisition['acquired_by_date'] ?? $acquisition['acquisition_date'] ?? date('Y-m-d')) ?>">
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Acquired By Signature</label>
                    <input type="text" name="acquired_by_signature" value="<?= htmlspecialchars($acquisition['acquired_by_signature'] ?? $acquisition['investigator_name'] ?? CURRENT_USER) ?>">
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Received By Name</label>
                            <input type="text" name="received_by_name" value="<?= htmlspecialchars($acquisition['received_by_name'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label class="form-label">Received By Date</label>
                            <input type="date" name="received_by_date" value="<?= htmlspecialchars($acquisition['received_by_date'] ?? '') ?>">
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Received By Signature</label>
                    <input type="text" name="received_by_signature" value="<?= htmlspecialchars($acquisition['received_by_signature'] ?? '') ?>">
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
                    <textarea name="additional_notes" rows="6"><?= htmlspecialchars($acquisition['additional_notes'] ?? '') ?></textarea>
                </div>
            </div>
            
            <div class="form-buttons">
                <a href="view-acquisition.php?id=<?= $acquisition_id ?>" class="cancel-button">
                    <i class="fas fa-times"></i> Cancel
                </a>
                <button type="button" class="cancel-button prev-section" data-prev="acquisition-details">
                    <i class="fas fa-arrow-left"></i> Previous
                </button>
                <button type="submit" class="submit-button">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>
        </div>
    </form>
</div>
<?php else: ?>
<div class="alert alert-danger">
    <i class="fas fa-exclamation-triangle"></i>
    <?= empty($error_message) ? "Acquisition record not found." : $error_message ?>
</div>

<div class="form-buttons" style="justify-content: center;">
    <a href="view-acquisition-forms.php" class="cancel-button">
        <i class="fas fa-arrow-left"></i> Back to List
    </a>
</div>
<?php endif; ?>

<script>
    document.addEventListener('DOMContentLoaded', function() {
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
        
        // Handle hash type selection and corresponding hash fields
        const hashTypeRadios = document.querySelectorAll('input[name="hash_type"]');
        
        hashTypeRadios.forEach(radio => {
            radio.addEventListener('change', function() {
                const hashType = this.value;
                
                // Update original_hash hidden field based on selected hash type
                const originalHashField = document.querySelector('input[name="original_hash"]');
                const md5HashField = document.querySelector('input[name="md5_hash"]');
                
                if (hashType === 'MD5') {
                    originalHashField.value = md5HashField.value;
                }
            });
        });
        
        // Handle evidence_number and unique_description field synchronization
        const evidenceNumberField = document.querySelector('#evidence_number');
        const uniqueDescriptionField = document.querySelector('#unique_description');
        
        if (evidenceNumberField && uniqueDescriptionField) {
            evidenceNumberField.addEventListener('input', function() {
                uniqueDescriptionField.value = this.value;
            });
        }
    });
</script>

</body>
</html>