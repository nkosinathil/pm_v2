<?php
// Load all clients from the database
include_once('db-connection.php');

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    $stmt = $pdo->query("SELECT client_code, first_name, surname, phone, email, company_name FROM clients");
    $clients = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html>
<head>
  <title>Create Case</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <style>
    body { 
      font-family: Arial, sans-serif; 
      margin: 0; 
      padding: 0;
      background-color: #f5f5f5;
    }
    
    .header-bar {
      background-color: #ffffff;
      color: #000000;
      padding: 10px 30px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      border-bottom: 3px solid #FFBA08;
    }
    
    .header-info {
      font-size: 0.8rem;
      text-align: right;
    }
    
    .content-area {
      padding: 30px;
    }
    
    h2 { 
      margin-bottom: 20px;
      color: #000000;
      border-bottom: 2px solid #FFBA08;
      padding-bottom: 10px;
    }
    
    table { 
      width: 100%; 
      border-collapse: collapse; 
      margin-bottom: 20px; 
      background-color: white;
    }
    
    th, td { 
      padding: 10px; 
      border: 1px solid #ccc; 
      text-align: left; 
    }
    
    th { 
      background-color: #f0f0f0; 
    }
    
    label { 
      display: block; 
      margin: 15px 0 5px;
      font-weight: bold;
    }
    
    select, input, textarea { 
      width: 100%; 
      padding: 8px; 
      margin-bottom: 10px; 
      border: 1px solid #ccc; 
      box-sizing: border-box;
      border-radius: 4px; 
    }
    
    textarea { 
      height: 100px; 
      resize: vertical;
    }
    
    input[type="submit"] {
      background-color: #007bff;
      color: white;
      padding: 10px 20px;
      border: none;
      cursor: pointer;
      margin-top: 20px;
      width: auto;
      font-weight: bold;
      border-radius: 4px;
    }
    
    input[type="submit"]:hover {
      background-color: #0056b3;
    }
    
    input[readonly] {
      background-color: #f5f5f5;
    }
    
    .section-header {
      background-color: #f2f2f2;
      padding: 10px;
      font-weight: bold;
      margin: 20px 0 15px;
      border: 1px solid #ccc;
      border-left: 4px solid #FFBA08;
    }
    
    .note {
      font-size: 0.9em;
      color: #666;
      font-style: italic;
      margin: 5px 0;
    }
    
    hr {
      margin: 20px 0;
      border: none;
      border-top: 1px solid #ccc;
    }
    
    .form-container {
      background-color: white;
      padding: 20px;
      border-radius: 5px;
      box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    }
    
    /* Logo styles */
    .logo-container {
      display: flex;
      align-items: center;
    }
    /*
    .logo {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background-color: white;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: bold;
      margin-right: 10px;
      border: 2px solid #FFBA08;
      font-size: 18px;
    }*/
	.company-logo {
        height: 40px;
      }
	.logo-container {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    
    .logo-text {
      font-size: 1.2rem;
      font-weight: bold;
    }
    
    .logo-g {
      color: #000000;
    }
    
    .logo-i {
      color: #FFBA08;
    }
  </style>
</head>
<body>

<div class="header-bar">
  <div class="logo-container">
          <!-- Use the actual GI logo image -->
          <img src="../co/assets/logo.jpg" alt="Governance Intelligence Logo" class="company-logo">
          <div class="site-title">Governance Intelligence</div>
   </div>
  <div class="header-info">
    <div>2025-06-09 18:36:33</div>
    <div>nkosinathil</div>
  </div>
</div>

<div class="content-area">
  <h2><i class="fas fa-folder-plus" style="color: #FFBA08; margin-right: 10px;"></i> Create Forensic Case</h2>

  <div class="form-container">
    <form method="post" action="save_case.php">
      <div class="section-header">Client Information</div>
      
      <label for="client_code">Select Client:</label>
      <select name="client_code" id="client_code" required>
        <option value="">-- Choose a client --</option>
        <?php foreach ($clients as $client): ?>
          <option 
            value="<?= htmlspecialchars($client['client_code']) ?>"
            data-repname="<?= htmlspecialchars($client['first_name']) ?>"
            data-repsurname="<?= htmlspecialchars($client['surname']) ?>"
            data-repphone="<?= htmlspecialchars($client['phone'] ?? '') ?>"
            data-repemail="<?= htmlspecialchars($client['email'] ?? '') ?>"
          >
            <?= htmlspecialchars($client['company_name']) ?> (<?= $client['client_code'] ?>)
          </option>
        <?php endforeach; ?>
      </select>

      <label for="case_number">Case Number:</label>
      <input type="text" name="case_number" id="case_number" readonly required>
      <p class="note">This will be automatically generated when you select a client</p>

      <label for="case_description">Case Description:</label>
      <textarea name="case_description" id="case_description" required placeholder="Enter detailed description of the forensic case..."></textarea>

      <div class="section-header"><i class="fas fa-user-tie" style="margin-right: 5px;"></i> Client Representative Details</div>
      <p class="note">Information will be automatically populated from client records and can be modified if needed.</p>

      <table>
        <tr>
          <th>Field</th>
          <th>Value</th>
        </tr>
        <tr>
          <td><label for="rep_name">First Name:</label></td>
          <td><input type="text" name="rep_name" id="rep_name" required></td>
        </tr>
        <tr>
          <td><label for="rep_surname">Surname:</label></td>
          <td><input type="text" name="rep_surname" id="rep_surname" required></td>
        </tr>
        <tr>
          <td><label for="rep_phone">Phone:</label></td>
          <td><input type="text" name="rep_phone" id="rep_phone" required></td>
        </tr>
        <tr>
          <td><label for="rep_email">Email:</label></td>
          <td><input type="email" name="rep_email" id="rep_email" required></td>
        </tr>
      </table>

      <input type="submit" value="Create Case">
    </form>
  </div>
</div>

<script>
document.getElementById('client_code').addEventListener('change', async function () {
  const clientCode = this.value;
  const year = new Date().getFullYear();
  const selected = this.options[this.selectedIndex];

  // Populate representative fields from selected option
  document.getElementById('rep_name').value = selected.getAttribute('data-repname') || '';
  document.getElementById('rep_surname').value = selected.getAttribute('data-repsurname') || '';
  document.getElementById('rep_phone').value = selected.getAttribute('data-repphone') || '';
  document.getElementById('rep_email').value = selected.getAttribute('data-repemail') || '';

  if (!clientCode) {
    document.getElementById('case_number').value = '';
    return;
  }

  try {
    const response = await fetch(`get_case_seq.php?client_code=${clientCode}&year=${year}`);
    const data = await response.json();

    if (data.status === 'ok') {
      document.getElementById('case_number').value = `${clientCode}-${year}-${data.next_seq}`;
    } else {
      alert('Could not generate case number.');
    }
  } catch (err) {
    console.error(err);
    alert('Error contacting server.');
  }
});
</script>

</body>
</html>