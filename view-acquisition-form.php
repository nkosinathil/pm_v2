<?php
session_start();
define('CURRENT_TIMESTAMP', '2025-06-13 19:37:19');
define('CURRENT_USER', 'nkosinathil');

// Database connection setup
include_once('db-connection.php');

$job_code = isset($_GET['job_code']) ? $_GET['job_code'] : '';
$acquisitions = [];
$total_records = 0;
$error_message = '';
$success_message = '';

// Handle potential deletion request
if (isset($_POST['delete_acquisition']) && isset($_POST['acquisition_id'])) {
    $delete_id = (int)$_POST['acquisition_id'];
    
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        
        // Check if user has permission to delete (optional)
        // ...
        
        // Begin transaction for safer deletion
        $pdo->beginTransaction();
        
        // First check if acquisition exists
        $check = $pdo->prepare("SELECT id FROM acquisition_forms WHERE id = ?");
        $check->execute([$delete_id]);
        
        if ($check->rowCount() > 0) {
            // Delete the acquisition
            $stmt = $pdo->prepare("DELETE FROM acquisition_forms WHERE id = ?");
            $stmt->execute([$delete_id]);
            
            // Commit transaction
            $pdo->commit();
            $success_message = "Acquisition record #" . $delete_id . " has been successfully deleted.";
        } else {
            $error_message = "Acquisition record not found.";
        }
    } catch (PDOException $e) {
        // Rollback in case of error
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error_message = "Database error: " . $e->getMessage();
    }
}

// Pagination settings
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    // Query to count total records
    $count_sql = "SELECT COUNT(*) FROM acquisition_forms WHERE 1=1";
    $where_params = [];
    
    // Apply filters
    if (!empty($job_code)) {
        $count_sql .= " AND case_id = ?";
        $where_params[] = $job_code;
    }
    
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($where_params);
    $total_records = $count_stmt->fetchColumn();
    
    // Calculate total pages
    $total_pages = ceil($total_records / $per_page);
    if ($page > $total_pages && $total_pages > 0) {
        $page = $total_pages;
        $offset = ($page - 1) * $per_page;
    }
    
    // Query to fetch paginated records
    $sql = "SELECT * FROM acquisition_forms WHERE 1=1";
    
    // Apply the same filters as for count
    if (!empty($job_code)) {
        $sql .= " AND case_id = ?";
    }
    
    // FIX: Use integers for LIMIT and OFFSET, not strings
    $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
    
    // Make a copy of the parameters for query execution
    $query_params = $where_params;
    $query_params[] = (int)$per_page;  // Cast to integer for LIMIT
    $query_params[] = (int)$offset;    // Cast to integer for OFFSET
    
    $stmt = $pdo->prepare($sql);
    
    // Bind parameters with explicit type
    for ($i = 0; $i < count($query_params); $i++) {
        $param_type = PDO::PARAM_STR;
        if (is_int($query_params[$i])) {
            $param_type = PDO::PARAM_INT;
        }
        $stmt->bindValue($i + 1, $query_params[$i], $param_type);
    }
    
    $stmt->execute();
    $acquisitions = $stmt->fetchAll();
    
} catch (PDOException $e) {
    $error_message = "Database error: " . $e->getMessage();
}

// Function to format date nicely if available
function formatDate($date) {
    if (empty($date)) return "-";
    return date("Y-m-d", strtotime($date));
}

