<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['Admin', 'manager'])) {
    header("Location: login.php");
    exit;
}

include_once('db-connection.php');
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
$pdo = new PDO($dsn, $user, $pass, $options);

// Set the exact current date/time and user format
define('CURRENT_TIMESTAMP', '2025-06-08 19:42:37');
define('CURRENT_USER', 'nkosinathil');

// Handle assignment
$success = $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $employee_id = $_POST['employee_id'] ?? '';
    $project_id = $_POST['project_id'] ?? '';

    if ($employee_id && $project_id) {
        try {
            $stmt = $pdo->prepare("INSERT IGNORE INTO project_users (project_id, employee_id) VALUES (?, ?)");
            $stmt->execute([$project_id, $employee_id]);
            $success = "User successfully assigned to project.";
        } catch (PDOException $e) {
            $error = "DB Error: " . $e->getMessage();
        }
    } else {
        $error = "Please select both project and user.";
    }
}

// Get employees
$employees = $pdo->query("SELECT e.id, e.name, e.surname FROM employee e ORDER BY e.name")->fetchAll();

// Get projects
$projects = $pdo->query("SELECT id, case_number FROM projects ORDER BY case_number")->fetchAll();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Assign User to Case | Governance Intelligence</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { 
            font-family: 'Inter', sans-serif; 
            margin: 0; 
            padding: 0;
            background-color: #f5f6fa;
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
        
        .system-info {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .main-header {
            background-color: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 15px 0;
            margin-bottom: 20px;
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .logo-container {
            display: flex;
            align-items: center;
        }
        
        .logo {
            height: 50px;
            margin-right: 15px;
        }
        
        .site-title {
            font-size: 20px;
            font-weight: 600;
            color: #2d3436;
        }
        
        .nav-links {
            display: flex;
            gap: 20px;
        }
        
        .nav-link {
            color: #2d3436;
            text-decoration: none;
            font-weight: 500;
            padding: 8px 16px;
            border-radius: 4px;
            transition: all 0.2s;
        }
        
        .nav-link:hover {
            background-color: #ffd32a;
            color: #2d3436;
        }
        
        .page-header {
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .page-header h1 {
            font-size: 24px;
            font-weight: 600;
            color: #2d3436;
            margin: 0;
        }
        
        .card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            overflow: hidden;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .card-header {
            background-color: #f8f9fa;
            padding: 15px 20px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .card-header h2 {
            margin: 0;
            font-size: 18px;
            color: #2d3436;
        }
        
        .card-body {
            padding: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #495057;
        }
        
        select, input[type="submit"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-family: inherit;
            font-size: 14px;
        }
        
        select:focus {
            outline: none;
            border-color: #ffd32a;
            box-shadow: 0 0 0 3px rgba(255, 211, 42, 0.25);
        }
        
        .btn {
            padding: 10px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            background-color: #ffd32a;
            color: #2d3436;
            width: 100%;
            font-size: 16px;
            transition: background-color 0.2s;
        }
        
        .btn:hover {
            background-color: #f0c61a;
        }
        
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }
        
        .alert-success {
            background-color: #d3f9d8;
            color: #2b8a3e;
            border-left: 4px solid #2b8a3e;
        }
        
        .alert-danger {
            background-color: #ffe3e3;
            color: #e03131;
            border-left: 4px solid #e03131;
        }
        
        .actions {
            margin-top: 20px;
            text-align: center;
        }
        
        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #495057;
            text-decoration: none;
        }
        
        .back-link:hover {
            color: #ffd32a;
        }
        
        @media (max-width: 768px) {
            .system-info {
                flex-direction: column;
                gap: 5px;
            }
            
            .header-content {
                flex-direction: column;
                padding: 10px;
                text-align: center;
            }
            
            .nav-links {
                margin-top: 10px;
                justify-content: center;
            }
            
            .logo-container {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
  

    <header class="main-header">
        <div class="header-content">
            <div class="logo-container">
                <img src="../co/assets/logo.jpg" alt="Logo" class="logo">
                <h1 class="site-title">Governance Intelligence</h1>
            </div>
            <div class="nav-links">
                <a href="dashboard.php" class="nav-link">Dashboard</a>
               
            </div>
        </div>
    </header>

    <div class="container">
       
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <div>
                    <strong>Success!</strong>
                    <p><?= htmlspecialchars($success) ?></p>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <div>
                    <strong>Error</strong>
                    <p><?= htmlspecialchars($error) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h2>Assignment Form</h2>
            </div>
            <div class="card-body">
                <form method="post">
                    <div class="form-group">
                        <label for="employee_id">Select Employee:</label>
                        <select name="employee_id" id="employee_id" required>
                            <option value="">-- Select Employee --</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?= $emp['id'] ?>" <?= isset($_POST['employee_id']) && $_POST['employee_id'] == $emp['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($emp['name'] . ' ' . $emp['surname']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="project_id">Select Case:</label>
                        <select name="project_id" id="project_id" required>
                            <option value="">-- Select Case --</option>
                            <?php foreach ($projects as $proj): ?>
                                <option value="<?= $proj['id'] ?>" <?= isset($_POST['project_id']) && $_POST['project_id'] == $proj['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($proj['case_number']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <button type="submit" class="btn">
                            <i class="fas fa-user-plus"></i> Assign User to Case
                        </button>
                    </div>
                </form>
                
                <div class="actions">
                    <a href="dashboard.php" class="back-link">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>