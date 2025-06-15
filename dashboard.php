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

define('CURRENT_TIMESTAMP', gmdate('Y-m-d H:i:s'));
define('CURRENT_USER', $_SESSION['username'] ?? 'nkosinathil');

include_once('db-connection.php');

// Initialize cases array to prevent undefined variable error
$cases = [];
$error_message = '';

try {
    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
    $pdo = new PDO($dsn, $user, $pass, $options);

    // Fetch summary statistics
    $stats = [
        'total_cases' => $pdo->query("
            SELECT COUNT(*) FROM cases
        ")->fetchColumn(),
        
        'active_projects' => $pdo->query("
            SELECT COUNT(*) FROM projects p
            JOIN cases c ON p.case_number = c.case_number
            WHERE c.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ")->fetchColumn(),
        
        'consent_forms' => $pdo->query("
            SELECT COUNT(*) FROM consent_forms
        ")->fetchColumn(),
        
        'custody_logs' => $pdo->query("
            SELECT COUNT(*) FROM custody_logs
        ")->fetchColumn()
    ];

    // Fetch all cases with related information
    $sql = "
        SELECT 
            c.id,
            cl.company_name as client,
            c.case_number,
            c.created_at,
            c.client_code,
            c.case_description,
            c.rep_name,
            c.rep_surname,
            c.rep_phone,
            c.rep_email,
            
            COALESCE((SELECT 1 FROM consent_forms cf WHERE cf.client_code = c.client_code LIMIT 1), 0) as has_consent_form,
            COALESCE((SELECT 1 FROM custody_logs coc WHERE coc.job_code = c.case_number LIMIT 1), 0) as has_coc,
            
            (
                SELECT COUNT(*) 
                FROM custody_devices cd 
                WHERE cd.job_code = c.case_number
            ) as total_devices,
            
            (
                SELECT COUNT(*) 
                FROM acquisition_forms af 
                WHERE af.case_id = c.case_number
            ) as completed_acquisitions,
            
            COALESCE((SELECT 1 FROM acquisition_forms af WHERE af.case_id = c.case_number LIMIT 1), 0) as has_acquisition,
            
            COALESCE((SELECT 1 FROM invoices i WHERE i.case_number = c.case_number LIMIT 1), 0) as has_invoice,
            
            (SELECT status FROM invoices i WHERE i.case_number = c.case_number ORDER BY id DESC LIMIT 1) as invoice_status,
            
            CASE 
                WHEN EXISTS (SELECT 1 FROM invoices i WHERE i.case_number = c.case_number AND i.status = 'Paid' LIMIT 1)
                THEN 1
                ELSE 0
            END as invoice_paid,
            
            /* Removed reference to reports table that doesn't exist */
            0 as has_report
        FROM cases c
        LEFT JOIN clients cl ON c.client_code = cl.client_code
        GROUP BY c.id
        ORDER BY c.created_at DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Database Error: ' . $e->getMessage());
    $error_message = "Error loading dashboard data: " . $e->getMessage();
    // Initialize cases as empty array to avoid undefined variable error
    $cases = [];
}

// Calculate overall completion percentage for each case
foreach ($cases as &$case) {
    // Define all steps for case completion
    $total_steps = 4; // Consent, CoC, Acquisition, Report
    $completed_steps = 0;
    
    if ($case['has_consent_form']) $completed_steps++;
    if ($case['has_coc']) $completed_steps++;
    
    // Only count acquisition as complete if all devices are acquired
    if ($case['has_acquisition'] && $case['total_devices'] > 0 && $case['completed_acquisitions'] == $case['total_devices']) {
        $completed_steps++;
    }
    
    // We'll handle the report differently since there's no table
    // You can check if a file exists in a specific location
    if (file_exists('reports/' . $case['case_number'] . '.pdf')) {
        $completed_steps++;
        $case['has_report'] = 1;
    }
    
    $case['completion_percentage'] = ($total_steps > 0) ? (($completed_steps / $total_steps) * 100) : 0;
    
    // Determine status class for styling
    if ($case['completion_percentage'] == 0) $case['status_class'] = 'new';
    elseif ($case['completion_percentage'] == 100) $case['status_class'] = 'completed';
    else $case['status_class'] = 'in-progress';
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Dashboard - Project Management System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="top-bar">
        <div class="container">
            <div class="system-info">
                <span class="timestamp">Current Date and Time (UTC): <?= CURRENT_TIMESTAMP ?></span>
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
                    <li class="active">
                        <a href="dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
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
                    <li>
                        <a href="acquisition-forms.php">
                            <i class="fas fa-laptop"></i> Acquisition Forms
                        </a>
                    </li>
                </ul>
            </div>

            <div class="menu-section">
                <h3>Billing</h3>
                <ul>
                    <li>
                        <a href="../inv/invoices.php">
                            <i class="fas fa-file-invoice-dollar"></i> Invoices
                        </a>
                    </li>
                </ul>
            </div>

            <?php if ($_SESSION['role'] === 'Admin'): ?>
            <div class="menu-section">
                <h3>Administration</h3>
                <ul>
                    <li>
                        <a href="user-management.php">
                            <i class="fas fa-users-cog"></i> User Management
                        </a>
                    </li>
                    <li>
                        <a href="audit-logs.php">
                            <i class="fas fa-history"></i> Audit Logs
                        </a>
                    </li>
                </ul>
            </div>
            <?php endif; ?>
            
            <div class="sidebar-footer">
                <a href="logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </nav>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="dashboard-header">
                <h1>Welcome, <?= htmlspecialchars($_SESSION['name'] ?? CURRENT_USER) ?></h1>
                <div class="quick-actions">
                    <button onclick="location.href='create-case.php'" class="action-button primary">
                        <i class="fas fa-plus"></i> New Case
                    </button>
                    <button onclick="location.href='assign-case.php'" class="action-button secondary">
                        <i class="fas fa-user-plus"></i> Assign Employee
                    </button>
                    <button onclick="location.href='task-management.php'" class="action-button">
                        <i class="fas fa-tasks"></i> Create Task
                    </button>
                    <button onclick="location.href='../inv/create-invoice.php'" class="action-button">
                        <i class="fas fa-file-invoice"></i> Create Invoice
                    </button>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <i class="fas fa-folder"></i>
                    <h3>Total Cases</h3>
                    <div class="stat-number"><?= isset($stats['total_cases']) ? number_format($stats['total_cases']) : '0' ?></div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-project-diagram"></i>
                    <h3>Active Projects</h3>
                    <div class="stat-number"><?= isset($stats['active_projects']) ? number_format($stats['active_projects']) : '0' ?></div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-file-signature"></i>
                    <h3>Consent Forms</h3>
                    <div class="stat-number"><?= isset($stats['consent_forms']) ? number_format($stats['consent_forms']) : '0' ?></div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-exchange-alt"></i>
                    <h3>Custody Transfers</h3>
                    <div class="stat-number"><?= isset($stats['custody_logs']) ? number_format($stats['custody_logs']) : '0' ?></div>
                </div>
            </div>

            <!-- Cases Table -->
            <div class="cases-section">
                <div class="section-header">
                    <h2>Case Management</h2>
                    <div class="filter-controls">
                        <input type="text" id="caseFilter" placeholder="Search cases..." class="search-input">
                        <select id="statusFilter" class="filter-select">
                            <option value="all">All Status</option>
                            <option value="new">New</option>
                            <option value="in-progress">In Progress</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>
                </div>

                <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= $error_message ?>
                </div>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="cases-table" id="casesTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Client</th>
                                <th>Case</th>
                                <th>Consent Form</th>
                                <th>Chain of Custody</th>
                                <th>Acquisition Form</th>
                                <th>Status</th>
                                <th>Report</th>
                                <th>Generate Invoice</th>
                                <th>Payment Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($cases)): ?>
                                <tr>
                                    <td colspan="11" class="no-data-cell">
                                        <div class="no-data">
                                            <i class="fas fa-folder-open"></i>
                                            <p>No cases found. Click "New Case" to create one.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($cases as $index => $case): ?>
                                    <tr>
                                        <td><?= $index + 1 ?></td>
                                        <td><?= htmlspecialchars($case['client']) ?></td>
                                        <td>
                                            <a href="view-case.php?id=<?= $case['id'] ?>" class="case-link">
                                                <?= htmlspecialchars($case['case_number']) ?>
                                            </a>
                                        </td>
                                        <td>
                                            <?php if ($case['has_consent_form']): ?>
                                                <a href="view-consent.php?id=<?= $case['id'] ?>" class="view-link">
                                                    <i class="fas fa-eye"></i> View Form
                                                </a>
                                            <?php else: ?>
                                                <a href="consent-form.php?id=<?= $case['id'] ?>&case=<?= urlencode($case['case_number']) ?>&client=<?= urlencode($case['client_code']) ?>" 
                                                   class="action-link">
                                                    <i class="fas fa-plus-circle"></i> Create Form
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($case['has_coc']): ?>
                                                <a href="view-coc.php?id=<?= $case['id'] ?>&case=<?= urlencode($case['case_number']) ?>" 
                                                   class="view-link">
                                                    <i class="fas fa-eye"></i> View CoC
                                                </a>
                                            <?php else: ?>
                                                <a href="create-coc.php?id=<?= $case['id'] ?>&case=<?= urlencode($case['case_number']) ?>&client=<?= urlencode($case['client_code']) ?>" 
                                                   class="action-link">
                                                    <i class="fas fa-plus-circle"></i> Create CoC
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $acquisition_status = '';
                                            $acquisition_percentage = 0;
                                            if ($case['total_devices'] > 0) {
                                                $acquisition_percentage = ($case['completed_acquisitions'] / $case['total_devices']) * 100;
                                                $acquisition_status = "({$case['completed_acquisitions']}/{$case['total_devices']})";
                                            }
                                            ?>
                                            <?php if ($case['has_acquisition']): ?>
                                                <div class="mini-progress">
                                                    <div class="mini-progress-bar">
                                                        <div class="mini-progress-fill" style="width: <?= $acquisition_percentage ?>%;"></div>
                                                    </div>
                                                    <span class="mini-progress-text"><?= $acquisition_status ?></span>
                                                    <a href="view-acquisition.php?case=<?= urlencode($case['case_number']) ?>" class="view-link">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                </div>
                                            <?php else: ?>
                                                <?php if ($case['has_coc'] && $case['total_devices'] > 0): ?>
                                                    <a href="create-acquisition.php?case=<?= urlencode($case['case_number']) ?>" 
                                                       class="action-link">
                                                        <i class="fas fa-plus-circle"></i> Create Forms
                                                    </a>
                                                <?php else: ?>
                                                    <span class="status-badge na">
                                                        <i class="fas fa-exclamation-circle"></i> Need CoC
                                                    </span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="progress-bar <?= $case['status_class'] ?>">
                                                <div class="progress" style="width: <?= $case['completion_percentage'] ?>%"></div>
                                                <span class="progress-text"><?= number_format($case['completion_percentage']) ?>%</span>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if (file_exists("reports/{$case['case_number']}.pdf")): ?>
                                                <a href="reports/<?= urlencode($case['case_number']) ?>.pdf" 
                                                   class="view-link" target="_blank">
                                                    <i class="fas fa-file-pdf"></i> View
                                                </a>
                                            <?php else: ?>
                                                <div class="report-actions">
                                                    <a href="upload-report.php?case=<?= urlencode($case['case_number']) ?>" 
                                                       class="action-link">
                                                        <i class="fas fa-upload"></i> Upload
                                                    </a>
                                                    <button class="mark-not-needed" data-case="<?= $case['case_number'] ?>"
                                                            title="Mark as not needed">N/A</button>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($case['has_invoice']): ?>
                                                <a href="../inv/view-invoice.php?case=<?= urlencode($case['case_number']) ?>" 
                                                   class="view-link">
                                                    <i class="fas fa-file-invoice"></i> View
                                                </a>
                                            <?php else: ?>
                                                <a href="../inv/create-invoice.php?case=<?= urlencode($case['case_number']) ?>" 
                                                   class="action-link">
                                                    <i class="fas fa-plus-circle"></i> Generate
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($case['has_invoice']): ?>
                                                <span class="status-badge <?= $case['invoice_paid'] ? 'paid' : 'pending' ?>">
                                                    <?= $case['invoice_paid'] ? 'Paid' : 'Pending' ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="status-badge na">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-menu">
                                                <button class="action-menu-button">
                                                    <i class="fas fa-ellipsis-v"></i>
                                                </button>
                                                <div class="action-menu-content">
                                                    <a href="view-case.php?id=<?= $case['id'] ?>">
                                                        <i class="fas fa-eye"></i> View Details
                                                    </a>
                                                    <a href="edit-case.php?id=<?= $case['id'] ?>">
                                                        <i class="fas fa-edit"></i> Edit Case
                                                    </a>
                                                    <a href="assign-employee.php?id=<?= $case['id'] ?>">
                                                        <i class="fas fa-user-plus"></i> Assign Employee
                                                    </a>
                                                    <a href="create-task.php?case=<?= urlencode($case['case_number']) ?>">
                                                        <i class="fas fa-tasks"></i> Create Task
                                                    </a>
                                                    <a href="timeline.php?id=<?= $case['id'] ?>">
                                                        <i class="fas fa-history"></i> View Timeline
                                                    </a>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
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
            background-color: #2d3436;
            color: white;
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
            background: #ffd32a;
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

        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .quick-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .action-button {
            padding: 10px 16px;
            background: #e9ecef;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            transition: all 0.2s ease;
            color: #2d3436;
        }

        .action-button:hover {
            background: #dee2e6;
            transform: translateY(-2px);
        }

        .action-button.primary {
            background: #ffd32a;
            color: #2d3436;
        }

        .action-button.primary:hover {
            background: #f9ca24;
        }

        .action-button.secondary {
            background: #74b9ff;
            color: #fff;
        }

        .action-button.secondary:hover {
            background: #0984e3;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            text-align: center;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .stat-card i {
            font-size: 28px;
            color: #ffd32a;
            margin-bottom: 15px;
        }

        .stat-card h3 {
            font-size: 0.9em;
            color: #636e72;
            margin-bottom: 10px;
        }

        .stat-number {
            font-size: 28px;
            font-weight: 600;
            color: #2d3436;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .filter-controls {
            display: flex;
            gap: 10px;
        }

        .search-input {
            padding: 8px 12px;
            border: 1px solid #e9ecef;
            border-radius: 4px;
            font-size: 0.9em;
            min-width: 220px;
        }

        .filter-select {
            padding: 8px 12px;
            border: 1px solid #e9ecef;
            border-radius: 4px;
            font-size: 0.9em;
            background: white;
        }

        .cases-section {
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 30px;
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

        .alert i {
            font-size: 18px;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .cases-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .cases-table th,
        .cases-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
            font-size: 0.95em;
        }

        .cases-table th {
            background: #f8f9fa;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .cases-table tbody tr {
            transition: background-color 0.2s;
        }

        .cases-table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .no-data-cell {
            text-align: center;
            padding: 40px 0;
        }

        .no-data {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #868e96;
        }

        .no-data i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.4;
        }

        .case-link {
            color: #1098ad;
            font-weight: 500;
            text-decoration: none;
        }

        .case-link:hover {
            text-decoration: underline;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.85em;
            font-weight: 500;
        }

        .status-badge.complete {
            background: #c8ffd4;
            color: #2f9e44;
        }

        .status-badge.progress {
            background: #fff3bf;
            color: #e67700;
        }

        .status-badge.draft {
            background: #e9ecef;
            color: #495057;
        }

        .status-badge.paid {
            background: #c8ffd4;
            color: #2f9e44;
        }

        .status-badge.pending {
            background: #fff3bf;
            color: #e67700;
        }

        .status-badge.na {
            background: #e9ecef;
            color: #868e96;
        }

        .action-link {
            color: #1098ad;
            text-decoration: none;
            font-size: 0.9em;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 8px;
            border-radius: 4px;
            transition: background-color 0.2s;
        }

        .action-link:hover {
            background: #e3f2fd;
        }

        .view-link {
            color: #2d3436;
            text-decoration: none;
            font-size: 0.9em;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 8px;
            border-radius: 4px;
            transition: background-color 0.2s;
        }

        .view-link:hover {
            background: #f1f3f5;
        }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
            position: relative;
        }

        .progress-bar.new .progress {
            background: #495057;
        }

        .progress-bar.in-progress .progress {
            background: #ffd32a;
        }

        .progress-bar.completed .progress {
            background: #2ecc71;
        }

        .progress {
            height: 100%;
            transition: width 0.3s ease;
        }

        .progress-text {
            position: absolute;
            right: 0;
            top: -18px;
            font-size: 0.8em;
            color: #868e96;
        }

        .mini-progress {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .mini-progress-bar {
            flex: 1;
            height: 6px;
            background: #e9ecef;
            border-radius: 3px;
            overflow: hidden;
        }

        .mini-progress-fill {
            height: 100%;
            background: #74b9ff;
        }

        .mini-progress-text {
            font-size: 0.8em;
            color: #495057;
            white-space: nowrap;
        }

        .report-actions {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .mark-not-needed {
            background: #e9ecef;
            border: none;
            border-radius: 4px;
            padding: 2px 6px;
            font-size: 0.8em;
            color: #495057;
            cursor: pointer;
        }

        .mark-not-needed:hover {
            background: #dee2e6;
        }

        .action-menu {
            position: relative;
        }

        .action-menu-button {
            background: none;
            border: none;
            padding: 6px;
            cursor: pointer;
            border-radius: 4px;
        }

        .action-menu-button:hover {
            background: #f1f3f5;
        }

        .action-menu-content {
            position: absolute;
            right: 0;
            top: 100%;
            background: white;
            border-radius: 4px;
            box-shadow: 0 3px 15px rgba(0,0,0,0.1);
            display: none;
            min-width: 200px;
            z-index: 100;
            overflow: hidden;
        }

        .action-menu-content a {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 12px;
            color: #2d3436;
            text-decoration: none;
            font-size: 0.9em;
            transition: background 0.2s;
        }

        .action-menu-content a:hover {
            background: #f8f9fa;
        }

        .action-menu:hover .action-menu-content {
            display: block;
        }

        .mobile-menu-toggle {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: #ffd32a;
            border: none;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 20px;
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
        }

        @media (max-width: 768px) {
            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .quick-actions {
                width: 100%;
                justify-content: space-between;
            }

            .action-button {
                padding: 8px 12px;
                font-size: 0.9em;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .section-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .filter-controls {
                width: 100%;
            }

            .search-input, .filter-select {
                flex: 1;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .cases-section {
                padding: 15px;
            }

            .action-button {
                flex: 1;
                text-align: center;
                justify-content: center;
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
            
            // Action menu functionality for mobile
            document.querySelectorAll('.action-menu-button').forEach(button => {
                button.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const content = this.nextElementSibling;
                    
                    // Close all other menus
                    document.querySelectorAll('.action-menu-content').forEach(menu => {
                        if (menu !== content) {
                            menu.style.display = 'none';
                        }
                    });
                    
                    // Toggle current menu
                    content.style.display = content.style.display === 'block' ? 'none' : 'block';
                });
            });
            
            // Close menus when clicking outside
            document.addEventListener('click', function() {
                document.querySelectorAll('.action-menu-content').forEach(menu => {
                    menu.style.display = 'none';
                });
            });
            
            // Search filter functionality
            const searchInput = document.getElementById('caseFilter');
            const statusFilter = document.getElementById('statusFilter');
            const table = document.getElementById('casesTable');
            
            if (searchInput && statusFilter && table) {
                function filterTable() {
                    const searchTerm = searchInput.value.toLowerCase();
                    const statusTerm = statusFilter.value.toLowerCase();
                    
                    const rows = table.querySelectorAll('tbody tr');
                    if (rows.length === 1 && rows[0].querySelector('.no-data')) {
                        // Skip filtering if there's only the "no data" row
                        return;
                    }
                    
                    rows.forEach(row => {
                        const client = row.querySelector('td:nth-child(2)').textContent.toLowerCase();
                        const caseNumber = row.querySelector('td:nth-child(3)').textContent.toLowerCase();
                        
                        // Get the status class from the progress bar
                        const statusElement = row.querySelector('.progress-bar');
                        const statusClass = statusElement ? Array.from(statusElement.classList).find(cls => 
                            cls !== 'progress-bar'
                        ) : '';
                        
                        const matchesSearch = client.includes(searchTerm) || caseNumber.includes(searchTerm);
                        const matchesStatus = statusTerm === 'all' || statusClass === statusTerm;
                        
                        row.style.display = matchesSearch && matchesStatus ? '' : 'none';
                    });
                }
                
                searchInput.addEventListener('input', filterTable);
                statusFilter.addEventListener('change', filterTable);
            }
            
            // Mark report as not needed functionality
            document.querySelectorAll('.mark-not-needed').forEach(button => {
                button.addEventListener('click', function() {
                    const caseNumber = this.dataset.case;
                    if (confirm('Mark this report as not needed for case ' + caseNumber + '?')) {
                        // Create a directory for not-needed reports if it doesn't exist
                        const dir = 'reports';
                        
                        // Create an empty file to indicate report is not needed
                        fetch('mark-report-not-needed.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: 'case=' + encodeURIComponent(caseNumber)
                        })
                        .then(response => {
                            if (response.ok) {
                                // Update the UI
                                const parentCell = this.closest('td');
                                parentCell.innerHTML = '<span class="status-badge complete">Not Required</span>';
                                
                                // Update the progress bar
                                const row = this.closest('tr');
                                const progressBar = row.querySelector('.progress-bar');
                                const progressFill = row.querySelector('.progress');
                                const progressText = row.querySelector('.progress-text');
                                
                                if (progressBar && progressFill && progressText) {
                                    // Calculate new percentage
                                    let currentPercentage = parseInt(progressText.textContent);
                                    let newPercentage = Math.min(currentPercentage + 25, 100);
                                    
                                    progressFill.style.width = newPercentage + '%';
                                    progressText.textContent = newPercentage + '%';
                                    
                                    // Update status class if needed
                                    if (newPercentage === 100) {
                                        progressBar.className = 'progress-bar completed';
                                    }
                                }
                            } else {
                                alert('An error occurred. Please try again.');
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('An error occurred. Please try again.');
                        });
                    }
                });
            });
        });
    </script>
</body>
</html>