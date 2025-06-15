<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include_once('db-connection.php');
require('fpdf/fpdf.php');

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
$pdo = new PDO($dsn, $user, $pass, $options);

$id = $_GET['id'] ?? '';
if (!$id) {
    die("Missing enquiry ID.");
}

if (isset($_GET['edit']) && $_GET['edit'] == '1') {
    unset($_SESSION['pdf_temp_file']);
    $_SESSION['edit_mode'] = true;
}

$enquiry = $pdo->prepare("SELECT * FROM client_enquiries WHERE id = ?");
$enquiry->execute([$id]);
$entry = $enquiry->fetch();
if (!$entry) {
    die("Enquiry not found.");
}

$services = json_decode($entry['services'] ?? '[]', true);
$devices = json_decode($entry['devices'] ?? '[]', true);

$role_stmt = $pdo->prepare("SELECT hourly_rate FROM roles WHERE role_name = 'Junior Consultant' OR role_name = 'Forensic Analyst' LIMIT 1");
$role_stmt->execute();
$analysis_rate = $role_stmt->fetchColumn() ?? 1000;

$items = $pdo->query("SELECT * FROM items ORDER BY name ASC")->fetchAll();
$item_map = [];
foreach ($items as $item) {
    $item_map[$item['name']] = $item['price'];
}

if (!empty($_SESSION['quotation_items']) && !empty($_SESSION['edit_mode'])) {
    $quotation_items = $_SESSION['quotation_items'];
    $total_amount = array_sum(array_column($quotation_items, 'amount'));
    unset($_SESSION['edit_mode']);
} else {
    $quotation_items = [];
    $total_amount = 0;
    foreach ($services as $service) {
        if ($service === 'Imaging') {
            foreach ($devices as $dev) {
                if (intval($dev['count']) > 0) {
                    $rate = $item_map['Forensic Imaging'] ?? 15000;
                    $qty = intval($dev['count']);
                    $amount = $rate * $qty;
                    $quotation_items[] = [
                        'item' => "Forensic Imaging - " . $dev['type'],
                        'rate' => $rate,
                        'quantity' => $qty,
                        'amount' => $amount
                    ];
                    $total_amount += $amount;
                }
            }
        } else {
            $rate = $item_map[$service] ?? $analysis_rate;
            $qty = 5;
            $amount = $rate * $qty;
            $quotation_items[] = [
                'item' => $service,
                'rate' => $rate,
                'quantity' => $qty,
                'amount' => $amount
            ];
            $total_amount += $amount;
        }
    }
}

