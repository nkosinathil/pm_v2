
<?php
// Prepopulate based on case_number
include_once('db-connection.php');

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

$client_name = '';
$client_address = '';
$job_code = $_GET['case_number'] ?? '';

if ($job_code) {
    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
        $stmt = $pdo->prepare("SELECT c.company_name, c.company_address FROM cases cs JOIN clients c ON cs.client_code = c.client_code WHERE cs.case_number = :case_number");
        $stmt->execute([':case_number' => $job_code]);
        $result = $stmt->fetch();
        if ($result) {
            $client_name = $result['company_name'];
            $client_address = $result['company_address'] ?? '';
        }
    } catch (PDOException $e) {
        die("DB Error: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Chain of Custody Form</title>
  <style>
    canvas {
      border: 1px solid #ccc;
      margin-bottom: 10px;
    }
    fieldset {
      margin-bottom: 30px;
      padding: 20px;
    }
    .custodyBlock {
      margin-bottom: 30px;
      border: 1px solid #ddd;
      padding: 15px;
      background-color: #f9f9f9;
    }
    input, textarea, select {
      width: 100%;
      padding: 8px;
      margin-top: 5px;
      margin-bottom: 15px;
    }
	
	
	
    label {
      font-weight: bold;
    }
  </style>
  <style>
        body { font-family: Arial; padding: 30px; }
        form { max-width: 800px; margin: auto; }
        label { display: block; margin-top: 15px; font-weight: bold; }
        select, input[type=submit] { width: 100%; padding: 10px; margin-top: 5px; }
        .message { margin-top: 20px; }
    </style>
</head>
<body>

<h2>Chain of Custody Form</h2>

<form method="post" action="submit_custody.php">
  <fieldset>
    <legend>Case Information</legend>
    <label>Client Name:</label>
    <input type="text" name="client_name" value="<?= htmlspecialchars($client_name) ?>" required>

    <label>Job Code:</label>
    <input type="text" name="job_code" value="<?= htmlspecialchars($job_code) ?>">

    <label>Name of Manager/Director on the Project:</label>
    <input type="text" name="manager_name">

    <label>GI Office:</label>
    <select name="office">
      <option value="Gauteng">Gauteng</option>
      <option value="Durban">Durban</option>
      <option value="Cape Town">Cape Town</option>
      <option value="Free State">Free State</option>
    </select>

    <label>GI Telephone:</label>
    <input type="text" name="telephone">

    <label>Place/Address:</label>
    <textarea name="client_address"><?= htmlspecialchars($client_address) ?></textarea>
  </fieldset>

  <fieldset>
    <legend>Device Information</legend>
    <div id="deviceList">
      <div>
        <label>Item Number:</label>
        <input type="number" name="item_number[]" value="1" readonly>

        <label>Description (Brand, Model):</label>
        <input type="text" name="description[]">

        <label>Serial Number:</label>
        <input type="text" name="serial_number[]">
      </div>
    </div>
    <button type="button" onclick="addDevice()">+ Add More Devices</button>
  </fieldset>

  <fieldset>
    <legend>Chain of Custody Log</legend>
    <div id="custodyContainer">
      <div class="custodyBlock">
        <h4>Released By</h4>
        <label>Full Name:</label>
        <input type="text" name="released_by_name[]">

        <label>Position & Company:</label>
        <input type="text" name="released_by_position[]">

        <label>Phone:</label>
        <input type="text" name="released_by_phone[]">

        <label>Date & Time:</label>
        <input type="datetime-local" name="released_by_datetime[]">

        <label>Reason for Change in Custody:</label>
        <textarea name="released_reason[]"></textarea>

        <label>Signature:</label>
        <canvas class="signature" width="300" height="100"></canvas>
        <button type="button" onclick="clearCanvas(this)">Clear Signature</button>
        <input type="hidden" name="released_by_signature[]">

        <h4>Received By</h4>
        <label>Full Name:</label>
        <input type="text" name="received_by_name[]">

        <label>Position & Company:</label>
        <input type="text" name="received_by_position[]">

        <label>Phone:</label>
        <input type="text" name="received_by_phone[]">

        <label>Date & Time:</label>
        <input type="datetime-local" name="received_by_datetime[]">

        <label>Reason for Change in Custody:</label>
        <textarea name="received_reason[]"></textarea>

        <label>Signature:</label>
        <canvas class="signature" width="300" height="100"></canvas>
        <button type="button" onclick="clearCanvas(this)">Clear Signature</button>
        <input type="hidden" name="received_by_signature[]">
      </div>
    </div>
    <button type="button" onclick="addCustodyLog()">+ Add More Logs</button>
  </fieldset>

  <input type="submit" value="Submit Form">
</form>

<script>
function addDevice() {
  let container = document.getElementById('deviceList');
  let currentCount = container.querySelectorAll('input[name="item_number[]"]').length;
  let nextIndex = currentCount + 1;
  let div = document.createElement('div');
  div.innerHTML = `
    <label>Item Number:</label>
    <input type="number" name="item_number[]" value="${nextIndex}" readonly>

    <label>Description (Brand, Model):</label>
    <input type="text" name="description[]">

    <label>Serial Number:</label>
    <input type="text" name="serial_number[]">
  `;
  container.appendChild(div);
}

function initializeSignatures() {
  document.querySelectorAll('.custodyBlock').forEach(block => {
    block.querySelectorAll('.signature').forEach(canvas => {
      const ctx = canvas.getContext("2d");
      let isDrawing = false;

      canvas.onmousedown = () => isDrawing = true;
      canvas.onmouseup = () => {
        isDrawing = false;
        canvas.nextElementSibling.nextElementSibling.value = canvas.toDataURL();
      };
      canvas.onmousemove = e => {
        if (!isDrawing) return;
        const rect = canvas.getBoundingClientRect();
        ctx.lineTo(e.clientX - rect.left, e.clientY - rect.top);
        ctx.stroke();
      };
    });
  });
}

function clearCanvas(button) {
  const canvas = button.previousElementSibling;
  const ctx = canvas.getContext("2d");
  ctx.clearRect(0, 0, canvas.width, canvas.height);
  canvas.nextElementSibling.value = '';
}

function addCustodyLog() {
  const container = document.getElementById("custodyContainer");
  const lastBlock = container.lastElementChild;
  const clone = lastBlock.cloneNode(true);

  clone.querySelectorAll("input, textarea").forEach(el => {
    if (el.type !== "hidden") el.value = "";
    if (el.type === "hidden") el.value = "";
  });

  clone.querySelectorAll("canvas").forEach(canvas => {
    const ctx = canvas.getContext("2d");
    ctx.clearRect(0, 0, canvas.width, canvas.height);
  });

  container.appendChild(clone);
  initializeSignatures();
}

initializeSignatures();
</script>

</body>
</html>