// Function to format time nicely if available
function formatTime($time) {
    if (empty($time)) return "-";
    return date("H:i", strtotime($time));
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>View Acquisition Forms</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { 
            font-family: 'Inter', sans-serif; 
            margin: 20px;
            background-color: #f5f6fa;
            color: #2d3436;
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
            background-color: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .filters {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: center;
        }
        
        .filter-item {
            display: flex;
            flex-direction: column;
            flex-grow: 1;
        }
        
        .filter-item label {
            font-size: 0.85em;
            margin-bottom: 5px;
            color: #636e72;
        }
        
        .filter-item input, .filter-item select {
            padding: 8px 12px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
        }
        
        .filter-actions {
            display: flex;
            align-items: flex-end;
            gap: 10px;
        }
        
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.2s ease;
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
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        th, td {
            border: 1px solid #dee2e6;
            padding: 12px 15px;
        }
        
        th {
            background-color: #f8f9fa;
            font-weight: 500;
            text-align: left;
        }
        
        tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px;
            text-align: center;
        }
        
        .empty-state-icon {
            font-size: 48px;
            color: #adb5bd;
            margin-bottom: 15px;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 30px;
            gap: 5px;
        }
        
        .pagination a, .pagination span {
            display: inline-block;
            padding: 8px 14px;
            border-radius: 4px;
            background: #f8f9fa;
            color: #495057;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        
        .pagination a:hover {
            background: #e9ecef;
        }
        
        .pagination .active {
            background: #007bff;
            color: white;
        }
        
        .pagination .disabled {
            color: #adb5bd;
            pointer-events: none;
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
        
        .actions-column {
            white-space: nowrap;
        }
        
        .action-link {
            margin-right: 10px;
            color: #495057;
        }
        
        .action-link:hover {
            color: #228be6;
        }
        
        .delete-form {
            display: inline;
        }
        
        @media (max-width: 768px) {
            table {
                display: block;
                overflow-x: auto;
            }
            
            .filters {
                flex-direction: column;
            }
            
            .filter-item {
                width: 100%;
            }
            
            .filter-actions {
                flex-direction: column;
                width: 100%;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
        
        .case-info {
            background-color: #e3f2fd;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            border-left: 4px solid #1976d2;
        }
        
        .case-info h3 {
            margin-top: 0;
            margin-bottom: 10px;
            color: #1976d2;
        }
        
        .case-info-row {
            display: flex;
            margin-bottom: 5px;
        }
        
        .case-info-label {
            font-weight: 500;
            min-width: 120px;
        }
    </style>
</head>
<body>

<div class="header-bar">
    <h1>Acquisition Forms</h1>
    <div class="user-info">
        <span>Current Date and Time (UTC - YYYY-MM-DD HH:MM:SS formatted): <?= CURRENT_TIMESTAMP ?></span>
        <span>Current User's Login: <?= CURRENT_USER ?></span>
    </div>
</div>

<div class="breadcrumb">
    <a href="dashboard.php">Dashboard</a> / 
    <a href="coc.php">Chain of Custody</a> /
    <span>Acquisition Forms</span>
</div>

<div class="container">
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
    
    <?php if (!empty($job_code)): ?>
    <div class="case-info">
        <h3>Case Details: <?= htmlspecialchars($job_code) ?></h3>
        <?php
        // Get case details if available
        try {
            // Check if cases table exists
            $tableExists = false;
            $tablesQuery = $pdo->query("SHOW TABLES LIKE 'cases'");
            if ($tablesQuery->rowCount() > 0) {
                $tableExists = true;
            }
            
            if ($tableExists) {
                $case_stmt = $pdo->prepare("SELECT * FROM cases WHERE case_id = ? LIMIT 1");
                $case_stmt->execute([$job_code]);
                $case_details = $case_stmt->fetch();
                
                if ($case_details): 
        ?>
        <div class="case-info-row">
            <div class="case-info-label">Case Title:</div>
            <div><?= htmlspecialchars($case_details['case_title'] ?? '-') ?></div>
        </div>
        <div class="case-info-row">
            <div class="case-info-label">Client:</div>
            <div><?= htmlspecialchars($case_details['client_name'] ?? '-') ?></div>
        </div>
        <div class="case-info-row">
            <div class="case-info-label">Status:</div>
            <div><?= htmlspecialchars($case_details['status'] ?? '-') ?></div>
        </div>
        <?php 
                else: 
        ?>
        <p>No detailed case information available for this case ID.</p>
        <?php 
                endif;
            } else {
        ?>
        <p>Case details not available in the current system structure.</p>
        <?php
            }
        } catch (PDOException $e) {
            echo "<p>Error retrieving case details.</p>";
            error_log("Error in view-acquisition-forms.php: " . $e->getMessage());
        }
        ?>
        
        <div style="margin-top: 10px;">
            <a href="case-details.php?id=<?= urlencode($job_code) ?>" class="btn btn-outline">
                <i class="fas fa-folder-open"></i> View Full Case
            </a>
            <a href="acquisition-form.php?job_code=<?= urlencode($job_code) ?>" class="btn btn-primary">
                <i class="fas fa-plus"></i> Add New Acquisition
            </a>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="filters">
        <form method="GET" action="view-acquisition-forms.php" style="width: 100%; display: flex; flex-wrap: wrap; gap: 15px;">
            <div class="filter-item">
                <label for="job_code">Case ID / Job Code:</label>
                <input type="text" id="job_code" name="job_code" value="<?= htmlspecialchars($job_code) ?>" placeholder="Enter case ID">
            </div>
            
            <div class="filter-item">
                <label for="investigator">Investigator:</label>
                <input type="text" id="investigator" name="investigator" value="<?= htmlspecialchars($_GET['investigator'] ?? '') ?>" placeholder="Investigator name">
            </div>
            
            <div class="filter-item">
                <label for="date_from">Date From:</label>
                <input type="date" id="date_from" name="date_from" value="<?= htmlspecialchars($_GET['date_from'] ?? '') ?>">
            </div>
            
            <div class="filter-item">
                <label for="date_to">Date To:</label>
                <input type="date" id="date_to" name="date_to" value="<?= htmlspecialchars($_GET['date_to'] ?? '') ?>">
            </div>
            
            <div class="filter-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Search
                </button>
                
                <a href="view-acquisition-forms.php" class="btn btn-outline">
                    <i class="fas fa-times"></i> Clear
                </a>
            </div>
        </form>
    </div>
    
    <?php if (empty($acquisitions)): ?>
    <div class="empty-state">
        <i class="fas fa-search empty-state-icon"></i>
        <h3>No Acquisition Records Found</h3>
        <?php if (!empty($job_code)): ?>
            <p>No acquisition records found for case ID: <?= htmlspecialchars($job_code) ?></p>
            <a href="acquisition-form.php?job_code=<?= urlencode($job_code) ?>" class="btn btn-primary">
                <i class="fas fa-plus"></i> Create New Acquisition
            </a>
        <?php else: ?>
            <p>No acquisition records match your search criteria or no records exist in the system.</p>
            <a href="acquisition-form.php" class="btn btn-primary">
                <i class="fas fa-plus"></i> Create New Acquisition
            </a>
        <?php endif; ?>
    </div>
    <?php else: ?>
    
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <div>
            <strong>Total Records:</strong> <?= $total_records ?>
        </div>
        
        <div>
            <a href="acquisition-form.php<?= !empty($job_code) ? '?job_code=' . urlencode($job_code) : '' ?>" class="btn btn-success">
                <i class="fas fa-plus"></i> New Acquisition
            </a>
        </div>
    </div>
    
    <div style="overflow-x: auto;">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Case ID</th>
                    <th>Investigator</th>
                    <th>Date</th>
                    <th>Device</th>
                    <th>Serial Number</th>
                    <th>Tool</th>
                    <th>Format</th>
                    <th>Hash Match</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($acquisitions as $acq): ?>
                <tr>
                    <td><?= $acq['id'] ?></td>
                    <td><?= htmlspecialchars($acq['case_id']) ?></td>
                    <td><?= htmlspecialchars($acq['investigator_name']) ?></td>
                    <td><?= formatDate($acq['acquisition_date']) ?></td>
                    <td><?= htmlspecialchars($acq['make_model']) ?></td>
                    <td><?= htmlspecialchars($acq['serial_number']) ?></td>
                    <td><?= htmlspecialchars($acq['imaging_tool']) ?></td>
                    <td><?= htmlspecialchars($acq['imaging_format']) ?></td>
                    <td>
                        <?php if ($acq['hash_match'] == 'Yes'): ?>
                            <span style="color: #2b8a3e;"><i class="fas fa-check-circle"></i> Yes</span>
                        <?php elseif ($acq['hash_match'] == 'No'): ?>
                            <span style="color: #e03131;"><i class="fas fa-times-circle"></i> No</span>
                        <?php else: ?>
                            <span style="color: #868e96;"><i class="fas fa-question-circle"></i> N/A</span>
                        <?php endif; ?>
                    </td>
                    <td class="actions-column">
                        <a href="view-acquisition.php?id=<?= $acq['id'] ?>" class="action-link" title="View">
                            <i class="fas fa-eye"></i>
                        </a>
                        
                        <a href="edit-acquisition.php?id=<?= $acq['id'] ?>" class="action-link" title="Edit">
                            <i class="fas fa-edit"></i>
                        </a>
                        
                        <form class="delete-form" method="POST" onsubmit="return confirm('Are you sure you want to delete this acquisition record? This action cannot be undone.');">
                            <input type="hidden" name="acquisition_id" value="<?= $acq['id'] ?>">
                            <button type="submit" name="delete_acquisition" class="action-link" style="background:none; border:none; cursor:pointer; color:#dc3545;" title="Delete">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </form>
                        
                        <?php if (!empty($acq['uploaded_log_file'])): ?>
                        <a href="uploads/logs/<?= htmlspecialchars($acq['uploaded_log_file']) ?>" class="action-link" title="Download Log" download>
                            <i class="fas fa-file-download"></i>
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?page=1<?= !empty($job_code) ? '&job_code=' . urlencode($job_code) : '' ?>">&laquo; First</a>
            <a href="?page=<?= ($page-1) ?><?= !empty($job_code) ? '&job_code=' . urlencode($job_code) : '' ?>">&lsaquo; Prev</a>
        <?php else: ?>
            <span class="disabled">&laquo; First</span>
            <span class="disabled">&lsaquo; Prev</span>
        <?php endif; ?>
        
        <?php
        // Calculate range of page numbers to display
        $range = 2;
        $start_page = max(1, $page - $range);
        $end_page = min($total_pages, $page + $range);
        
        // Always show first page if not in range
        if ($start_page > 1) {
            echo '<a href="?page=1' . (!empty($job_code) ? '&job_code=' . urlencode($job_code) : '') . '">1</a>';
            if ($start_page > 2) {
                echo '<span class="disabled">...</span>';
            }
        }
        
        // Display page numbers
        for ($i = $start_page; $i <= $end_page; $i++) {
            if ($i == $page) {
                echo '<span class="active">' . $i . '</span>';
            } else {
                echo '<a href="?page=' . $i . (!empty($job_code) ? '&job_code=' . urlencode($job_code) : '') . '">' . $i . '</a>';
            }
        }
        
        // Always show last page if not in range
        if ($end_page < $total_pages) {
            if ($end_page < $total_pages - 1) {
                echo '<span class="disabled">...</span>';
            }
            echo '<a href="?page=' . $total_pages . (!empty($job_code) ? '&job_code=' . urlencode($job_code) : '') . '">' . $total_pages . '</a>';
        }
        ?>
        
        <?php if ($page < $total_pages): ?>
            <a href="?page=<?= ($page+1) ?><?= !empty($job_code) ? '&job_code=' . urlencode($job_code) : '' ?>">Next &rsaquo;</a>
            <a href="?page=<?= $total_pages ?><?= !empty($job_code) ? '&job_code=' . urlencode($job_code) : '' ?>">Last &raquo;</a>
        <?php else: ?>
            <span class="disabled">Next &rsaquo;</span>
            <span class="disabled">Last &raquo;</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <?php endif; ?>
    
</div>

<script>
    // Confirm delete
    document.addEventListener('DOMContentLoaded', function() {
        const deleteButtons = document.querySelectorAll('.delete-form');
        
        deleteButtons.forEach(form => {
            form.addEventListener('submit', function(e) {
                if (!confirm('Are you sure you want to delete this acquisition record? This action cannot be undone.')) {
                    e.preventDefault();
                }
            });
        });
    });
</script>

</body>
</html>