$pdf_file = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['mode'] ?? '';

    if ($mode === 'review') {
        $quotation_items = [];
        $total_amount = 0;
        foreach ($_POST['item'] as $i => $name) {
            if (isset($_POST['delete']) && in_array($i, $_POST['delete'])) continue;
            $rate = floatval($_POST['rate'][$i]);
            $qty = intval($_POST['qty'][$i]);
            $amount = $rate * $qty;
            $quotation_items[] = [
                'item' => $name,
                'rate' => $rate,
                'quantity' => $qty,
                'amount' => $amount
            ];
            $total_amount += $amount;
        }

        if (!empty($_POST['new_item']) && is_array($_POST['new_item'])) {
            foreach ($_POST['new_item'] as $idx => $new_name) {
                if (trim($new_name) !== '') {
                    $new_rate = floatval($_POST['new_rate'][$idx] ?? 0);
                    $new_qty = intval($_POST['new_qty'][$idx] ?? 0);
                    $new_amount = $new_rate * $new_qty;
                    $quotation_items[] = [
                        'item' => $new_name,
                        'rate' => $new_rate,
                        'quantity' => $new_qty,
                        'amount' => $new_amount
                    ];
                    $total_amount += $new_amount;
                }
            }
        }

        $_SESSION['quotation_items'] = $quotation_items;

		
		class PDF extends FPDF {
		function Header() {
				$this->Image('assets/logo.jpg', 8, 6, 60);
				$this->Ln(20);
				$this->SetFont('Arial','B',11);
				$this->SetY(10);
				$this->SetX(130);
				$this->Cell(60,5,'Governance Intelligence (PTY) LTD',0,1);
				$this->SetFont('Arial','',10);
				$this->SetX(130);
				$this->Cell(60,5,'The Forum, 2 Maude Street, Sandton',0,1);
				$this->SetX(130);
				$this->Cell(60,5,'VAT Reg: 4330283823',0,1);
				$this->SetX(130);
				$this->Cell(60,5,'Telephone: +27 (0) 82 453 1105',0,1);
				$this->SetX(130);
				$this->Cell(60,5,'finance@gint.africa | www.gint.africa',0,1);
				$this->Ln(10);
			}
		}

				$pdf = new PDF();
				$pdf->AddPage();

				$pdf->SetFont('Arial','',10);
				$pdf->Ln(5);
				$pdf->Cell(100,6,'To: ' . $entry['company'],0,1);
				$pdf->Cell(0,10,'Contact: ' . $entry['contact'],0,1);
				$pdf->Cell(0,10,'Email: ' . $entry['email'],0,1);
				$pdf->Ln(5);
				$pdf->MultiCell(0,8,'Case Background: ' . $entry['background']);
				$pdf->Ln(5);

				$pdf->SetFont('Arial','B',12);
				$pdf->Cell(0,10,'Quotation Details:',0,1);
				$pdf->SetFont('Arial','',11);

				$pdf->Cell(90,8,'Service',1);
				$pdf->Cell(30,8,'Rate (R)',1);
				$pdf->Cell(30,8,'Qty',1);
				$pdf->Cell(40,8,'Total (R)',1);
				$pdf->Ln();

				$pdf->SetFont('Arial','',10);
				foreach ($quotation_items as $row) {
						$pdf->Cell(90,8,$row['item'],1);
						$pdf->Cell(30,8,number_format($row['rate'],2),1);
						$pdf->Cell(30,8,$row['quantity'],1);
						$pdf->Cell(40,8,number_format($row['amount'],2),1);
						$pdf->Ln();
				}

				$pdf->SetFont('Arial','B',12);
				$pdf->Cell(150,10,'Total Quotation (R)',1);
				$pdf->Cell(40,10,number_format($total_amount,2),1);

				$filename = 'temp/quotation_preview_' . time() . '.pdf';
				$pdf->Output('F', $filename);
				$_SESSION['pdf_temp_file'] = $filename;
			}
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Generate Quotation</title>
    <style>
        body { font-family: Arial; padding: 30px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        th { background: #f0f0f0; }
        input[type='number'], input[type='text'] { width: 100%; box-sizing: border-box; }
        .add-row { margin-top: 10px; }
    </style>
    <script>
        function addRow() {
            const table = document.querySelector("#quotation-table tbody");
            const row = document.createElement("tr");
            row.innerHTML = `
                <td><input type="text" name="new_item[]" required></td>
                <td><input type="number" name="new_rate[]" step="0.01" value="0" oninput="calculateTotal()"></td>
                <td><input type="number" name="new_qty[]" value="1" oninput="calculateTotal()"></td>
                <td class="row-total">R0.00</td>
                <td><button type="button" onclick="deleteRow(this)">❌</button></td>
            `;
            table.appendChild(row);
        }

        function deleteRow(btn) {
            if (confirm("Are you sure you want to delete this row?")) {
                btn.closest("tr").remove();
                calculateTotal();
            }
        }

        function calculateTotal() {
            const rows = document.querySelectorAll("#quotation-table tbody tr");
            let grandTotal = 0;
            rows.forEach(row => {
                const rateInput = row.querySelector("input[name^='rate']") || row.querySelector("input[name^='new_rate']");
                const qtyInput = row.querySelector("input[name^='qty']") || row.querySelector("input[name^='new_qty']");
                const rate = parseFloat(rateInput?.value || 0);
                const qty = parseInt(qtyInput?.value || 0);
                const total = rate * qty;
                const totalCell = row.querySelector(".row-total");
                if (totalCell) totalCell.textContent = 'R' + total.toFixed(2);
                grandTotal += total;
            });
            document.getElementById("grand-total").textContent = 'R' + grandTotal.toFixed(2);
        }
    </script>
</head>
<body>
<h2>Quotation Review - <?= htmlspecialchars($entry['company']) ?></h2>
<?php if (!empty($_SESSION['pdf_temp_file']) && file_exists($_SESSION['pdf_temp_file'])): ?>
    <iframe src="<?= $_SESSION['pdf_temp_file'] ?>" width="100%" height="600px"></iframe>
    <br><br>
    <form method="post">
        <button type="submit" name="mode" value="approve">✅ Approve & Send</button>
        <a href="generate-quotation.php?id=<?= $id ?>&edit=1" style="margin-left: 20px;">✏️ Edit Quotation</a>
    </form>
<?php else: ?>
<form method="post">
<table id="quotation-table">
    <thead><tr><th>Item</th><th>Rate (R)</th><th>Qty</th><th>Total</th><th>Delete</th></tr></thead>
    <tbody>
    <?php foreach ($quotation_items as $i => $row): ?>
        <tr>
            <td><input type="text" name="item[]" value="<?= htmlspecialchars($row['item']) ?>" required></td>
            <td><input type="number" name="rate[]" value="<?= $row['rate'] ?>" step="0.01" oninput="calculateTotal()"></td>
            <td><input type="number" name="qty[]" value="<?= $row['quantity'] ?>" min="1" oninput="calculateTotal()"></td>
            <td class="row-total">R<?= number_format($row['amount'], 2) ?></td>
            <td><button type="button" onclick="deleteRow(this)">❌</button></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr><td colspan="4" style="text-align:right;"><strong>Total</strong></td><td id="grand-total"><strong>R<?= number_format($total_amount, 2) ?></strong></td></tr>
    </tfoot>
</table>
<button type="button" class="add-row" onclick="addRow()">➕ Add Item</button>
<br><br>
<button type="submit" name="mode" value="review">🔁 Review</button>
<button type="submit" name="mode" value="approve">✅ Approve & Send</button>
<a href="save-quotation.php?id=<?= $id ?>"> ✅ Approve & Send </a>

</form>
<?php endif; ?>
</body>
</html>
