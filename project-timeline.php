<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

define('CURRENT_TIMESTAMP', '2025-06-01 20:19:01');
define('CURRENT_USER', 'nkosinathil');

include_once('db-connection.php');
try {
    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
    $pdo = new PDO($dsn, $user, $pass, $options);

    // Get filter parameters with defaults
    $client_code = $_GET['client_code'] ?? '';
    $case_number = $_GET['case_number'] ?? '';
    $start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
    $end_date = $_GET['end_date'] ?? date('Y-m-d');

    // Simplified timeline query to debug the issue
    $timeline_sql = "
        SELECT 
            'timesheet' as event_type,
            ts.id as event_id,
            'Timesheet Entry' as title,
            CONCAT('Hours logged: ', ts.total_hours) as description,
            ts.status,
            ts.created_at as event_date,
            CONCAT(e.name, ' ', e.surname) as user_name,
            ts.case_number,
            c.company_name,
            ts.week_start as additional_data
        FROM timesheets ts
        JOIN employee e ON ts.employee_id = e.id
        JOIN cases cs ON ts.case_number = cs.case_number
        JOIN clients c ON cs.client_code = c.client_code
        WHERE ts.created_at BETWEEN ? AND ?
    ";

    if ($client_code) {
        $timeline_sql .= " AND c.client_code = ?";
    }

    if ($case_number) {
        $timeline_sql .= " AND cs.case_number = ?";
    }

    $timeline_sql .= " ORDER BY ts.created_at DESC";

    // Prepare parameters
    $params = [$start_date, $end_date];
    if ($client_code) $params[] = $client_code;
    if ($case_number) $params[] = $case_number;

    // Execute query
    $timeline_stmt = $pdo->prepare($timeline_sql);
    $timeline_stmt->execute($params);
    $timeline_events = $timeline_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get clients for filter
    $clients_stmt = $pdo->query("
        SELECT DISTINCT c.client_code, c.company_name 
        FROM clients c 
        JOIN cases cs ON c.client_code = cs.client_code
        ORDER BY c.company_name
    ");
    $clients = $clients_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get cases for filter
    $cases_sql = "
        SELECT DISTINCT cs.case_number, cs.case_description
        FROM cases cs
        WHERE 1=1
    ";
    if ($client_code) {
        $cases_sql .= " AND cs.client_code = ?";
        $cases_params = [$client_code];
    } else {
        $cases_params = [];
    }
    $cases_sql .= " ORDER BY cs.created_at DESC";
    
    $cases_stmt = $pdo->prepare($cases_sql);
    $cases_stmt->execute($cases_params);
    $cases = $cases_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    error_log('Timeline Error: ' . $e->getMessage());
    $error_message = "Error loading timeline data. Please contact system administrator.";
    // For debugging only:
    // $error_message = $e->getMessage();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Project Timeline</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
	<style>
    :root {
        --gi-black: #000000;
        --gi-yellow: #ffd700;
        --gi-white: #ffffff;
        --gi-gray-light: #f5f6fa;
        --gi-gray-medium: #e9ecef;
        --gi-gray-dark: #2d3436;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
        font-family: 'Inter', sans-serif;
    }

    body {
        background-color: var(--gi-gray-light);
        color: var(--gi-gray-dark);
        padding-top: 60px;
    }

    .top-bar {
        background-color: var(--gi-black);
        color: var(--gi-white);
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

    .timeline-container {
        max-width: 1200px;
        margin: 40px auto;
        padding: 0 20px;
    }

    .filters-section {
        background: var(--gi-white);
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        margin-bottom: 30px;
    }

    .filters-section h2 {
        color: var(--gi-black);
        margin-bottom: 20px;
    }

    .primary-button {
        background: var(--gi-yellow);
        color: var(--gi-black);
        border: none;
        padding: 8px 16px;
        border-radius: 4px;
        cursor: pointer;
        font-weight: 500;
    }

    .secondary-button {
        background: var(--gi-gray-medium);
        color: var(--gi-black);
        border: none;
        padding: 8px 16px;
        border-radius: 4px;
        cursor: pointer;
        font-weight: 500;
    }

    .timeline-icon {
        background: var(--gi-white);
        border: 2px solid var(--gi-yellow);
    }

    .timeline-icon i {
        color: var(--gi-black);
    }

    .status-badge {
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 0.9em;
    }

    .status-badge.active {
        background: var(--gi-yellow);
        color: var(--gi-black);
    }

    .status-badge.completed {
        background: var(--gi-yellow);
        color: var(--gi-black);
    }

    .timeline-item {
        border-left: 4px solid var(--gi-yellow);
    }

    .auth-link {
        color: var(--gi-yellow);
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 16px;
        border-radius: 4px;
        transition: background-color 0.3s;
    }

    .auth-link:hover {
        background-color: rgba(255, 215, 0, 0.1);
    }

    .filter-group select,
    .filter-group input {
        border: 1px solid var(--gi-gray-medium);
        padding: 8px 12px;
        border-radius: 4px;
        width: 100%;
    }

    .filter-group select:focus,
    .filter-group input:focus {
        border-color: var(--gi-yellow);
        outline: none;
        box-shadow: 0 0 0 2px rgba(255, 215, 0, 0.2);
    }

    .error-message {
        background-color: #ffe3e3;
        border-left: 4px solid #e03131;
    }

    .no-events {
        text-align: center;
        padding: 40px;
        background: var(--gi-white);
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .no-events i {
        font-size: 48px;
        color: var(--gi-yellow);
        margin-bottom: 16px;
    }

    @media (max-width: 768px) {
        .filters-form {
            grid-template-columns: 1fr;
        }

        .timeline-date {
            margin-left: 60px;
        }

        .timeline-item {
            margin-left: 60px;
        }

        .timeline-meta {
            flex-direction: column;
            gap: 10px;
        }
    }
</style>
</head>
<body>
    <div class="top-bar">
        <div class="container">
            <div class="system-info">
                <span class="timestamp">Current Date and Time (UTC - YYYY-MM-DD HH:MM:SS formatted): <?= CURRENT_TIMESTAMP ?></span>
                <span class="username">Current User's Login: <?= CURRENT_USER ?></span>
            </div>
            <div class="auth-buttons">
                <a href="dashboard.php" class="auth-link"><i class="fas fa-home"></i> Dashboard</a>
                <a href="logout.php" class="auth-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </div>

    <div class="timeline-container">
        <?php if (isset($error_message)): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i>
                <?= htmlspecialchars($error_message) ?>
            </div>
        <?php endif; ?>

        <div class="filters-section">
            <h2>Project Timeline</h2>
            <form id="filterForm" method="get" class="filters-form">
                <div class="filter-group">
                    <label for="client_code">Client:</label>
                    <select name="client_code" id="client_code">
                        <option value="">All Clients</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?= htmlspecialchars($client['client_code']) ?>"
                                <?= $client_code === $client['client_code'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($client['company_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="case_number">Case Number:</label>
                    <select name="case_number" id="case_number">
                        <option value="">All Cases</option>
                        <?php foreach ($cases as $case): ?>
                            <option value="<?= htmlspecialchars($case['case_number']) ?>"
                                <?= $case_number === $case['case_number'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($case['case_number']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Date Range:</label>
                    <div class="date-inputs">
                        <input type="text" name="start_date" id="start_date" 
                               class="datepicker" placeholder="Start Date"
                               value="<?= htmlspecialchars($start_date) ?>">
                        <span>to</span>
                        <input type="text" name="end_date" id="end_date" 
                               class="datepicker" placeholder="End Date"
                               value="<?= htmlspecialchars($end_date) ?>">
                    </div>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="primary-button">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                    <button type="button" onclick="resetFilters()" class="secondary-button">
                        <i class="fas fa-undo"></i> Reset
                    </button>
                </div>
            </form>
        </div>

        <div class="timeline">
            <?php if (empty($timeline_events)): ?>
                <div class="no-events">
                    <i class="fas fa-info-circle"></i>
                    <p>No events found for the selected filters.</p>
                </div>
            <?php else: ?>
                <?php 
                $current_date = null;
                foreach ($timeline_events as $event): 
                    $event_date = date('Y-m-d', strtotime($event['event_date']));
                    if ($event_date !== $current_date):
                        $current_date = $event_date;
                ?>
                    <div class="timeline-date">
                        <span><?= date('F j, Y', strtotime($event_date)) ?></span>
                    </div>
                <?php endif; ?>

                <div class="timeline-item">
                    <div class="timeline-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="timeline-content">
                        <div class="timeline-header">
                            <h3><?= htmlspecialchars($event['title']) ?></h3>
                            <span class="status-badge <?= strtolower($event['status']) ?>">
                                <?= htmlspecialchars($event['status']) ?>
                            </span>
                        </div>
                        <p class="description"><?= htmlspecialchars($event['description']) ?></p>
                        <div class="timeline-meta">
                            <span class="case">
                                <i class="fas fa-folder"></i>
                                <?= htmlspecialchars($event['case_number']) ?>
                            </span>
                            <span class="client">
                                <i class="fas fa-building"></i>
                                <?= htmlspecialchars($event['company_name']) ?>
                            </span>
                            <span class="user">
                                <i class="fas fa-user"></i>
                                <?= htmlspecialchars($event['user_name']) ?>
                            </span>
                            <span class="time">
                                <i class="fas fa-clock"></i>
                                <?= date('H:i', strtotime($event['event_date'])) ?>
                            </span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <style>
        /* Your existing CSS here */
    </style>

    <script>
        // Initialize date pickers
        flatpickr(".datepicker", {
            dateFormat: "Y-m-d",
            maxDate: "today"
        });

        // Reset filters
        function resetFilters() {
            document.getElementById('client_code').value = '';
            document.getElementById('case_number').value = '';
            document.getElementById('start_date').value = '';
            document.getElementById('end_date').value = '';
            document.getElementById('filterForm').submit();
        }
    </script>
</body>
</html>