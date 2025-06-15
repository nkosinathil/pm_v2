<?php
// Start the session
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Session check
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Define constants
define('CURRENT_USER', $_SESSION['name'] ?? 'nkosinathil');
define('CURRENT_TIME', date('d-m-Y H:i:s')); // Fixed time as requested

// Include database connection
include_once('db-connection.php');

// Create necessary directories if they don't exist
$directories = [
    'signatures',
    'documents/consent'
];

foreach ($directories as $dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Include FPDF library
require_once('../co/fpdf/fpdf.php');

// Extend FPDF class to create custom header and footer
class PDF extends FPDF {
    function Header() {
        // Logo
        $this->Image('../co/assets/logo.jpg', 8, 6, 60);
        $this->Ln(20);
        
        // Company info header
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
    
    function Footer() {
        // Page number
        $this->SetY(-15);
        $this->SetFont('Arial','I',8);
        $this->Cell(0,10,'Page '.$this->PageNo().' of {nb}',0,0,'C');
    }
}

// Initialize variables
$error_message = '';
$success_message = '';
$consent_id = $_GET['id'] ?? '';
$consent_data = [];

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Get consent form details
    if ($consent_id) {
        $stmt = $pdo->prepare("
            SELECT cf.*, c.*, cl.*
            FROM consent_forms cf
            LEFT JOIN cases c ON cf.case_number = c.case_number
            LEFT JOIN clients cl ON cf.client_code = cl.client_code
            WHERE cf.id = ?
        ");
        
        $stmt->execute([$consent_id]);
        $consent_data = $stmt->fetch();
        
        if (!$consent_data) {
            $error_message = "Consent form not found.";
            echo $error_message;
            exit;
        }
    } else {
        echo "No consent ID provided.";
        exit;
    }
    
    // Initialize PDF
    $pdf = new PDF();
    $pdf->AliasNbPages(); // For page numbers
    $pdf->AddPage();
    $pdf->SetFont('Arial','B',16);
    $pdf->Cell(0,10,'CONSENT FORM',0,1,'C');
    $pdf->SetFont('Arial','',10);
    $pdf->Ln(5);
    
     
    
    // Client information block
    $pdf->SetFont('Arial','B',12);
    $pdf->Cell(0,10,'CLIENT INFORMATION',0,1);
    
    $pdf->SetFont('Arial','B',11);
    $pdf->Cell(50,6,'Company Name:',0);
    $pdf->SetFont('Arial','',11);
    $pdf->Cell(0,6,$consent_data['management_for'],0,1);
    
    $pdf->SetFont('Arial','B',11);
    $pdf->Cell(50,6,'Client Code:',0);
    $pdf->SetFont('Arial','',11);
    $pdf->Cell(0,6,$consent_data['client_code'],0,1);
    
    $pdf->SetFont('Arial','B',11);
    $pdf->Cell(50,6,'Representative:',0);
    $pdf->SetFont('Arial','',11);
    $pdf->Cell(0,6,$consent_data['full_name'],0,1);
   
	
	$pdf->SetFont('Arial','B',11);
    $pdf->Cell(50,6,'Date:',0);
    $pdf->SetFont('Arial','',11);
    $pdf->Cell(0,6,date('d-m-Y', strtotime($consent_data['date_signed'])),0,1);
    $pdf->Ln(10);
    
    // Consent declaration
    $pdf->SetFont('Arial','B',12);
    $pdf->Cell(0,10,'DECLARATION',0,1);
    $pdf->SetFont('Arial','',11);
    
    $pdf->MultiCell(0, 6, 'I, ' . $consent_data['full_name'] . ', hereby give consent to Governance Intelligence (Pty) Ltd to conduct digital forensic services for ' . $consent_data['management_for'] . '.');
    $pdf->Ln(3);
    
    $pdf->MultiCell(0, 6, 'I understand that this consent allows Governance Intelligence (Pty) Ltd to perform the necessary digital forensic procedures as outlined in the agreed scope of work.');
    $pdf->Ln(3);
    
    $pdf->MultiCell(0, 6, 'I confirm that I am authorized to provide this consent on behalf of ' . $consent_data['management_for'] . ' and have the legal authority to do so.');
    $pdf->Ln(10);
    
    // Scope explanation
    $pdf->SetFont('Arial','B',12);
    $pdf->Cell(0,10,'SCOPE OF CONSENT',0,1);
    $pdf->SetFont('Arial','',11);
    
    $pdf->MultiCell(0, 6, 'This consent authorizes Governance Intelligence (Pty) Ltd to:');
    $pdf->Ln(2);
    
    $pdf->SetX(15);
    $pdf->Cell(5,6,chr(149),0); // Bullet point
    $pdf->MultiCell(0, 6, 'Access and examine digital devices relevant to the investigation');
    
    $pdf->SetX(15);
    $pdf->Cell(5,6,chr(149),0);
    $pdf->MultiCell(0, 6, 'Acquire forensic images of digital storage media');
    
    $pdf->SetX(15);
    $pdf->Cell(5,6,chr(149),0);
    $pdf->MultiCell(0, 6, 'Analyze and document digital evidence');
    
    $pdf->SetX(15);
    $pdf->Cell(5,6,chr(149),0);
    $pdf->MultiCell(0, 6, 'Prepare detailed reports on findings');
    
    $pdf->SetX(15);
    $pdf->Cell(5,6,chr(149),0);
    $pdf->MultiCell(0, 6, 'Present expert testimony if required');
    $pdf->Ln(10);
    
    // Signature section
    $pdf->SetFont('Arial','B',12);
    $pdf->Cell(0,10,'SIGNATURE',0,1);
    
    // Add signature image if available
    if (!empty($consent_data['signature_data'])) {
        $signature_img = $consent_data['signature_data'];
        $signature_img = str_replace('data:image/png;base64,', '', $signature_img);
        $signature_img = str_replace(' ', '+', $signature_img);
        $signature_data = base64_decode($signature_img);
        $signature_file = 'signatures/signature_' . time() . '.png';
        
        if (file_put_contents($signature_file, $signature_data)) {
            $pdf->Image($signature_file, 15, $pdf->GetY(), 50);
            $pdf->Ln(30);
            unlink($signature_file);
        } else {
            $pdf->Ln(30); // Skip if signature can't be created
        }
    } else {
        $pdf->Ln(30);
    }
    
    // Signature details
    $pdf->SetFont('Arial','B',11);
    $pdf->Cell(50,6,'Name:',0);
    $pdf->SetFont('Arial','',11);
    $pdf->Cell(100,6,$consent_data['confirm_full_name'],0,1);
    
    $pdf->SetFont('Arial','B',11);
    $pdf->Cell(50,6,'Date:',0);
    $pdf->SetFont('Arial','',11);
    $pdf->Cell(100,6,date('d-m-Y', strtotime($consent_data['date_signed'])),0,1);
    
    $pdf->SetFont('Arial','B',11);
    $pdf->Cell(50,6,'Place:',0);
    $pdf->SetFont('Arial','',11);
    $pdf->Cell(100,6,$consent_data['place_signed'],0,1);
    
    $pdf->Ln(15);
    
    // Terms and information
    $pdf->SetFont('Arial','B',12);
    $pdf->Cell(0,10,'TERMS & CONDITIONS',0,1);
    $pdf->SetFont('Arial','',10);
    $pdf->MultiCell(0, 5, 
       "1. This consent is valid for the duration of the current investigation or until revoked in writing.\n".
       "2. All information collected will be treated as confidential and in accordance with applicable privacy laws.\n".
       "3. Governance Intelligence (Pty) Ltd will maintain chain of custody documentation for all evidence collected.\n".
       "4. This consent does not extend to actions beyond the agreed scope of work.\n".
       "5. The signatory confirms they have the legal authority to provide this consent."
    );
    
    $pdf->Ln(10);
    
    // Document validation
    $pdf->SetFont('Arial','I',9);
    $pdf->Cell(0,6,'Current Date and Time (UTC - YYYY-MM-DD HH:MM:SS formatted): ' . CURRENT_TIME,0,1);
    $pdf->Cell(0,6,'Current User\'s Login: ' . CURRENT_USER,0,1);
    
    // Generate the output filename
    $pdf_filename = 'Consent_Form_' . $consent_data['case_number'] . '.pdf';
    
    // Save PDF to server
    $pdf_path = 'documents/consent/' . $pdf_filename;
    $pdf->Output('F', $pdf_path);
    
    // Also output to browser
    $pdf->Output('I', $pdf_filename);
    
} catch (PDOException $e) {
    error_log('Database Error: ' . $e->getMessage());
    echo "Database Error: " . $e->getMessage();
} catch (Exception $e) {
    error_log('Error: ' . $e->getMessage());
    echo "Error: " . $e->getMessage();
}
?>