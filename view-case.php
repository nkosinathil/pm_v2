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

define('CURRENT_TIMESTAMP', '2025-06-14 20:03:00');
define('CURRENT_USER', 'nkosinathil');

include_once('db-connection.php');

$error_message = '';
$success_message = '';

// Get case ID from URL parameter
$case_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($case_id <= 0) {
    $error_message = "Invalid case ID.";
}

$case_data = [];
$client_data = [];
$devices = [];
$coc_records = [];
$acquisition_forms = [];
$assigned_users = []; // This will remain empty since case_assignments table doesn't exist
$tasks = [];
$case_logs = [];

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
        WHERE c.id = ?
    ");
    $stmt->execute([$case_id]);
    $case_data = $stmt->fetch();
    
    if (!$case_data) {
        $error_message = "Case not found.";
    } else {
        // Get case_number for further queries
        $case_number = $case_data['case_number'];
        
        // Fetch devices for this case
        $stmt = $pdo->prepare("
            SELECT * FROM custody_devices 
            WHERE job_code = ? 
            ORDER BY item_number
        ");
        $stmt->execute([$case_number]);
        $devices = $stmt->fetchAll();
        
        // Fetch chain of custody records for this case
        $stmt = $pdo->prepare("
            SELECT * FROM custody_logs 
            WHERE job_code = ? 
            ORDER BY created_at DESC
        ");
        $stmt->execute([$case_number]);
        $coc_records = $stmt->fetchAll();
        
        // Check for acquisition forms
        $stmt = $pdo->prepare("
            SELECT * FROM acquisition_forms 
            WHERE case_id = ? 
            ORDER BY created_at DESC
        ");
        $stmt->execute([$case_number]);
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
                $param_value = ($case_column === 'job_code' || $case_column === 'case_number') ? $case_number : $case_id;
                $stmt->execute([$param_value]);
                $tasks = $stmt->fetchAll();
            } else {
                // If no matching column found, tasks will remain an empty array
                error_log('Could not find a case-related column in the tasks table');
            }
        }
        
        // Check if audit_logs table exists before querying
        // Replace the audit_logs query section with this updated version
		// Check if audit_logs table exists before querying
		$stmt = $pdo->prepare("SHOW TABLES LIKE 'audit_logs'");
		$stmt->execute();
		if ($stmt->rowCount() > 0) {
			// First check the audit_logs table structure to understand the columns
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
				$params = [$case_id, '%' . $case_number . '%'];
			} else {
				$query = "
					SELECT al.*, u.email as username 
					FROM audit_logs al
					LEFT JOIN users u ON al.$user_id_column = u.id
					WHERE al.target_table = 'cases' AND al.$case_id_column = ?
					ORDER BY al.timestamp DESC
					LIMIT 50
				";
				$params = [$case_id];
			}
			
			$stmt = $pdo->prepare($query);
			$stmt->execute($params);
			$case_logs = $stmt->fetchAll();
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
    if ($task['status'] === 'Completed') {
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
    <title>View Case - <?= htmlspecialchars($case_data['case_number'] ?? 'Details') ?></title>
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
                    <span><?= htmlspecialchars($case_data['case_number'] ?? 'Details') ?></span>
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
                        <a href="edit-case.php?id=<?= $case_id ?>" class="btn btn-secondary">
                            <i class="fas fa-edit"></i> Edit Case
                        </a>
                        <a href="create-coc.php?case=<?= urlencode($case_data['case_number']) ?>" class="btn btn-primary">
                            <i class="fas fa-plus"></i> New Chain of Custody
                        </a>
                        <button type="button" class="btn btn-icon" id="moreActionsBtn">
                            <i class="fas fa-ellipsis-v"></i>
                        </button>
                    </div>
                </div>
                
                <div class="case-dropdown" id="caseDropdown">
                    <a href="create-task.php?case_id=<?= $case_id ?>">
                        <i class="fas fa-tasks"></i> Add Task
                    </a>
                    <a href="case-documents.php?id=<?= $case_id ?>">
                        <i class="fas fa-file"></i> Manage Documents
                    </a>
                    <a href="case-reports.php?id=<?= $case_id ?>" class="text-info">
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
                                <a href="create-coc.php?case=<?= urlencode($case_data['case_number']) ?>" class="btn btn-sm">
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
                                        <a href="create-coc.php?case=<?= urlencode($case_data['case_number']) ?>" class="btn">
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
                                                                <a href="acquisition-form.php?device_id=<?= $device['id'] ?>&job_code=<?= urlencode($case_data['case_number']) ?>" class="btn-text">
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
                                        <i class="fas fa-laptop"></i>
                                        <p>No devices added to this case</p>
                                        <a href="create-coc.php?case=<?= urlencode($case_data['case_number']) ?>" class="btn">
                                            <i class="fas fa-plus"></i> Add Devices with Chain of Custody
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Right column for progress, tasks, etc -->
                    <div class="content-column">
                        <!-- Case Progress Card -->
                        <div class="card">
                            <div class="card-header">
                                <h3>Case Progress</h3>
                            </div>
                            <div class="card-body">
                                <div class="progress-section">
                                    <div class="progress-header">
                                        <span>Device Acquisition</span>
                                        <span><?= $case_stats['acquired_devices'] ?>/<?= $case_stats['total_devices'] ?> (<?= $device_completion ?>%)</span>
                                    </div>
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?= $device_completion ?>%"></div>
                                    </div>
                                </div>
                                
                                <div class="progress-section">
                                    <div class="progress-header">
                                        <span>Tasks Completed</span>
                                        <span><?= $case_stats['completed_tasks'] ?>/<?= $case_stats['total_tasks'] ?> (<?= $task_completion ?>%)</span>
                                    </div>
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?= $task_completion ?>%"></div>
                                    </div>
                                </div>
                                
                                <div class="stats-grid">
                                    <div class="stat-item">
                                        <div class="stat-value"><?= $case_stats['total_devices'] ?></div>
                                        <div class="stat-label">Total Devices</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-value"><?= $case_stats['total_tasks'] ?></div>
                                        <div class="stat-label">Total Tasks</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-value"><?= $case_stats['days_active'] ?></div>
                                        <div class="stat-label">Days Active</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-value <?= $case_stats['overdue_tasks'] > 0 ? 'text-danger' : '' ?>">
                                            <?= $case_stats['overdue_tasks'] ?>
                                        </div>
                                        <div class="stat-label">Overdue Tasks</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Client Information Card -->
                        <div class="card">
                            <div class="card-header">
                                <h3>Client Information</h3>
                                <a href="edit-client.php?id=<?= $case_data['client_code'] ?>" class="btn btn-sm">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                            </div>
                            <div class="card-body">
                                <div class="detail-grid">
                                    <div class="detail-item">
                                        <div class="detail-label">Client Code</div>
                                        <div class="detail-value"><?= htmlspecialchars($case_data['client_code'] ?? 'N/A') ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Company Name</div>
                                        <div class="detail-value"><?= htmlspecialchars($case_data['company_name'] ?? 'N/A') ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Representative</div>
                                        <div class="detail-value">
                                            <?= htmlspecialchars(($case_data['rep_name'] ?? '') . ' ' . ($case_data['rep_surname'] ?? '')) ?>
                                        </div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Email</div>
                                        <div class="detail-value">
                                            <a href="mailto:<?= htmlspecialchars($case_data['email'] ?? $case_data['rep_email'] ?? 'N/A') ?>">
                                                <?= htmlspecialchars($case_data['email'] ?? $case_data['rep_email'] ?? 'N/A') ?>
                                            </a>
                                        </div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Phone</div>
                                        <div class="detail-value">
                                            <a href="tel:<?= htmlspecialchars($case_data['phone'] ?? $case_data['rep_phone'] ?? 'N/A') ?>">
                                                <?= htmlspecialchars($case_data['phone'] ?? $case_data['rep_phone'] ?? 'N/A') ?>
                                            </a>
                                        </div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Industry</div>
                                        <div class="detail-value"><?= htmlspecialchars($case_data['industry'] ?? 'N/A') ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tasks Card -->
                        <?php if (!empty($tasks)): ?>
                        <div class="card">
                            <div class="card-header">
                                <h3>Tasks</h3>
                                <a href="create-task.php?case_id=<?= $case_id ?>" class="btn btn-sm">
                                    <i class="fas fa-plus"></i> New Task
                                </a>
                            </div>
                            <div class="card-body">
                                <div class="task-list">
                                    <?php foreach(array_slice($tasks, 0, 5) as $task): ?>
                                        <div class="task-item">
                                            <div class="task-checkbox">
                                                <input type="checkbox" id="task-<?= $task['id'] ?>" <?= $task['status'] === 'Completed' ? 'checked' : '' ?> disabled>
                                                <label for="task-<?= $task['id'] ?>"></label>
                                            </div>
                                            <div class="task-content">
                                                <div class="task-title">
                                                    <a href="view-task.php?id=<?= $task['id'] ?>"><?= htmlspecialchars($task['title']) ?></a>
                                                    <?php if ($task['priority'] === 'High'): ?>
                                                        <span class="priority-indicator high"></span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="task-meta">
                                                    <span class="task-assigned"><?= htmlspecialchars($task['assigned_to_name'] ?? 'Unassigned') ?></span>
                                                    <?php if (!empty($task['due_date'])): ?>
                                                        <span class="task-due <?= strtotime($task['due_date']) < time() && $task['status'] !== 'Completed' ? 'overdue' : '' ?>">
                                                            Due: <?= date('M j', strtotime($task['due_date'])) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="task-status">
                                                <span class="status-badge <?= strtolower($task['status']) ?>">
                                                    <?= htmlspecialchars($task['status']) ?>
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    
                                    <?php if (count($tasks) > 5): ?>
                                        <div class="view-all">
                                            <a href="task-management.php?case_id=<?= $case_id ?>">View all <?= count($tasks) ?> tasks</a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Activity Log Card -->
                        <?php if (!empty($case_logs)): ?>
                        <div class="card">
                            <div class="card-header">
                                <h3>Activity Log</h3>
                            </div>
                            <div class="card-body">
                                <div class="activity-timeline">
                                    <?php foreach(array_slice($case_logs, 0, 10) as $log): ?>
                                        <div class="activity-item">
                                            <div class="activity-icon">
                                                <?php if (strpos($log['action'], 'created') !== false): ?>
                                                    <i class="fas fa-plus-circle"></i>
                                                <?php elseif (strpos($log['action'], 'updated') !== false): ?>
                                                    <i class="fas fa-edit"></i>
                                                <?php elseif (strpos($log['action'], 'deleted') !== false): ?>
                                                    <i class="fas fa-trash"></i>
                                                <?php elseif (strpos($log['action'], 'assigned') !== false): ?>
                                                    <i class="fas fa-user-plus"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-info-circle"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div class="activity-content">
                                                <div class="activity-header">
                                                    <span class="activity-action"><?= htmlspecialchars($log['action']) ?></span>
                                                    <span class="activity-time"><?= timeElapsed($log['timestamp']) ?></span>
                                                </div>
                                                <div class="activity-user">by <?= htmlspecialchars($log['username'] ?? 'System') ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    
                                    <?php if (count($case_logs) > 10): ?>
                                        <div class="view-all">
                                            <a href="case-activity.php?id=<?= $case_id ?>">View all activity</a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Close Case Modal -->
                <div id="closeCaseModal" class="modal">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2>Close Case</h2>
                            <span class="close" id="closeModalX">&times;</span>
                        </div>
                        <div class="modal-body">
                            <p>Are you sure you want to close this case? This will update the status to "Closed" and may affect ongoing activities.</p>
                            
                            <form action="close-case.php" method="post">
                                <input type="hidden" name="case_id" value="<?= $case_id ?>">
                                
                                <div class="form-group">
                                    <label for="close_reason">Reason for closing:</label>
                                    <select name="close_reason" id="close_reason" required>
                                        <option value="">Select reason...</option>
                                        <option value="Investigation Complete">Investigation Complete</option>
                                        <option value="Client Request">Client Request</option>
                                        <option value="Insufficient Evidence">Insufficient Evidence</option>
                                        <option value="Resource Constraints">Resource Constraints</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="close_notes">Additional Notes:</label>
                                    <textarea name="close_notes" id="close_notes" rows="4"></textarea>
                                </div>
                                
                                <div class="form-actions">
                                    <button type="button" id="cancelClose" class="btn">Cancel</button>
                                    <button type="submit" class="btn btn-danger">Close Case</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="content-card">
                    <div class="empty-state large">
                        <i class="fas fa-folder-open"></i>
                        <h3>Case Not Found</h3>
                        <p>The requested case could not be found or you don't have permission to view it.</p>
                        <a href="cases.php" class="btn">
                            <i class="fas fa-arrow-left"></i> Back to Cases
                        </a>
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

        .content-card {
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .case-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .case-title {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .case-title h2 {
            font-size: 1.5rem;
            color: #2d3436;
            margin: 0;
        }

        .case-badges {
            display: flex;
            gap: 10px;
        }

        .badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 16px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .badge-open {
            background-color: #e3f2fd;
            color: #0288d1;
        }

        .badge-closed {
            background-color: #f5f5f5;
            color: #757575;
        }

        .badge-priority {
            background-color: #fff3e0;
            color: #ff9800;
        }

        .badge-count {
            background-color: #e9ecef;
            color: #495057;
        }

        .case-actions {
            display: flex;
            gap: 10px;
            position: relative;
        }

        .btn {
            padding: 8px 16px;
            border-radius: 4px;
            font-size: 0.9em;
            font-weight: 500;
            text-decoration: none;
            color: #495057;
            background-color: #e9ecef;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
        }

        .btn:hover {
            background-color: #dee2e6;
            transform: translateY(-2px);
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

        .btn-danger {
            background-color: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background-color: #c82333;
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 0.8em;
        }

        .btn-icon {
            padding: 8px 10px;
            min-width: 36px;
        }

        .case-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background-color: white;
            border-radius: 4px;
            box-shadow: 0 3px 15px rgba(0,0,0,0.1);
            padding: 8px 0;
            min-width: 200px;
            z-index: 100;
            display: none;
        }

        .case-dropdown a {
            display: block;
            padding: 8px 15px;
            color: #495057;
            text-decoration: none;
            transition: background-color 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .case-dropdown a:hover {
            background-color: #f8f9fa;
        }

        .case-dropdown a.text-danger {
            color: #e03131;
        }

        .case-dropdown a.text-info {
            color: #0288d1;
        }

        .content-grid {
            display: grid;
            grid-template-columns: 3fr 2fr;
            gap: 20px;
        }

        .content-column {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .card {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            overflow: hidden;
        }

        .card-header {
            padding: 15px 20px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 600;
            color: #2d3436;
        }

        .card-body {
            padding: 20px;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 15px;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
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

        .detail-value a {
            color: #0288d1;
            text-decoration: none;
        }

        .detail-value a:hover {
            text-decoration: underline;
        }

        .detail-value.description {
            white-space: pre-line;
            line-height: 1.5;
        }

        .detail-full-width {
            margin-top: 15px;
            grid-column: 1 / -1;
        }

        .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 5px;
        }

        .status-indicator.open {
            background-color: #0288d1;
        }

        .status-indicator.closed {
            background-color: #757575;
        }

        .status-indicator.pending {
            background-color: #ff9800;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: 500;
        }

        .status-badge.active {
            background-color: #e3f2fd;
            color: #0288d1;
        }

        .status-badge.completed, 
        .status-badge.returned {
            background-color: #d3f9d8;
            color: #2b8a3e;
        }

        .status-badge.in-progress {
            background-color: #fff9db;
            color: #fab005;
        }

        .status-badge.pending {
            background-color: #fff3bf;
            color: #e67700;
        }

        .table-responsive {
            overflow-x: auto;
            margin-bottom: 15px;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th,
        .data-table td {
            padding: 10px 15px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }

        .data-table th {
            background-color: #f8f9fa;
            font-weight: 500;
            color: #495057;
        }

        .data-table tbody tr:last-child td {
            border-bottom: none;
        }

        .data-table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .btn-text {
            background: none;
            border: none;
            color: #0288d1;
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
        }

        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 15px;
            padding: 20px;
            text-align: center;
        }

        .empty-state i {
            font-size: 32px;
            color: #adb5bd;
        }

        .empty-state p {
            color: #868e96;
        }

        .empty-state.large {
            padding: 40px 20px;
        }

        .empty-state.large i {
            font-size: 48px;
            margin-bottom: 10px;
        }

        .empty-state.large h3 {
            font-size: 1.2rem;
            margin-bottom: 10px;
        }

        .progress-section {
            margin-bottom: 20px;
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 0.9em;
        }

        .progress-bar {
            height: 8px;
            background-color: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background-color: #4dabf7;
            border-radius: 4px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-top: 20px;
        }

        .stat-item {
            padding: 10px;
            text-align: center;
            background-color: #f8f9fa;
            border-radius: 4px;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 600;
            color: #2d3436;
        }

        .stat-label {
            font-size: 0.8em;
            color: #868e96;
            margin-top: 5px;
        }

        .text-danger {
            color: #e03131;
        }

        .user-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 15px;
        }

        .user-card {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background-color: #f8f9fa;
            border-radius: 4px;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background-color: #e9ecef;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #868e96;
            font-size: 18px;
        }

        .user-info {
            flex: 1;
            overflow: hidden;
        }

        .user-name {
            font-weight: 500;
            color: #2d3436;
            font-size: 0.95em;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-role {
            font-size: 0.8em;
            color: #868e96;
        }

        .task-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .task-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background-color: #f8f9fa;
            border-radius: 4px;
        }

        .task-checkbox {
            position: relative;
            width: 20px;
            height: 20px;
        }

        .task-checkbox input {
            position: absolute;
            opacity: 0;
            cursor: pointer;
            height: 0;
            width: 0;
        }

        .task-checkbox label {
            position: absolute;
            top: 0;
            left: 0;
            height: 20px;
            width: 20px;
            background-color: white;
            border: 1px solid #ced4da;
            border-radius: 4px;
            transition: all 0.2s ease;
        }

        .task-checkbox input:checked + label {
            background-color: #37b24d;
            border-color: #37b24d;
        }

        .task-checkbox label:after {
            content: "";
            position: absolute;
            display: none;
            left: 7px;
            top: 3px;
            width: 5px;
            height: 10px;
            border: solid white;
            border-width: 0 2px 2px 0;
            transform: rotate(45deg);
        }

        .task-checkbox input:checked + label:after {
            display: block;
        }

        .task-content {
            flex: 1;
            min-width: 0;
        }

        .task-title {
            font-weight: 500;
            color: #2d3436;
            font-size: 0.95em;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .task-title a {
            text-decoration: none;
            color: #2d3436;
        }

        .task-title a:hover {
            color: #0288d1;
        }

        .priority-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }

        .priority-indicator.high {
            background-color: #fa5252;
        }

        .task-meta {
            font-size: 0.8em;
            color: #868e96;
            margin-top: 3px;
            display: flex;
            gap: 10px;
        }

        .task-due.overdue {
            color: #fa5252;
        }

        .activity-timeline {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .activity-item {
            display: flex;
            gap: 15px;
        }

        .activity-icon {
            width: 28px;
            height: 28px;
            background-color: #e9ecef;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #495057;
            font-size: 14px;
            flex-shrink: 0;
        }

        .activity-content {
            flex: 1;
            min-width: 0;
        }

        .activity-header {
            display: flex;
            justify-content: space-between;
            gap: 10px;
        }

        .activity-action {
            font-weight: 500;
            font-size: 0.9em;
            color: #2d3436;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .activity-time {
            font-size: 0.8em;
            color: #868e96;
            white-space: nowrap;
        }

        .activity-user {
            font-size: 0.8em;
            color: #868e96;
            margin-top: 3px;
        }

        .view-all {
            text-align: center;
            margin-top: 15px;
            padding-top: 10px;
            border-top: 1px solid #e9ecef;
        }

        .view-all a {
            color: #0288d1;
            text-decoration: none;
            font-size: 0.9em;
        }

        .view-all a:hover {
            text-decoration: underline;
        }

        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
        }

        .modal-content {
            background-color: #fefefe;
            margin: 10% auto;
            padding: 0;
            border-radius: 8px;
            width: 500px;
            max-width: 90%;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            animation: fadeIn 0.3s ease-in-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .modal-header {
            padding: 15px 20px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 1.25rem;
        }

        .modal-body {
            padding: 20px;
        }

        .close {
            color: #aaa;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
        }

        .close:hover {
            color: #333;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            font-size: 0.9em;
        }

        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-family: inherit;
            font-size: 0.95em;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
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
            
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .case-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .case-actions {
                width: 100%;
                justify-content: flex-start;
            }
            
            .system-info {
                flex-direction: column;
                align-items: flex-end;
                gap: 5px;
            }
            
            .detail-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .top-bar {
                padding: 10px 15px;
            }

            .page-header h1 {
                font-size: 1.5rem;
            }

            .card-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .btn-text {
                padding: 4px 6px;
                font-size: 0.85em;
            }

            .data-table th,
            .data-table td {
                padding: 8px 10px;
                font-size: 0.9em;
            }

            .action-buttons {
                width: 100%;
                flex-wrap: wrap;
            }

            .modal-content {
                margin: 20% auto;
            }

            .detail-value {
                word-break: break-word;
            }

            .case-badges {
                flex-wrap: wrap;
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
            
            // More actions dropdown
            const moreActionsBtn = document.getElementById('moreActionsBtn');
            const caseDropdown = document.getElementById('caseDropdown');
            
            if (moreActionsBtn && caseDropdown) {
                moreActionsBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    caseDropdown.style.display = caseDropdown.style.display === 'block' ? 'none' : 'block';
                });
                
                document.addEventListener('click', function() {
                    if (caseDropdown) caseDropdown.style.display = 'none';
                });
                
                if (caseDropdown) {
                    caseDropdown.addEventListener('click', function(e) {
                        e.stopPropagation();
                    });
                }
            }
            
            // Close case modal
            const closeCaseBtn = document.getElementById('closeCaseBtn');
            const closeCaseModal = document.getElementById('closeCaseModal');
            const closeModalX = document.getElementById('closeModalX');
            const cancelClose = document.getElementById('cancelClose');
            
            if (closeCaseBtn && closeCaseModal) {
                closeCaseBtn.addEventListener('click', function() {
                    closeCaseModal.style.display = 'block';
                });
            }
            
            if (closeModalX && closeCaseModal) {
                closeModalX.addEventListener('click', function() {
                    closeCaseModal.style.display = 'none';
                });
            }
            
            if (cancelClose && closeCaseModal) {
                cancelClose.addEventListener('click', function() {
                    closeCaseModal.style.display = 'none';
                });
            }
            
            // Close modal when clicking outside
            window.addEventListener('click', function(e) {
                if (closeCaseModal && e.target == closeCaseModal) {
                    closeCaseModal.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>