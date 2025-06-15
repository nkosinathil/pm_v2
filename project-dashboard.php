<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Session and role check
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

define('CURRENT_TIMESTAMP', '2025-06-01 19:50:50');
define('CURRENT_USER', 'nkosinathil');

include_once('db-connection.php');
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
$pdo = new PDO($dsn, $user, $pass, $options);

// At the beginning of the PHP section, add default values
$project = [
    'case_number' => 'N/A',
    'company_name' => 'N/A',
    'team_members' => '',
    'total_tasks' => 0,
    'completed_tasks' => 0,
    'total_hours' => 0
];

// Get project ID from URL or default to user's first project
$project_id = $_GET['id'] ?? null;
if (!$project_id) {
    // Get user's first project if no ID specified
    $stmt = $pdo->prepare("
        SELECT p.id 
        FROM projects p 
        JOIN project_users pu ON p.id = pu.project_id 
        WHERE pu.employee_id = ? 
        LIMIT 1
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $project_id = $stmt->fetchColumn();
}

try {
    // Fetch project details
    $project_stmt = $pdo->prepare("
        SELECT 
            p.*,
            c.company_name,
            c.client_code,
            GROUP_CONCAT(DISTINCT CONCAT(e.name, ' ', e.surname)) as team_members,
            COUNT(DISTINCT t.id) as total_tasks,
            SUM(CASE WHEN t.status = 'Completed' THEN 1 ELSE 0 END) as completed_tasks,
            SUM(ts.total_hours) as total_hours
        FROM projects p
        LEFT JOIN cases cs ON p.case_number = cs.case_number
        LEFT JOIN clients c ON cs.client_code = c.client_code
        LEFT JOIN project_users pu ON p.id = pu.project_id
        LEFT JOIN employee e ON pu.employee_id = e.id
        LEFT JOIN tasks t ON p.id = t.project_id
        LEFT JOIN timesheets ts ON p.case_number = ts.case_number
        WHERE p.id = ?
        GROUP BY p.id
    ");
    $project_stmt->execute([$project_id]);
    $project = $project_stmt->fetch();

    if (!$project) {
        throw new Exception("Project not found");
    }

    // Fetch recent tasks
    $tasks_stmt = $pdo->prepare("
        SELECT t.*, e.name as assigned_to_name, e.surname as assigned_to_surname
        FROM tasks t
        LEFT JOIN employee e ON t.assigned_to = e.id
        WHERE t.project_id = ?
        ORDER BY t.created_at DESC
        LIMIT 5
    ");
    $tasks_stmt->execute([$project_id]);
    $recent_tasks = $tasks_stmt->fetchAll();

    // Fetch recent timesheets
    $timesheets_stmt = $pdo->prepare("
        SELECT ts.*, e.name, e.surname
        FROM timesheets ts
        JOIN employee e ON ts.employee_id = e.id
        WHERE ts.case_number = ?
        ORDER BY ts.week_start DESC
        LIMIT 5
    ");
    $timesheets_stmt->execute([$project['case_number']]);
    $recent_timesheets = $timesheets_stmt->fetchAll();

    // Fetch documents (Chain of Custody, etc.)
    $documents_stmt = $pdo->prepare("
        SELECT cl.*, cd.description
        FROM custody_logs cl
        JOIN custody_devices cd ON cl.job_code = cd.job_code
        WHERE cl.job_code = ?
        ORDER BY cl.released_by_datetime DESC
        LIMIT 5
    ");
    $documents_stmt->execute([$project['case_number']]);
    $recent_documents = $documents_stmt->fetchAll();

} catch (Exception $e) {
    error_log('Project Dashboard Error: ' . $e->getMessage());
    $error_message = "Error loading project data.";
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Project Dashboard - <?= htmlspecialchars($project['case_number']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="top-bar">
        <div class="container">
            <div class="top-bar-content">
                <div class="system-info">
                    <span class="timestamp">Current Date and Time (UTC - YYYY-MM-DD HH:MM:SS formatted): <?= CURRENT_TIMESTAMP ?></span>
                    <span class="username">Current User's Login: <?= CURRENT_USER ?></span>
                </div>
                <div class="auth-buttons">
                    <a href="dashboard.php" class="auth-link"><i class="fas fa-home"></i> Main Dashboard</a>
                    <a href="logout.php" class="auth-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
                </div>
            </div>
        </div>
    </div>

    <div class="project-container">
        <!-- Project Header -->
        <div class="project-header">
            <div class="project-title">
                <h1><?= htmlspecialchars($project['case_number']) ?></h1>
                <span class="client-name"><?= htmlspecialchars($project['company_name']) ?></span>
            </div>
            <div class="project-actions">
                <?php if ($_SESSION['role'] === 'Admin' || $_SESSION['role'] === 'Manager'): ?>
                    <button onclick="location.href='edit-project.php?id=<?= $project_id ?>'" class="action-button">
                        <i class="fas fa-edit"></i> Edit Project
                    </button>
                <?php endif; ?>
                <button onclick="location.href='task-management.php?project_id=<?= $project_id ?>'" class="action-button">
                    <i class="fas fa-tasks"></i> Manage Tasks
                </button>
                <button onclick="location.href='timesheet.php?case_number=<?= urlencode($project['case_number']) ?>'" class="action-button">
                    <i class="fas fa-clock"></i> Submit Time
                </button>
            </div>
        </div>

        <!-- Project Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <i class="fas fa-users"></i>
                <h3>Team Members</h3>
                <div class="stat-content">
                    <?php
                    $members = explode(',', $project['team_members']);
                    foreach ($members as $member): ?>
                        <span class="team-member"><?= htmlspecialchars($member) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="stat-card">
                <i class="fas fa-tasks"></i>
                <h3>Task Progress</h3>
                <div class="stat-content">
                    <div class="progress-bar">
                        <?php 
                        $progress = $project['total_tasks'] ? 
                            ($project['completed_tasks'] / $project['total_tasks']) * 100 : 0;
                        ?>
                        <div class="progress" style="width: <?= $progress ?>%"></div>
                    </div>
                    <span class="progress-text">
                        <?= $project['completed_tasks'] ?>/<?= $project['total_tasks'] ?> Tasks Complete
                    </span>
                </div>
            </div>

            <div class="stat-card">
                <i class="fas fa-clock"></i>
                <h3>Total Hours</h3>
                <div class="stat-content">
                    <div class="stat-number"><?= number_format($project['total_hours'], 1) ?></div>
                    <span class="stat-label">Hours Logged</span>
                </div>
            </div>
        </div>

        <!-- Activity Grid -->
        <div class="activity-grid">
            <!-- Recent Tasks -->
            <div class="activity-card">
                <div class="card-header">
                    <h3><i class="fas fa-list-check"></i> Recent Tasks</h3>
                    <a href="task-management.php?project_id=<?= $project_id ?>" class="view-all">View All</a>
                </div>
                <div class="card-content">
                    <?php if (!empty($recent_tasks)): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Task</th>
                                    <th>Assigned To</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_tasks as $task): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($task['task_name']) ?></td>
                                        <td><?= htmlspecialchars($task['assigned_to_name'] . ' ' . $task['assigned_to_surname']) ?></td>
                                        <td>
                                            <span class="status-badge <?= strtolower($task['status']) ?>">
                                                <?= htmlspecialchars($task['status']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="no-data">No tasks found for this project.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Timesheets -->
            <div class="activity-card">
                <div class="card-header">
                    <h3><i class="fas fa-clock"></i> Recent Timesheets</h3>
                    <a href="timesheet-list.php?case_number=<?= urlencode($project['case_number']) ?>" class="view-all">View All</a>
                </div>
                <div class="card-content">
                    <?php if (!empty($recent_timesheets)): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Week</th>
                                    <th>Hours</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_timesheets as $timesheet): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($timesheet['name'] . ' ' . $timesheet['surname']) ?></td>
                                        <td><?= date('Y-m-d', strtotime($timesheet['week_start'])) ?></td>
                                        <td><?= $timesheet['total_hours'] ?></td>
                                        <td>
                                            <span class="status-badge <?= strtolower($timesheet['status']) ?>">
                                                <?= htmlspecialchars($timesheet['status']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="no-data">No timesheets found for this project.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Documents -->
            <div class="activity-card">
                <div class="card-header">
                    <h3><i class="fas fa-file-alt"></i> Recent Documents</h3>
                    <a href="document-list.php?case_number=<?= urlencode($project['case_number']) ?>" class="view-all">View All</a>
                </div>
                <div class="card-content">
                    <?php if (!empty($recent_documents)): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Description</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_documents as $doc): ?>
                                    <tr>
                                        <td>Chain of Custody</td>
                                        <td><?= htmlspecialchars($doc['description']) ?></td>
                                        <td><?= date('Y-m-d', strtotime($doc['released_by_datetime'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="no-data">No documents found for this project.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

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

        .project-container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .project-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .project-title h1 {
            font-size: 2em;
            margin-bottom: 5px;
        }

        .client-name {
            color: #636e72;
            font-size: 1.1em;
        }

        .project-actions {
            display: flex;
            gap: 10px;
        }

        .action-button {
            padding: 8px 16px;
            background: #ffd32a;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .stat-card i {
            font-size: 24px;
            color: #ffd32a;
            margin-bottom: 10px;
        }

        .stat-content {
            margin-top: 15px;
        }

        .team-member {
            display: inline-block;
            padding: 4px 8px;
            background: #f5f6fa;
            border-radius: 12px;
            margin: 2px;
            font-size: 0.9em;
        }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            margin-bottom: 10px;
            overflow: hidden;
        }

        .progress {
            height: 100%;
            background: #ffd32a;
            transition: width 0.3s ease;
        }

        .progress-text {
            font-size: 0.9em;
            color: #636e72;
        }

        .stat-number {
            font-size: 24px;
            font-weight: 600;
            color: #2d3436;
        }

        .stat-label {
            font-size: 0.9em;
            color: #636e72;
        }

        .activity-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
        }

        .activity-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
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
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.1em;
        }

        .view-all {
            color: #2d3436;
            text-decoration: none;
            font-size: 0.9em;
        }

        .card-content {
            padding: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.9em;
        }

        .status-badge.pending {
            background: #fff3bf;
            color: #e67700;
        }

        .status-badge.completed {
            background: #c8ffd4;
            color: #2f9e44;
        }

        .status-badge.submitted {
            background: #e3fafc;
            color: #1098ad;
        }

        .no-data {
            text-align: center;
            color: #636e72;
            padding: 20px;
        }

        @media (max-width: 768px) {
            .project-header {
                flex-direction: column;
                gap: 20px;
                text-align: center;
            }

            .project-actions {
                flex-direction: column;
                width: 100%;
            }

            .action-button {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</body>
</html>