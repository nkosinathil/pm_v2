<?php
session_start();
define('CURRENT_TIMESTAMP', '2025-06-13 19:57:46');
define('CURRENT_USER', 'nkosinathil');

// Database connection setup
include_once('db-connection.php');

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Capture form inputs - strictly matching the database schema
$case_id              = $_POST['case_id'] ?? '';
$evidence_number      = $_POST['evidence_number'] ?? ''; // Added evidence_number
$unique_description   = $_POST['evidence_number'] ?? $evidence_number ?? ''; // Added unique_description
$investigator_name    = $_POST['investigator_name'] ?? CURRENT_USER;
$acquisition_date     = $_POST['acquisition_date'] ?? date('Y-m-d');
$location             = $_POST['location'] ?? '';
$imaging_tool         = $_POST['imaging_tool'] ?? '';
$device_type          = isset($_POST['device_type']) ? implode(', ', $_POST['device_type']) : '';
$make_model           = $_POST['make_model'] ?? '';
$serial_number        = $_POST['serial_number'] ?? '';
$capacity             = $_POST['capacity'] ?? '';
$interface_type       = isset($_POST['interface_type']) ? implode(', ', $_POST['interface_type']) : '';
$interface_other      = $_POST['interface_other'] ?? '';
$write_blocker        = $_POST['write_blocker'] ?? '';
$write_blocker_reason = $_POST['write_blocker_reason'] ?? '';
$start_time           = $_POST['start_time'] ?? '';
$end_time             = $_POST['end_time'] ?? '';
$tool_version         = $_POST['tool_version'] ?? '';
$imaging_format       = isset($_POST['imaging_format']) ? implode(', ', $_POST['imaging_format']) : '';
$format_other         = $_POST['format_other'] ?? '';
$compression          = $_POST['compression'] ?? '';
$verification         = $_POST['verification'] ?? '';
$notes                = $_POST['notes'] ?? '';
$hash_type            = $_POST['hash_type'] ?? '';
$original_hash        = $_POST['original_hash'] ?? $_POST['md5_hash'] ?? '';
$image_hash           = $_POST['image_hash'] ?? '';
$hash_match           = $_POST['hash_match'] ?? '';
$acquired_by_name     = $_POST['acquired_by_name'] ?? $investigator_name;
$acquired_by_date     = $_POST['acquired_by_date'] ?? date('Y-m-d');
$acquired_by_signature = $_POST['acquired_by_signature'] ?? '';
$received_by_name     = $_POST['received_by_name'] ?? '';
$received_by_date     = $_POST['received_by_date'] ?? '';
$received_by_signature = $_POST['received_by_signature'] ?? '';
$additional_notes     = $_POST['additional_notes'] ?? '';
$uploaded_log_file    = '';

// Device ID/Custody ID from original form
$original_device_id   = $_POST['original_device_id'] ?? 0;
$custody_id           = $_POST['custody_id'] ?? 0;

