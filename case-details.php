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

define('CURRENT_TIMESTAMP', '2025-06-14 20:34:26');
define('CURRENT_USER', 'nkosinathil');

include_once('db-connection.php');

$error_message = '';
$success_message = '';

// Get case ID from URL parameter
$case_id = isset($_GET['id']) ? $_GET['id'] : '';

if (empty($case_id)) {
    $error_message = "No case ID provided.";
}

$case_data = [];
$client_data = [];
$devices = [];
$coc_records = [];
$acquisition_forms = [];
$assigned_users = []; // This will remain empty since case_assignments table doesn't exist
$tasks = [];
$case_logs = [];
$activities = []; // Initialize empty array
$attachments = []; // Initialize empty array
$comments = []; // Initialize empty array

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    // Fetch case data
    $stmt = $pdo->prepare("
        SELECT c.*, cl.* 
        FROM cases c
        LEFT JOIN clients cl ON c.client_code = cl.client_code
        WHERE c.case_number = ?
    ");
    $stmt->execute([$case_id]);
    $case_data = $stmt->fetch();
    
    if (!$case_data) {
        $error_message = "Case not found.";
    } else {
        // Fetch devices for this case
        $stmt = $pdo->prepare("
            SELECT * FROM custody_devices 
            WHERE job_code = ? 
            ORDER BY item_number
        ");
        $stmt->execute([$case_id]);
        $devices = $stmt->fetchAll();
        
        // Fetch chain of custody records for this case
        $stmt = $pdo->prepare("
            SELECT * FROM custody_logs 
            WHERE job_code = ? 
            ORDER BY created_at DESC
        ");
        $stmt->execute([$case_id]);
        $coc_records = $stmt->fetchAll();
        
        // Check for acquisition forms
        $stmt = $pdo->prepare("
            SELECT * FROM acquisition_forms 
            WHERE case_id = ? 
            ORDER BY created_at DESC
        ");
        $stmt->execute([$case_id]);
        $acquisition_forms = $stmt->fetchAll();
        
        // Skip fetching assigned users since case_assignments table doesn't exist
        
        // Check if tasks table exists before querying
        $stmt = $pdo->prepare("SHOW TABLES LIKE 'tasks'");
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            // First check the tasks table structure to understand the column names
            $stmt = $pdo->prepare("DESCRIBE tasks");
            $stmt->execute();
            $task_columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Look for case-related column names
            $case_column = null;
            $possible_columns = ['case_id', 'job_code', 'case_number', 'job_number'];
            foreach ($possible_columns as $col) {
                if (in_array($col, $task_columns)) {
                    $case_column = $col;
                    break;
                }
            }
            
            if ($case_column) {
                // Use the found column name in the query
                // Also modify to use 'email' instead of 'username'
                $stmt = $pdo->prepare("
                    SELECT t.*, u.email as assigned_to_name
                    FROM tasks t
                    LEFT JOIN users u ON t.assigned_to = u.id
                    WHERE t.$case_column = ?
                    ORDER BY t.priority DESC, t.due_date ASC
                ");
                
                // Determine what value to use based on the column type
                $param_value = ($case_column === 'job_code' || $case_column === 'case_number') ? $case_id : $case_data['id'];
                $stmt->execute([$param_value]);
                $tasks = $stmt->fetchAll();
            } else {
                // If no matching column found, tasks will remain an empty array
                error_log('Could not find a case-related column in the tasks table');
            }
        }
        
        // Check if audit_logs table exists before querying
        $stmt = $pdo->prepare("SHOW TABLES LIKE 'audit_logs'");
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            // First check the audit_logs table structure
            $stmt = $pdo->prepare("DESCRIBE audit_logs");
            $stmt->execute();
            $log_columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Look for suitable case-related and user-related columns
            $case_id_column = in_array('target_id', $log_columns) ? 'target_id' : 'case_id';
            $user_id_column = in_array('user_id', $log_columns) ? 'user_id' : 'created_by';
            
            // Check if 'details' column exists
            $has_details_column = in_array('details', $log_columns);
            
            // Build query based on available columns
            if ($has_details_column) {
                $query = "
                    SELECT al.*, u.email as username 
                    FROM audit_logs al
                    LEFT JOIN users u ON al.$user_id_column = u.id
                    WHERE (al.target_table = 'cases' AND al.$case_id_column = ?) OR al.details LIKE ?
                    ORDER BY al.timestamp DESC
                    LIMIT 50
                ";
                $params = [$case_data['id'], '%' . $case_id . '%'];
            } else {
                $query = "
                    SELECT al.*, u.email as username 
                    FROM audit_logs al
                    LEFT JOIN users u ON al.$user_id_column = u.id
                    WHERE al.target_table = 'cases' AND al.$case_id_column = ?
                    ORDER BY al.timestamp DESC
                    LIMIT 50
                ";
                $params = [$case_data['id']];
            }
            
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $case_logs = $stmt->fetchAll();
            
            // Convert case_logs to activities for consistency in the template
            $activities = $case_logs;
        }
        
        // Check for attachments if that table exists
        $stmt = $pdo->prepare("SHOW TABLES LIKE 'case_attachments'");
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("
                SELECT * FROM case_attachments 
                WHERE case_id = ? OR case_number = ?
                ORDER BY uploaded_at DESC
            ");
            $stmt->execute([$case_data['id'], $case_id]);
            $attachments = $stmt->fetchAll();
        }
        
        // Check for comments if that table exists
        $stmt = $pdo->prepare("SHOW TABLES LIKE 'case_comments'");
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("
                SELECT c.*, u.email as username 
                FROM case_comments c
                LEFT JOIN users u ON c.user_id = u.id
                WHERE c.case_id = ? OR c.case_number = ?
                ORDER BY c.created_at DESC
            ");
            $stmt->execute([$case_data['id'], $case_id]);
            $comments = $stmt->fetchAll();
        }
    }
    
} catch (PDOException $e) {
    error_log('Database Error: ' . $e->getMessage());
    $error_message = "Database error: " . $e->getMessage();
}

// Helper function to format date
function formatDate($date) {
    if (empty($date)) return 'N/A';
    return date('F j, Y', strtotime($date));
}

// Helper function to calculate time elapsed
function timeElapsed($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);
    
    if ($diff->y > 0) {
        return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
    } elseif ($diff->m > 0) {
        return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
    } elseif ($diff->d > 0) {
        return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    } elseif ($diff->h > 0) {
        return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    } elseif ($diff->i > 0) {
        return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
    } else {
        return 'Just now';
    }
}

// Function to determine device acquisition status
function getDeviceStatus($device, $acquisition_forms) {
    foreach ($acquisition_forms as $form) {
        if ($form['serial_number'] === $device['serial_number']) {
            return [
                'status' => 'completed',
                'label' => 'Acquired'
            ];
        }
    }
    
    // Check if evidence_number exists and matches
    if (isset($device['evidence_number']) && !empty($device['evidence_number'])) {
        foreach ($acquisition_forms as $form) {
            if (isset($form['evidence_number']) && $form['evidence_number'] === $device['evidence_number']) {
                return [
                    'status' => 'completed',
                    'label' => 'Acquired'
                ];
            }
        }
    }
    
    return [
        'status' => 'pending',
        'label' => 'Pending'
    ];
}

// Helper function to format file size
function formatFileSize($bytes) {
    if ($bytes === 0) return '0 Bytes';
    
    $k = 1024;
    $sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes) / log($k));
    
    return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}

