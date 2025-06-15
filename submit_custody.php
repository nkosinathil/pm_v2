
<?php
include_once('db-connection.php');

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    $pdo->beginTransaction();

    // Insert general case metadata (optional logging or audit)

    // Insert devices
    $job_code = $_POST['job_code'];
    $client_name = $_POST['client_name'];
    $manager_name = $_POST['manager_name'];
    $office = $_POST['office'];
    $telephone = $_POST['telephone'];
    $client_address = $_POST['client_address'];

    $item_numbers = $_POST['item_number'];
    $descriptions = $_POST['description'];
    $serial_numbers = $_POST['serial_number'];

    foreach ($item_numbers as $i => $item_no) {
        $stmt = $pdo->prepare("INSERT INTO custody_devices (job_code, item_number, description, serial_number)
                               VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $job_code,
            $item_no,
            $descriptions[$i],
            $serial_numbers[$i]
        ]);
    }

    // Insert custody log
    $released_names = $_POST['released_by_name'];
    $released_positions = $_POST['released_by_position'];
    $released_phones = $_POST['released_by_phone'];
    $released_datetimes = $_POST['released_by_datetime'];
    $released_reasons = $_POST['released_reason'];
    $released_signatures = $_POST['released_by_signature'];

    $received_names = $_POST['received_by_name'];
    $received_positions = $_POST['received_by_position'];
    $received_phones = $_POST['received_by_phone'];
    $received_datetimes = $_POST['received_by_datetime'];
    $received_reasons = $_POST['received_reason'];
    $received_signatures = $_POST['received_by_signature'];

    foreach ($released_names as $i => $rel_name) {
        $stmt = $pdo->prepare("INSERT INTO custody_logs (
            job_code,
            released_by_name, released_by_position, released_by_phone, released_by_datetime, released_reason, released_by_signature,
            received_by_name, received_by_position, received_by_phone, received_by_datetime, received_reason, received_by_signature
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $stmt->execute([
            $job_code,
            $rel_name,
            $released_positions[$i],
            $released_phones[$i],
            $released_datetimes[$i],
            $released_reasons[$i],
            $released_signatures[$i],
            $received_names[$i],
            $received_positions[$i],
            $received_phones[$i],
            $received_datetimes[$i],
            $received_reasons[$i],
            $received_signatures[$i]
        ]);
    }

    $pdo->commit();
    echo "Chain of Custody successfully submitted.";
} catch (PDOException $e) {
    $pdo->rollBack();
    echo "Error: " . $e->getMessage();
}
?>