// Handle file upload if present
if (isset($_FILES['log_file']) && $_FILES['log_file']['error'] === 0) {
    $upload_dir = 'uploads/logs/';
    
    // Create directory if it doesn't exist
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $file_name = basename($_FILES['log_file']['name']);
    $timestamp = date('YmdHis');
    $new_filename = $case_id . '_' . $timestamp . '_' . $file_name;
    $upload_path = $upload_dir . $new_filename;
    
    if (move_uploaded_file($_FILES['log_file']['tmp_name'], $upload_path)) {
        $uploaded_log_file = $new_filename;
    }
} else if (isset($_FILES['logfile']) && $_FILES['logfile']['error'] === 0) {
    // Handle alternative field name for log file
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

// First, check if we need to alter the table to add evidence_number and unique_description columns
try {
    // Check if evidence_number column exists
    $evidence_number_exists = false;
    $unique_description_exists = false;
    
    $columnsQuery = $pdo->query("SHOW COLUMNS FROM acquisition_forms");
    $columns = $columnsQuery->fetchAll(PDO::FETCH_COLUMN);
    
    if (in_array('evidence_number', $columns)) {
        $evidence_number_exists = true;
    }
    
    if (in_array('unique_description', $columns)) {
        $unique_description_exists = true;
    }
    
    // Alter table if columns don't exist
    if (!$evidence_number_exists) {
        $pdo->exec("ALTER TABLE acquisition_forms ADD COLUMN evidence_number VARCHAR(255) AFTER case_id");
    }
    
    if (!$unique_description_exists) {
        $pdo->exec("ALTER TABLE acquisition_forms ADD COLUMN unique_description TEXT AFTER evidence_number");
    }
} catch (PDOException $e) {
    error_log("Error checking/modifying table structure: " . $e->getMessage());
    // Continue anyway, if we can't add columns we'll just ignore those fields
}

// Now prepare SQL statement based on what columns exist
$columnsQuery = $pdo->query("SHOW COLUMNS FROM acquisition_forms");
$columns = $columnsQuery->fetchAll(PDO::FETCH_COLUMN);

$fields = ['case_id'];
$placeholders = [':case_id'];
$params = [':case_id' => $case_id];

// Conditionally add evidence_number and unique_description
if (in_array('evidence_number', $columns)) {
    $fields[] = 'evidence_number';
    $placeholders[] = ':evidence_number';
    $params[':evidence_number'] = $evidence_number;
}

if (in_array('unique_description', $columns)) {
    $fields[] = 'unique_description';
    $placeholders[] = ':unique_description';
    $params[':unique_description'] = $unique_description;
}

// Add all other standard fields
$standardFields = [
    'investigator_name', 'acquisition_date', 'location', 'imaging_tool',
    'device_type', 'make_model', 'serial_number', 'capacity', 
    'interface_type', 'interface_other', 'write_blocker', 'write_blocker_reason',
    'start_time', 'end_time', 'tool_version', 'imaging_format', 'format_other',
    'compression', 'verification', 'notes', 'hash_type', 'original_hash',
    'image_hash', 'hash_match', 'acquired_by_name', 'acquired_by_date',
    'acquired_by_signature', 'received_by_name', 'received_by_date',
    'received_by_signature', 'additional_notes', 'uploaded_log_file'
];

$standardValues = [
    ':investigator_name' => $investigator_name,
    ':acquisition_date' => $acquisition_date,
    ':location' => $location,
    ':imaging_tool' => $imaging_tool,
    ':device_type' => $device_type,
    ':make_model' => $make_model,
    ':serial_number' => $serial_number,
    ':capacity' => $capacity,
    ':interface_type' => $interface_type,
    ':interface_other' => $interface_other,
    ':write_blocker' => $write_blocker,
    ':write_blocker_reason' => $write_blocker_reason,
    ':start_time' => $start_time,
    ':end_time' => $end_time,
    ':tool_version' => $tool_version,
    ':imaging_format' => $imaging_format,
    ':format_other' => $format_other,
    ':compression' => $compression,
    ':verification' => $verification,
    ':notes' => $notes,
    ':hash_type' => $hash_type,
    ':original_hash' => $original_hash,
    ':image_hash' => $image_hash,
    ':hash_match' => $hash_match,
    ':acquired_by_name' => $acquired_by_name,
    ':acquired_by_date' => $acquired_by_date,
    ':acquired_by_signature' => $acquired_by_signature,
    ':received_by_name' => $received_by_name,
    ':received_by_date' => $received_by_date,
    ':received_by_signature' => $received_by_signature,
    ':additional_notes' => $additional_notes,
    ':uploaded_log_file' => $uploaded_log_file
];

// Add standard fields that exist in the table
foreach ($standardFields as $field) {
    if (in_array($field, $columns)) {
        $fields[] = $field;
        $placeholders[] = ':' . $field;
        $params[':' . $field] = $standardValues[':' . $field];
    }
}

// Ensure created_at is included
if (in_array('created_at', $columns)) {
    $fields[] = 'created_at';
    $placeholders[] = 'NOW()';
}

// Build dynamic SQL query
$sql = "INSERT INTO acquisition_forms (" . implode(", ", $fields) . ") 
        VALUES (" . implode(", ", $placeholders) . ")";

try {
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute($params);
    
    // If we have a device_id and custody_id, update the device status in custody_devices
    if (!empty($original_device_id) && !empty($custody_id)) {
        // Check if the custody_devices table exists
        $tableExists = $pdo->query("SHOW TABLES LIKE 'custody_devices'")->rowCount() > 0;
        
        if ($tableExists) {
            // Check if the table has the required columns
            try {
                $deviceColumns = [];
                $deviceColumnsQuery = $pdo->query("SHOW COLUMNS FROM custody_devices");
                while ($column = $deviceColumnsQuery->fetch(PDO::FETCH_ASSOC)) {
                    $deviceColumns[] = $column['Field'];
                }
                
                // Only update if the table has the required columns
                if (in_array('status', $deviceColumns) && in_array('acquired_date', $deviceColumns) && in_array('acquired_by', $deviceColumns)) {
                    $deviceUpdate = $pdo->prepare("UPDATE custody_devices SET status = 'Acquired', 
                        acquired_date = :acquired_date, acquired_by = :acquired_by 
                        WHERE id = :device_id");
                    $deviceUpdate->execute([
                        ':acquired_date' => $acquisition_date,
                        ':acquired_by' => $investigator_name,
                        ':device_id' => $original_device_id
                    ]);
                }
            } catch (PDOException $e) {
                error_log("Error updating custody_devices status: " . $e->getMessage());
            }
        }
    }
    
    // Get the ID of the inserted acquisition record
    $acquisition_id = $pdo->lastInsertId();
    
    // Log the successful operation
    error_log("Acquisition record #$acquisition_id saved successfully for case $case_id by $investigator_name (evidence: $evidence_number)");
    
    // Redirect to success page
    header("Location: acquisition-success.php?id=" . $acquisition_id);
    exit;
    
} catch (PDOException $e) {
    // Log the error and show a user-friendly message
    error_log("Database error in save_acquisition.php: " . $e->getMessage());
    
    // Store form data in session to repopulate the form
    $_SESSION['form_data'] = $_POST;
    
    // Redirect back to form with error
    header("Location: acquisition-form.php?error=db&msg=" . urlencode($e->getMessage()));
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Saving Acquisition Data...</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            margin: 0;
            padding: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background-color: #f5f6fa;
        }
        .loading-container {
            text-align: center;
        }
        .loading-spinner {
            border: 8px solid #f3f3f3;
            border-top: 8px solid #3498db;
            border-radius: 50%;
            width: 60px;
            height: 60px;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="loading-container">
        <div class="loading-spinner"></div>
        <h2>Saving Acquisition Data...</h2>
        <p>Please wait while we process your submission.</p>
    </div>
</body>
</html>