// Calculate case statistics
$case_stats = [
    'total_devices' => count($devices),
    'acquired_devices' => 0,
    'pending_devices' => 0,
    'total_tasks' => count($tasks),
    'completed_tasks' => 0,
    'pending_tasks' => 0,
    'overdue_tasks' => 0,
    'days_active' => 0
];

if (!empty($case_data['created_at'])) {
    $created = new DateTime($case_data['created_at']);
    $now = new DateTime();
    $case_stats['days_active'] = $created->diff($now)->days;
}

foreach ($devices as $device) {
    $status = getDeviceStatus($device, $acquisition_forms);
    if ($status['status'] === 'completed') {
        $case_stats['acquired_devices']++;
    } else {
        $case_stats['pending_devices']++;
    }
}

foreach ($tasks as $task) {
    if (isset($task['status']) && $task['status'] === 'Completed') {
        $case_stats['completed_tasks']++;
    } else {
        $case_stats['pending_tasks']++;
        if (!empty($task['due_date']) && strtotime($task['due_date']) < time()) {
            $case_stats['overdue_tasks']++;
        }
    }
}

// Calculate completion percentage
$device_completion = $case_stats['total_devices'] > 0 ? 
    round(($case_stats['acquired_devices'] / $case_stats['total_devices']) * 100) : 0;

$task_completion = $case_stats['total_tasks'] > 0 ? 
    round(($case_stats['completed_tasks'] / $case_stats['total_tasks']) * 100) : 0;
?>

