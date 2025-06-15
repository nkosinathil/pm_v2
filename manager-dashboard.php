<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['manager', 'Admin'])) {
    header("Location: login.php");
    exit;
}

include_once('db-connection.php');
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die("DB Error: " . $e->getMessage());
}

// Get this week's Monday
$monday = date('Y-m-d', strtotime('monday this week'));

// Initialize summary counts (case-sensitive match with DB)
$summary = [
    'Submitted' => 0,
    'Approved' => 0,
    'Rejected' => 0
];

$stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM timesheets GROUP BY status");
$stmt->execute([':week' => $monday]);
foreach ($stmt->fetchAll() as $row) {
    $status = $row['status'];
    if (isset($summary[$status])) {
        $summary[$status] = $row['count'];
    }
}

// Fetch timesheets with employee and project info
$data_stmt = $pdo->query("
    SELECT 
        t.id,
        t.week_start,
        t.total_hours,
        t.status,
        t.created_at,
        e.name AS employee_name,
        e.surname,
        p.case_number
    FROM timesheets t
    JOIN employee e ON t.employee_id = e.id
    LEFT JOIN projects p ON p.case_number = t.case_number
    ORDER BY t.week_start DESC, t.created_at DESC
");
$timesheets = $data_stmt->fetchAll();
?>

<!DOCTYPE html>
<html>
<head>
  <title>Manager Dashboard</title>
  <style>
    body { font-family: Arial, sans-serif; padding: 30px; }
    h2 { margin-bottom: 20px; }
    .summary-boxes div {
      display: inline-block; margin-right: 20px; padding: 10px;
      background: #f2f2f2; border: 1px solid #ccc;
    }
    table { width: 100%; margin-top: 30px; border-collapse: collapse; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background-color: #eee; }
  </style>
</head>
<body>

<h2>Manager Dashboard</h2>

<div class="summary-boxes">
  <div><strong>Submitted:</strong> <?= $summary['Submitted'] ?></div>
  <div><strong>Approved:</strong> <?= $summary['Approved'] ?></div>
  <div><strong>Rejected:</strong> <?= $summary['Rejected'] ?></div>
</div>

<table>
  <thead>
    <tr>
      <th>Employee</th>
      <th>Week Starting</th>
      <th>Case Number</th>
      <th>Total Hours</th>
      <th>Status</th>
      <th>Submitted On</th>
      <th>Actions</th>
    </tr>
  </thead>
  <tbody>
    <?php if (empty($timesheets)): ?>
      <tr><td colspan="7">No timesheets submitted yet.</td></tr>
    <?php else: ?>
      <?php foreach ($timesheets as $sheet): ?>
        <tr>
          <td><?= htmlspecialchars($sheet['employee_name'] . ' ' . $sheet['surname']) ?></td>
          <td><?= htmlspecialchars($sheet['week_start']) ?></td>
          <td><?= htmlspecialchars($sheet['case_number']) ?></td>
          <td><?= htmlspecialchars($sheet['total_hours']) ?></td>
          <td><?= htmlspecialchars($sheet['status']) ?></td>
          <td><?= htmlspecialchars($sheet['created_at']) ?></td>
          <td>
            <a href="view-timesheet.php?id=<?= $sheet['id'] ?>">View</a>
            <?php if ($sheet['status'] === 'Submitted'): ?>
              | <a href="review_timesheet_action.php?id=<?= $sheet['id'] ?>&action=approve">Approve</a>
              | <a href="review_timesheet_action.php?id=<?= $sheet['id'] ?>&action=reject">Reject</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    <?php endif; ?>
  </tbody>
</table>

</body>
</html>