<!DOCTYPE html>
<html>
<head>
    <title>Case Details - <?= htmlspecialchars($case_id) ?></title>
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
                    <li class="active">
                        <a href="cases.php">
                            <i class="fas fa-folder"></i> Cases
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
                    <li>
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
                <h1>Case Details</h1>
                <nav class="breadcrumb">
                    <a href="dashboard.php">Dashboard</a> / 
                    <a href="cases.php">Cases</a> / 
                    <span><?= htmlspecialchars($case_id) ?></span>
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

            <?php if (!empty($case_data)): ?>
                <div class="case-header">
                    <div class="case-title">
                        <h2><?= htmlspecialchars($case_data['case_number']) ?></h2>
                        <div class="case-badges">
                            <span class="badge badge-<?= isset($case_data['status']) ? strtolower($case_data['status']) : 'default' ?>"><?= htmlspecialchars($case_data['status'] ?? 'Unknown') ?></span>
                            <span class="badge badge-priority"><?= htmlspecialchars($case_data['priority'] ?? 'Medium') ?></span>
                        </div>
                    </div>
                    <div class="case-actions">
                        <a href="edit-case.php?id=<?= urlencode($case_data['id']) ?>" class="btn btn-secondary">
                            <i class="fas fa-edit"></i> Edit Case
                        </a>
                        <a href="create-coc.php?case=<?= urlencode($case_id) ?>" class="btn btn-primary">
                            <i class="fas fa-plus"></i> New Chain of Custody
                        </a>
                        <button type="button" class="btn btn-icon" id="moreActionsBtn">
                            <i class="fas fa-ellipsis-v"></i>
                        </button>
                    </div>
                </div>
                
                <div class="case-dropdown" id="caseDropdown">
                    <a href="create-task.php?case_id=<?= $case_data['id'] ?>">
                        <i class="fas fa-tasks"></i> Add Task
                    </a>
                    <a href="case-documents.php?id=<?= $case_data['id'] ?>">
                        <i class="fas fa-file"></i> Manage Documents
                    </a>
                    <a href="case-reports.php?id=<?= $case_data['id'] ?>" class="text-info">
                        <i class="fas fa-chart-bar"></i> Generate Reports
                    </a>
                    <a href="#" class="text-danger" id="closeCaseBtn">
                        <i class="fas fa-archive"></i> Close Case
                    </a>
                </div>
                
                <div class="content-grid">
                    <!-- Left column for case details -->
                    <div class="content-column">
                        <!-- Case Details Card -->
                        <div class="card">
                            <div class="card-header">
                                <h3>Case Information</h3>
                            </div>
                            <div class="card-body">
                                <div class="detail-grid">
                                    <div class="detail-item">
                                        <div class="detail-label">Case Number</div>
                                        <div class="detail-value"><?= htmlspecialchars($case_data['case_number']) ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Client</div>
                                        <div class="detail-value"><?= htmlspecialchars($case_data['company_name'] ?? 'N/A') ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Status</div>
                                        <div class="detail-value">
                                            <span class="status-indicator <?= isset($case_data['status']) ? strtolower($case_data['status']) : 'default' ?>"></span>
                                            <?= htmlspecialchars($case_data['status'] ?? 'Unknown') ?>
                                        </div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Priority</div>
                                        <div class="detail-value"><?= htmlspecialchars($case_data['priority'] ?? 'Medium') ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Created On</div>
                                        <div class="detail-value"><?= formatDate($case_data['created_at']) ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Created By</div>
                                        <div class="detail-value"><?= htmlspecialchars($case_data['created_by'] ?? 'N/A') ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Case Type</div>
                                        <div class="detail-value"><?= htmlspecialchars($case_data['case_type'] ?? 'Standard Investigation') ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Expected Duration</div>
                                        <div class="detail-value"><?= htmlspecialchars($case_data['expected_duration'] ?? 'Not specified') ?> days</div>
                                    </div>
                                </div>
                                
                                <div class="detail-full-width">
                                    <div class="detail-label">Case Description</div>
                                    <div class="detail-value description">
                                        <?= nl2br(htmlspecialchars($case_data['case_description'] ?? 'No description provided.')) ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Chain of Custody Card -->
                        <div class="card">
                            <div class="card-header">
                                <h3>Chain of Custody</h3>
                                <a href="create-coc.php?case=<?= urlencode($case_id) ?>" class="btn btn-sm">
                                    <i class="fas fa-plus"></i> New
                                </a>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($coc_records)): ?>
                                    <div class="table-responsive">
                                        <table class="data-table">
                                            <thead>
                                                <tr>
                                                    <th>ID</th>
                                                    <th>Released By</th>
                                                    <th>Received By</th>
                                                    <th>Date</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($coc_records as $record): ?>
                                                    <tr>
                                                        <td><?= $record['id'] ?></td>
                                                        <td><?= htmlspecialchars($record['released_by_name']) ?></td>
                                                        <td><?= htmlspecialchars($record['received_by_name']) ?></td>
                                                        <td><?= date('Y-m-d', strtotime($record['created_at'])) ?></td>
                                                        <td>
                                                            <?php if (!empty($record['returned_at'])): ?>
                                                                <span class="status-badge returned">Returned</span>
                                                            <?php else: ?>
                                                                <span class="status-badge active">Active</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <a href="view-coc.php?id=<?= $record['id'] ?>" class="btn-text">
                                                                <i class="fas fa-eye"></i> View
                                                            </a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <i class="fas fa-file-contract"></i>
                                        <p>No chain of custody records found</p>
                                        <a href="create-coc.php?case=<?= urlencode($case_id) ?>" class="btn">
                                            <i class="fas fa-plus"></i> Create Chain of Custody
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Devices Card -->
                        <div class="card">
                            <div class="card-header">
                                <h3>Devices</h3>
                                <span class="badge badge-count"><?= count($devices) ?></span>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($devices)): ?>
                                    <div class="table-responsive">
                                        <table class="data-table">
                                            <thead>
                                                <tr>
                                                    <th>#</th>
                                                    <th>Description</th>
                                                    <th>Serial Number</th>
                                                    <th>Evidence Number</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($devices as $device): 
                                                    $deviceStatus = getDeviceStatus($device, $acquisition_forms);
                                                ?>
                                                    <tr>
                                                        <td><?= $device['item_number'] ?></td>
                                                        <td><?= htmlspecialchars($device['description']) ?></td>
                                                        <td><?= htmlspecialchars($device['serial_number']) ?></td>
                                                        <td><?= htmlspecialchars($device['evidence_number'] ?? 'N/A') ?></td>
                                                        <td>
                                                            <span class="status-badge <?= $deviceStatus['status'] ?>">
                                                                <?= $deviceStatus['label'] ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php if($deviceStatus['status'] === 'completed'): ?>
                                                                <a href="view-acquisition.php?id=<?= $device['id'] ?>" class="btn-text">
                                                                    <i class="fas fa-eye"></i> View Acquisition
                                                                </a>
                                                            <?php else: ?>
                                                                <a href="acquisition-form.php?device_id=<?= $device['id'] ?>&job_code=<?= urlencode($case_id) ?>" class="btn-text">
                                                                    <i class="fas fa-plus"></i> Create Acquisition
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
                                        <i class="fas fa-microchip"></i>
                                        <p>No devices found for this case</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Right column -->
                    <div class="column">
                        <!-- Case Activity Timeline -->
                        <div class="card">
                            <div class="card-header">
                                <h3>Recent Activity</h3>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($activities)): ?>
                                    <div class="activity-timeline">
                                        <?php foreach($activities as $activity): ?>
                                            <div class="timeline-item">
                                                <div class="timeline-icon">
                                                    <?php if (isset($activity['activity_type'])): ?>
                                                        <?php if ($activity['activity_type'] == 'create'): ?>
                                                            <i class="fas fa-plus-circle"></i>
                                                        <?php elseif ($activity['activity_type'] == 'update'): ?>
                                                            <i class="fas fa-edit"></i>
                                                        <?php elseif ($activity['activity_type'] == 'delete'): ?>
                                                            <i class="fas fa-trash"></i>
                                                        <?php elseif ($activity['activity_type'] == 'comment'): ?>
                                                            <i class="fas fa-comment"></i>
                                                        <?php else: ?>
                                                            <i class="fas fa-info-circle"></i>
                                                        <?php endif; ?>
                                                    <?php elseif (isset($activity['action'])): ?>
                                                        <?php if (strpos($activity['action'], 'create') !== false): ?>
                                                            <i class="fas fa-plus-circle"></i>
                                                        <?php elseif (strpos($activity['action'], 'update') !== false): ?>
                                                            <i class="fas fa-edit"></i>
                                                        <?php elseif (strpos($activity['action'], 'delete') !== false): ?>
                                                            <i class="fas fa-trash"></i>
                                                        <?php elseif (strpos($activity['action'], 'comment') !== false): ?>
                                                            <i class="fas fa-comment"></i>
                                                        <?php else: ?>
                                                            <i class="fas fa-info-circle"></i>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <i class="fas fa-info-circle"></i>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="timeline-content">
                                                    <p class="timeline-text">
                                                        <strong><?= htmlspecialchars($activity['username'] ?? 'System') ?></strong> 
                                                        <?= htmlspecialchars($activity['description'] ?? $activity['action'] ?? 'performed an action') ?>
                                                    </p>
                                                    <span class="timeline-date">
                                                        <?= date('M j, Y g:i A', strtotime($activity['created_at'] ?? $activity['timestamp'] ?? $activity['date'] ?? 'now')) ?>
                                                    </span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <i class="fas fa-history"></i>
                                        <p>No activity recorded for this case</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- File Attachments -->
                        <div class="card">
                            <div class="card-header">
                                <h3>Attachments</h3>
                                <button type="button" class="btn btn-sm btn-primary" id="uploadAttachmentBtn">
                                    <i class="fas fa-upload"></i> Upload
                                </button>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($attachments)): ?>
                                    <div class="attachment-list">
                                        <?php foreach($attachments as $attachment): ?>
                                            <div class="attachment-item">
                                                <div class="attachment-icon">
                                                    <?php
                                                    $ext = pathinfo($attachment['file_name'], PATHINFO_EXTENSION);
                                                    switch(strtolower($ext)) {
                                                        case 'pdf':
                                                            echo '<i class="fas fa-file-pdf"></i>';
                                                            break;
                                                        case 'doc':
                                                        case 'docx':
                                                            echo '<i class="fas fa-file-word"></i>';
                                                            break;
                                                        case 'xls':
                                                        case 'xlsx':
                                                            echo '<i class="fas fa-file-excel"></i>';
                                                            break;
                                                        case 'jpg':
                                                        case 'jpeg':
                                                        case 'png':
                                                        case 'gif':
                                                            echo '<i class="fas fa-file-image"></i>';
                                                            break;
                                                        case 'zip':
                                                        case 'rar':
                                                            echo '<i class="fas fa-file-archive"></i>';
                                                            break;
                                                        default:
                                                            echo '<i class="fas fa-file"></i>';
                                                    }
                                                    ?>
                                                </div>
                                                <div class="attachment-info">
                                                    <div class="attachment-name">
                                                        <a href="download.php?id=<?= $attachment['id'] ?>" target="_blank">
                                                            <?= htmlspecialchars($attachment['file_name']) ?>
                                                        </a>
                                                    </div>
                                                    <div class="attachment-meta">
                                                        <?= formatFileSize($attachment['file_size']) ?> - 
                                                        Uploaded <?= date('M j, Y', strtotime($attachment['uploaded_at'])) ?>
                                                        by <?= htmlspecialchars($attachment['uploaded_by']) ?>
                                                    </div>
                                                </div>
                                                <div class="attachment-actions">
                                                    <button type="button" class="btn-icon delete-attachment" data-id="<?= $attachment['id'] ?>">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <i class="fas fa-file-upload"></i>
                                        <p>No attachments for this case</p>
                                        <button type="button" class="btn btn-primary" id="emptyAttachmentBtn">
                                            <i class="fas fa-upload"></i> Upload Files
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Comments Section -->
                        <div class="card">
                            <div class="card-header">
                                <h3>Comments</h3>
                            </div>
                            <div class="card-body">
                                <div class="comment-list" id="commentsList">
                                    <?php if (!empty($comments)): ?>
                                        <?php foreach($comments as $comment): ?>
                                            <div class="comment-item">
                                                <div class="comment-avatar">
                                                    <div class="avatar-placeholder">
                                                        <?= strtoupper(substr($comment['username'] ?? 'U', 0, 1)) ?>
                                                    </div>
                                                </div>
                                                <div class="comment-content">
                                                    <div class="comment-header">
                                                        <span class="comment-author"><?= htmlspecialchars($comment['username'] ?? 'User') ?></span>
                                                        <span class="comment-date"><?= date('M j, Y g:i A', strtotime($comment['created_at'])) ?></span>
                                                    </div>
                                                    <div class="comment-text">
                                                        <?= nl2br(htmlspecialchars($comment['content'])) ?>
                                                    </div>
                                                    <?php if (isset($comment['user_id']) && $comment['user_id'] == $_SESSION['user_id']): ?>
                                                        <div class="comment-actions">
                                                            <button type="button" class="btn-text edit-comment" data-id="<?= $comment['id'] ?>">
                                                                <i class="fas fa-edit"></i> Edit
                                                            </button>
                                                            <button type="button" class="btn-text delete-comment" data-id="<?= $comment['id'] ?>">
                                                                <i class="fas fa-trash"></i> Delete
                                                            </button>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="no-comments" id="noComments">
                                            <p>No comments yet. Be the first to add a comment.</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="comment-form">
                                    <form id="addCommentForm">
                                        <input type="hidden" name="case_id" value="<?= $case_id ?>">
                                        <div class="form-group">
                                            <textarea name="comment" id="commentText" rows="3" placeholder="Add a comment..." required></textarea>
                                        </div>
                                        <div class="form-group">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-paper-plane"></i> Add Comment
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Upload Attachment Modal -->
    <div class="modal" id="uploadAttachmentModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Upload Attachment</h3>
                    <button type="button" class="close-modal">&times;</button>
                </div>
                <div class="modal-body">
                    <form id="attachmentForm" enctype="multipart/form-data">
                        <input type="hidden" name="case_id" value="<?= $case_id ?>">
                        
                        <div class="form-group">
                            <label for="attachmentFile">Select File</label>
                            <input type="file" name="attachment" id="attachmentFile" required>
                            <small>Max file size: 10MB</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="attachmentDescription">Description (Optional)</label>
                            <textarea name="description" id="attachmentDescription" rows="2"></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label for="attachmentCategory">Category</label>
                            <select name="category" id="attachmentCategory">
                                <option value="evidence">Evidence</option>
                                <option value="report">Report</option>
                                <option value="correspondence">Correspondence</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        
                        <div class="form-actions">
                            <button type="button" class="btn" id="cancelUpload">Cancel</button>
                            <button type="submit" class="btn btn-primary">Upload</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Comment Modal -->
    <div class="modal" id="editCommentModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Edit Comment</h3>
                    <button type="button" class="close-modal">&times;</button>
                </div>
                <div class="modal-body">
                    <form id="editCommentForm">
                        <input type="hidden" name="comment_id" id="editCommentId">
                        
                        <div class="form-group">
                            <textarea name="content" id="editCommentText" rows="4" required></textarea>
                        </div>
                        
                        <div class="form-actions">
                            <button type="button" class="btn" id="cancelEditComment">Cancel</button>
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div class="modal" id="confirmDeleteModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Confirm Delete</h3>
                    <button type="button" class="close-modal">&times;</button>
                </div>
                <div class="modal-body">
                    <p id="deleteMessage">Are you sure you want to delete this item?</p>
                    
                    <div class="form-actions">
                        <button type="button" class="btn" id="cancelDelete">Cancel</button>
                        <button type="button" class="btn btn-danger" id="confirmDelete">Delete</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Modal handling
            const modals = document.querySelectorAll('.modal');
            const openModalButtons = document.querySelectorAll('[id$="Btn"]');
            const closeModalButtons = document.querySelectorAll('.close-modal, [id^="cancel"]');
            
            // Open modals
            openModalButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const targetId = this.id.replace('Btn', 'Modal');
                    const targetModal = document.getElementById(targetId);
                    if (targetModal) {
                        targetModal.classList.add('show');
                    }
                });
            });
            
            // Close modals
            closeModalButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const modal = this.closest('.modal');
                    if (modal) {
                        modal.classList.remove('show');
                    }
                });
            });
            
            // Close modal when clicking outside
            window.addEventListener('click', function(e) {
                modals.forEach(modal => {
                    if (e.target === modal) {
                        modal.classList.remove('show');
                    }
                });
            });
            
            // Handle tabs
            const tabLinks = document.querySelectorAll('.tab-link');
            const tabContents = document.querySelectorAll('.tab-content');
            
            tabLinks.forEach(tabLink => {
                tabLink.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    // Remove active class from all tab links and contents
                    tabLinks.forEach(link => link.classList.remove('active'));
                    tabContents.forEach(content => content.classList.remove('active'));
                    
                    // Add active class to clicked tab and corresponding content
                    this.classList.add('active');
                    const targetId = this.getAttribute('data-tab');
                    document.getElementById(targetId).classList.add('active');
                });
            });
            
            // Comment form handling
            const addCommentForm = document.getElementById('addCommentForm');
            if (addCommentForm) {
                addCommentForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    // Here you would normally send an AJAX request to save the comment
                    // For demonstration, we'll just add it to the UI
                    const commentText = document.getElementById('commentText').value;
                    if (commentText.trim() === '') return;
                    
                    // Add comment to UI
                    const commentsList = document.getElementById('commentsList');
                    const noComments = document.getElementById('noComments');
                    
                    if (noComments) {
                        noComments.remove();
                    }
                    
                    const newComment = document.createElement('div');
                    newComment.className = 'comment-item';
                    newComment.innerHTML = `
                        <div class="comment-avatar">
                            <div class="avatar-placeholder">
                                <?= strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1)) ?>
                            </div>
                        </div>
                        <div class="comment-content">
                            <div class="comment-header">
                                <span class="comment-author"><?= htmlspecialchars($_SESSION['username'] ?? 'User') ?></span>
                                <span class="comment-date">Just now</span>
                            </div>
                            <div class="comment-text">
                                ${commentText.replace(/\n/g, '<br>')}
                            </div>
                            <div class="comment-actions">
                                <button type="button" class="btn-text edit-comment">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button type="button" class="btn-text delete-comment">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </div>
                        </div>
                    `;
                    
                    commentsList.appendChild(newComment);
                    addCommentForm.reset();
                });
            }
            
            // Handle attachment upload
            const attachmentForm = document.getElementById('attachmentForm');
            if (attachmentForm) {
                attachmentForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    // Here you would send the form data via AJAX to save the attachment
                    alert('File upload functionality would be implemented with server-side code.');
                    
                    // Hide modal after submission
                    document.getElementById('uploadAttachmentModal').classList.remove('show');
                });
            }
            
            // Handle delete confirmation
            const deleteButtons = document.querySelectorAll('.delete-comment, .delete-attachment');
            deleteButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const itemId = this.getAttribute('data-id');
                    const itemType = this.classList.contains('delete-comment') ? 'comment' : 'attachment';
                    
                    // Set up confirmation modal
                    const message = `Are you sure you want to delete this ${itemType}? This action cannot be undone.`;
                    document.getElementById('deleteMessage').textContent = message;
                    
                    // Store item info for deletion
                    const confirmDeleteBtn = document.getElementById('confirmDelete');
                    confirmDeleteBtn.setAttribute('data-id', itemId);
                    confirmDeleteBtn.setAttribute('data-type', itemType);
                    
                    // Show modal
                    document.getElementById('confirmDeleteModal').classList.add('show');
                });
            });
            
            // Handle confirmed deletion
            const confirmDeleteBtn = document.getElementById('confirmDelete');
            if (confirmDeleteBtn) {
                confirmDeleteBtn.addEventListener('click', function() {
                    const itemId = this.getAttribute('data-id');
                    const itemType = this.getAttribute('data-type');
                    
                    // Here you would send an AJAX request to delete the item
                    // For demonstration, we'll just remove it from the UI
                    if (itemType === 'comment') {
                        const commentItem = document.querySelector(`.delete-comment[data-id="${itemId}"]`).closest('.comment-item');
                        if (commentItem) {
                            commentItem.remove();
                        }
                    } else if (itemType === 'attachment') {
                        const attachmentItem = document.querySelector(`.delete-attachment[data-id="${itemId}"]`).closest('.attachment-item');
                        if (attachmentItem) {
                            attachmentItem.remove();
                        }
                    }
                    
                    // Hide modal after deletion
                    document.getElementById('confirmDeleteModal').classList.remove('show');
                });
            }
            
            // Handle edit comment
            const editCommentButtons = document.querySelectorAll('.edit-comment');
            editCommentButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const commentId = this.getAttribute('data-id');
                    const commentText = this.closest('.comment-content').querySelector('.comment-text').innerHTML.replace(/<br>/g, '\n');
                    
                    // Set values in edit form
                    document.getElementById('editCommentId').value = commentId;
                    document.getElementById('editCommentText').value = commentText.trim();
                    
                    // Show modal
                    document.getElementById('editCommentModal').classList.add('show');
                });
            });
            
            // Handle edit comment form submission
            const editCommentForm = document.getElementById('editCommentForm');
            if (editCommentForm) {
                editCommentForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const commentId = document.getElementById('editCommentId').value;
                    const commentText = document.getElementById('editCommentText').value;
                    
                    // Here you would send an AJAX request to update the comment
                    // For demonstration, we'll just update it in the UI
                    const commentItem = document.querySelector(`.edit-comment[data-id="${commentId}"]`).closest('.comment-item');
                    if (commentItem) {
                        const commentTextEl = commentItem.querySelector('.comment-text');
                        commentTextEl.innerHTML = commentText.replace(/\n/g, '<br>');
                    }
                    
                    // Hide modal after submission
                    document.getElementById('editCommentModal').classList.remove('show');
                });
            }
        });

        // Helper function to format file size
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
    </script>

    <style>
        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            z-index: 1000;
            overflow-y: auto;
        }
        
        .modal.show {
            display: block;
        }
        
        .modal-dialog {
            max-width: 500px;
            margin: 80px auto;
        }
        
        .modal-content {
            background-color: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
        }
        
        .modal-header {
            padding: 15px 20px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .modal-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
        }
        
        .modal-body {
            padding: 20px;
        }
        
        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            font-weight: bold;
            color: #adb5bd;
            cursor: pointer;
        }
        
        .close-modal:hover {
            color: #212529;
        }
        
        /* Comment styles */
        .comment-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .comment-item {
            display: flex;
            gap: 15px;
        }
        
        .comment-avatar {
            flex-shrink: 0;
        }
        
        .avatar-placeholder {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #4dabf7;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }
        
        .comment-content {
            flex: 1;
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 12px;
        }
        
        .comment-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        
        .comment-author {
            font-weight: 600;
            color: #343a40;
        }
        
        .comment-date {
            color: #868e96;
            font-size: 0.85em;
        }
        
        .comment-text {
            margin-bottom: 10px;
            line-height: 1.5;
        }
        
        .comment-actions {
            display: flex;
            gap: 10px;
            margin-top: 5px;
        }
        
        .comment-form {
            margin-top: 20px;
        }
        
        .comment-form textarea {
            width: 100%;
            border: 1px solid #ced4da;
            border-radius: 4px;
            padding: 12px;
            resize: vertical;
            min-height: 80px;
            font-family: inherit;
            font-size: 0.95em;
        }
        
        .no-comments {
            text-align: center;
            color: #868e96;
            padding: 20px 0;
        }
        
        /* Attachment styles */
        .attachment-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .attachment-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px;
            background-color: #f8f9fa;
            border-radius: 6px;
        }
        
        .attachment-icon {
            width: 40px;
            height: 40px;
            background-color: #e9ecef;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: #495057;
        }
        
        .attachment-info {
            flex: 1;
            min-width: 0;
        }
        
        .attachment-name {
            font-weight: 500;
            margin-bottom: 3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .attachment-name a {
            color: #0288d1;
            text-decoration: none;
        }
        
        .attachment-name a:hover {
            text-decoration: underline;
        }
        
        .attachment-meta {
            font-size: 0.85em;
            color: #868e96;
        }
        
        .attachment-actions {
            flex-shrink: 0;
        }
        
        /* Timeline styles */
        .activity-timeline {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .timeline-item {
            display: flex;
            gap: 15px;
        }
        
        .timeline-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background-color: #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #495057;
            font-size: 14px;
            flex-shrink: 0;
        }
        
        .timeline-content {
            flex: 1;
            position: relative;
        }
        
        .timeline-text {
            margin-bottom: 3px;
        }
        
        .timeline-date {
            font-size: 0.85em;
            color: #868e96;
        }
        
        /* Form styles */
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
        }
        
        .form-group input[type="file"],
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-family: inherit;
            font-size: 0.95em;
        }
        
        .form-group select {
            height: 42px;
        }
        
        .form-group small {
            display: block;
            margin-top: 5px;
            color: #868e96;
        }
        
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }
        
        /* Tabs styles */
        .tab-container {
            margin-bottom: 20px;
        }
        
        .tab-nav {
            display: flex;
            border-bottom: 1px solid #dee2e6;
            margin-bottom: 15px;
        }
        
        .tab-link {
            padding: 10px 15px;
            color: #495057;
            text-decoration: none;
            font-weight: 500;
            position: relative;
        }
        
        .tab-link.active {
            color: #0288d1;
        }
        
        .tab-link.active:after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 0;
            width: 100%;
            height: 2px;
            background-color: #0288d1;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* Button styles */
        .btn {
            padding: 8px 16px;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background-color: #e9ecef;
            color: #495057;
        }
        
        .btn:hover {
            background-color: #dee2e6;
        }
        
        .btn-primary {
            background-color: #0288d1;
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #0277bd;
        }
        
        .btn-danger {
            background-color: #e03131;
            color: white;
        }
        
        .btn-danger:hover {
            background-color: #c92a2a;
        }
        
        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
        }
        
        .btn-icon {
            width: 32px;
            height: 32px;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
        }
        
        .btn-text {
            background: none;
            border: none;
            color: #495057;
            cursor: pointer;
            padding: 0;
            font-size: 0.85em;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-text:hover {
            color: #0288d1;
        }

        /* Responsive styles */
        @media (max-width: 768px) {
            .modal-dialog {
                max-width: 95%;
                margin: 50px auto;
            }
            
            .comment-item {
                flex-direction: column;
                gap: 10px;
            }
            
            .comment-avatar {
                align-self: flex-start;
            }
        }
    </style>
</body>
</html>