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
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Load Composer's autoloader if available
if (file_exists('vendor/autoload.php')) {
    require 'vendor/autoload.php';
} else {
    // Manually include the PHPMailer files if autoloader isn't available
    require '../co/lib/phpmailer/src/Exception.php';
    require '../co/lib/phpmailer/src/PHPMailer.php';
    require '../co/lib/phpmailer/src/SMTP.php';
}
            
define('CURRENT_USER', $_SESSION['name'] ?? 'nkosinathil');
define('COMPANY_NAME', 'Governance Intelligence (Pty) Ltd');
define('CURRENT_TIME', '2025-06-11 20:12:23'); // As requested in your example

include_once('db-connection.php');

// Initialize variables
$error_message = '';
$success_message = '';
$case_number = $_GET['case'] ?? '';
$client_code = $_GET['client'] ?? '';
$case_data = [];
$client_data = [];

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

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

    // Get case details
    if ($case_number) {
        $stmt = $pdo->prepare("
            SELECT c.*, cl.* 
            FROM cases c
            LEFT JOIN clients cl ON c.client_code = cl.client_code
            WHERE c.case_number = ?
        ");
        $stmt->execute([$case_number]);
        $case_data = $stmt->fetch();
        
        if ($case_data) {
            $client_code = $case_data['client_code'];
        }
    }

    // Get client details if we have client_code but no case_data
    if ($client_code && empty($case_data)) {
        $stmt = $pdo->prepare("SELECT * FROM clients WHERE client_code = ?");
        $stmt->execute([$client_code]);
        $client_data = $stmt->fetch();
    }
    
    // Form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Create consent form
        $stmt = $pdo->prepare("
            INSERT INTO consent_forms (
                case_number,
                client_code,
                full_name,
                management_for,
                signature_data,
                date_signed,
                confirm_full_name,
                place_signed,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $_POST['case_number'],
            $_POST['client_code'],
            $_POST['full_name'],
            $_POST['management_for'],
            $_POST['signature_data'],
            $_POST['date_signed'],
            $_POST['confirm_full_name'],
            $_POST['place_signed']
        ]);
        
        $consent_id = $pdo->lastInsertId();
        
        // Send email to client
        if (isset($_POST['send_email']) && $_POST['send_email'] == 'yes' && !empty($_POST['email'])) {
            // Generate PDF with custom template
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
            $pdf->Cell(0,6,$_POST['management_for'],0,1);
            
            $pdf->SetFont('Arial','B',11);
            $pdf->Cell(50,6,'Client Code:',0);
            $pdf->SetFont('Arial','',11);
            $pdf->Cell(0,6,$_POST['client_code'],0,1);
            
            $pdf->SetFont('Arial','B',11);
            $pdf->Cell(50,6,'Representative:',0);
            $pdf->SetFont('Arial','',11);
            $pdf->Cell(0,6,$_POST['full_name'],0,1);
			
			 $pdf->SetFont('Arial','B',11);
            $pdf->Cell(50,6,'Date:',0);
            $pdf->SetFont('Arial','',11);
            $pdf->Cell(0,6,date('d-m-Y', strtotime($_POST['date_signed'])),0,1);
			
            $pdf->Ln(10);
            
            // Consent declaration
            $pdf->SetFont('Arial','B',12);
            $pdf->Cell(0,10,'DECLARATION',0,1);
            $pdf->SetFont('Arial','',11);
            
            $pdf->MultiCell(0, 6, 'I, ' . $_POST['full_name'] . ', hereby give consent to Governance Intelligence (Pty) Ltd to conduct digital forensic services for ' . $_POST['management_for'] . '.');
            $pdf->Ln(3);
            
            $pdf->MultiCell(0, 6, 'I understand that this consent allows Governance Intelligence (Pty) Ltd to perform the necessary digital forensic procedures as outlined in the agreed scope of work.');
            $pdf->Ln(3);
            
            $pdf->MultiCell(0, 6, 'I confirm that I am authorized to provide this consent on behalf of ' . $_POST['management_for'] . ' and have the legal authority to do so.');
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
            if (!empty($_POST['signature_data'])) {
                $signature_img = $_POST['signature_data'];
                $signature_img = str_replace('data:image/png;base64,', '', $signature_img);
                $signature_img = str_replace(' ', '+', $signature_img);
                $signature_data = base64_decode($signature_img);
                $signature_file = 'signatures/signature_' . time() . '.png';
                file_put_contents($signature_file, $signature_data);
                $pdf->Image($signature_file, 15, $pdf->GetY(), 50);
                $pdf->Ln(30);
                unlink($signature_file);
            } else {
                $pdf->Ln(30);
            }
            
            // Signature details
            $pdf->SetFont('Arial','B',11);
            $pdf->Cell(50,6,'Name:',0);
            $pdf->SetFont('Arial','',11);
            $pdf->Cell(100,6,$_POST['confirm_full_name'],0,1);
            
            $pdf->SetFont('Arial','B',11);
            $pdf->Cell(50,6,'Date:',0);
            $pdf->SetFont('Arial','',11);
            $pdf->Cell(100,6,date('d-m-Y', strtotime($_POST['date_signed'])),0,1);
            
            $pdf->SetFont('Arial','B',11);
            $pdf->Cell(50,6,'Place:',0);
            $pdf->SetFont('Arial','',11);
            $pdf->Cell(100,6,$_POST['place_signed'],0,1);
            
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
            
            // Save PDF
            $pdf_filename = 'consent_' . $_POST['case_number'] . '.pdf';
            $pdf->Output('documents/consent/' . $pdf_filename, 'F');
            
            // Send email with PHPMailer
            $mail = new PHPMailer(true);
            
            try {
                // Server settings
                $mail->isSMTP();
                $mail->isSMTP();                                      // Send using SMTP
                $mail->Host       = 'mail.gint.africa';               // SMTP server
                $mail->SMTPAuth   = true;                             // Enable SMTP authentication
                $mail->Username   = 'finance@gint.africa';            // SMTP username
                $mail->Password   = '5ucc3SS!@#s';                    // SMTP password
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;   // Enable TLS encryption
                $mail->Port       = 587;                              // TCP port to connect to, use 465 for `PHPMailer::ENCRYPTION_SMTPS` above
                    
                
                // Recipients
                 $mail->setFrom('finance@gint.africa', 'Governance Intelligence Finance');
                 $mail->addAddress($_POST['email'], $_POST['full_name']);
                 $mail->addReplyTo('finance@gint.africa', 'Finance Department');
                // Attachments
                $mail->addAttachment('documents/consent/' . $pdf_filename);
                
                // Content
                $mail->isHTML(true);
                $mail->Subject = 'Consent Form - ' . $_POST['case_number'];
                $mail->Body = "
                    <p>Dear {$_POST['full_name']},</p>
                    <p>Thank you for providing consent for Governance Intelligence (Pty) Ltd to perform digital forensic services.</p>
                    <p>Please find attached a copy of the signed consent form for your records.</p>
                    <p>If you have any questions or concerns, please do not hesitate to contact us.</p>
                    <p>Best regards,<br>" . COMPANY_NAME . "</p>
                ";
                
                $mail->send();
                $email_sent = true;
            } catch (Exception $e) {
                error_log("Email could not be sent. Mailer Error: {$mail->ErrorInfo}");
                $email_sent = false;
            }
        }
        
        $success_message = "Consent form created successfully" . 
                        (isset($email_sent) && $email_sent ? " and sent to client" : "") . 
                        ".";
                        
        // Redirect after short delay
        header("Refresh: 2; URL=view-consent.php?id=$consent_id");
    }
    
} catch (PDOException $e) {
    error_log('Database Error: ' . $e->getMessage());
    $error_message = "Error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Create Consent Form - Project Management System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Signature Pad Library -->
    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.0.0/dist/signature_pad.umd.min.js"></script>
</head>
<body>
    <div class="top-bar">
        <div class="container">
            <div class="system-info">
                <span class="timestamp">Current Date and Time (UTC): <?= gmdate('Y-m-d H:i:s') ?></span>
                <span class="username">Current User: <?= CURRENT_USER ?></span>
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
                    <li>
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
                    <li class="active">
                        <a href="consent-form.php">
                            <i class="fas fa-file-signature"></i> Consent Forms
                        </a>
                    </li>
                </ul>
            </div>

            <div class="sidebar-footer">
                <a href="logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </nav>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="page-header">
                <h1>Create Consent Form</h1>
                <nav class="breadcrumb">
                    <a href="dashboard.php">Dashboard</a> / 
                    <a href="consent-form.php">Consent Forms</a> / 
                    <span>Create Form</span>
                </nav>
            </div>

            <?php if ($error_message): ?>
                <div class="alert alert-danger">
                    <?= $error_message ?>
                </div>
            <?php endif; ?>

            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <?= $success_message ?>
                </div>
            <?php endif; ?>

            <div class="content-card">
                <form method="post" id="consentForm">
                    <div class="form-section">
                        <h2>Case Information</h2>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="case_number">Case Number</label>
                                <input type="text" id="case_number" name="case_number" value="<?= htmlspecialchars($case_data['case_number'] ?? '') ?>" required readonly>
                            </div>
                            <div class="form-group">
                                <label for="client_code">Client Code</label>
                                <input type="text" id="client_code" name="client_code" value="<?= htmlspecialchars($case_data['client_code'] ?? $client_data['client_code'] ?? '') ?>" required readonly>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h2>Client Information</h2>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="full_name">Full Name</label>
                                <input type="text" id="full_name" name="full_name" value="<?= htmlspecialchars($case_data['rep_name'] ?? '') . ' ' . htmlspecialchars($case_data['rep_surname'] ?? '') ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="email">Email</label>
                                <input type="email" id="email" name="email" value="<?= htmlspecialchars($case_data['rep_email'] ?? $client_data['email'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group full-width">
                                <label for="management_for">On behalf of (Company/Entity)</label>
                                <input type="text" id="management_for" name="management_for" value="<?= htmlspecialchars($case_data['company_name'] ?? $client_data['company_name'] ?? '') ?>" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h2>Consent Details</h2>
                        <div class="consent-text">
                            <p>I, <strong class="dynamic-name"><?= htmlspecialchars($case_data['rep_name'] ?? '') . ' ' . htmlspecialchars($case_data['rep_surname'] ?? '') ?></strong>, hereby give consent to Governance Intelligence (Pty) Ltd to conduct digital forensic services for <strong class="dynamic-company"><?= htmlspecialchars($case_data['company_name'] ?? $client_data['company_name'] ?? '') ?></strong>.</p>
                            <p>I understand that this consent allows Governance Intelligence (Pty) Ltd to perform the necessary digital forensic procedures as outlined in the agreed scope of work.</p>
                            <p>I confirm that I am authorized to provide this consent on behalf of <strong class="dynamic-company"><?= htmlspecialchars($case_data['company_name'] ?? $client_data['company_name'] ?? '') ?></strong> and have the legal authority to do so.</p>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h2>Signature</h2>
                        <div class="form-row">
                            <div class="form-group full-width">
                                <label for="signature-pad">Sign Below</label>
                                <div class="signature-container">
                                    <canvas id="signature-pad" class="signature-pad"></canvas>
                                    <input type="hidden" name="signature_data" id="signature_data">
                                    <div class="signature-actions">
                                        <button type="button" id="clearSignature" class="btn-text">Clear</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="confirm_full_name">Confirm Full Name</label>
                                <input type="text" id="confirm_full_name" name="confirm_full_name" value="<?= htmlspecialchars($case_data['rep_name'] ?? '') . ' ' . htmlspecialchars($case_data['rep_surname'] ?? '') ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="date_signed">Date Signed</label>
                                <input type="date" id="date_signed" name="date_signed" value="<?= date('Y-m-d') ?>" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group full-width">
                                <label for="place_signed">Place</label>
                                <input type="text" id="place_signed" name="place_signed" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h2>Email Options</h2>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="send_email">Send Form to Client</label>
                                <div class="toggle-switch">
                                    <input type="checkbox" id="send_email" name="send_email" value="yes" checked>
                                    <label for="send_email" class="switch"></label>
                                    <span class="toggle-label">Yes, send the form to: <strong id="recipient-email"><?= htmlspecialchars($case_data['rep_email'] ?? $client_data['email'] ?? '') ?></strong></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-buttons">
                        <button type="button" onclick="window.history.back()" class="cancel-button">Cancel</button>
                        <button type="submit" class="submit-button">Create Consent Form</button>
                    </div>
                </form>
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
    background-color: #f5f5f5;
    color: #2d3436;
    padding-top: 0;
    margin: 0;
}

/* Top Bar Styling - White with Black Text */
.top-bar {
    background-color: #fff;
    color: #000;
    padding: 10px 30px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 1000;
}

.system-info {
    display: flex;
    justify-content: space-between;
    width: 100%;
}

/* Layout */
.layout {
    display: flex;
    min-height: calc(100vh - 60px);
    margin-top: 60px;
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

.sidebar-header {
    display: flex;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid #e9ecef;
}

.logo {
    height: 40px;
    margin-right: 10px;
}

.sidebar-header h2 {
    font-size: 18px;
    font-weight: 600;
    color: #2d3436;
    border-bottom: none;
}

.menu-section {
    margin-bottom: 20px;
}

.menu-section h3 {
    font-size: 14px;
    color: #6c757d;
    text-transform: uppercase;
    margin-bottom: 10px;
    padding-left: 10px;
}

.menu-section ul {
    list-style: none;
}

.menu-section li {
    margin-bottom: 5px;
}

.menu-section a {
    display: flex;
    align-items: center;
    padding: 10px;
    color: #2d3436;
    text-decoration: none;
    border-radius: 4px;
    transition: background 0.2s;
}

.menu-section a:hover {
    background: #f8f9fa;
}

.menu-section li.active a {
    background: #e9ecef;
    font-weight: 500;
}

.menu-section a i {
    margin-right: 10px;
    width: 20px;
    text-align: center;
    font-size: 16px;
    color: #495057;
}

.sidebar-footer {
    position: absolute;
    bottom: 20px;
    width: calc(100% - 40px);
}

.logout-btn {
    display: flex;
    align-items: center;
    padding: 10px;
    color: #dc3545;
    text-decoration: none;
    border-radius: 4px;
    transition: background 0.2s;
    font-weight: 500;
}

.logout-btn:hover {
    background: #f8d7da;
}

.logout-btn i {
    margin-right: 10px;
}

/* Main Content Area */
.main-content {
    flex: 1;
    padding: 20px;
    margin-left: 280px;
    transition: margin-left 0.3s ease;
}

.page-header {
    margin-bottom: 20px;
}

.page-header h1 {
    font-size: 24px;
    font-weight: 600;
    color: #2d3436;
    margin-bottom: 5px;
}

.breadcrumb {
    color: #6c757d;
    font-size: 14px;
}

.breadcrumb a {
    color: #6c757d;
    text-decoration: none;
}

.breadcrumb a:hover {
    color: #2d3436;
}

/* Alerts */
.alert {
    padding: 15px;
    margin-bottom: 20px;
    border-radius: 4px;
}

.alert-danger {
    background-color: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
}

.alert-success {
    background-color: #d4edda;
    border: 1px solid #c3e6cb;
    color: #155724;
}

/* Card Styling */
.content-card {
    background: white;
    border-radius: 5px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    padding: 20px;
    margin-bottom: 20px;
}

/* Form Styling */
.form-section {
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 1px solid #e9ecef;
}

.form-section:last-child {
    border-bottom: none;
}

.form-section h2 {
    font-size: 18px;
    font-weight: 600;
    color: #2d3436;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 1px solid #e9ecef;
}

.form-row {
    display: flex;
    flex-wrap: wrap;
    margin: -10px;
}

.form-group {
    padding: 10px;
    flex: 1 1 300px;
}

.form-group.full-width {
    flex: 1 1 100%;
}

label {
    display: block;
    margin-bottom: 8px;
    font-weight: 500;
    color: #2d3436;
}

input[type="text"],
input[type="email"],
input[type="date"],
select,
textarea {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    font-size: 14px;
    transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
}

input[type="text"]:focus,
input[type="email"]:focus,
input[type="date"]:focus,
select:focus,
textarea:focus {
    border-color: #80bdff;
    outline: none;
    box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
}

input[readonly] {
    background-color: #f5f5f5;
    cursor: not-allowed;
}

/* Consent text styling */
.consent-text {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 4px;
    font-size: 15px;
    line-height: 1.6;
}

.consent-text p {
    margin-bottom: 15px;
}

.consent-text p:last-child {
    margin-bottom: 0;
}

/* Signature Pad */
.signature-container {
    border: 1px solid #ced4da;
    border-radius: 4px;
    padding: 10px;
    background: #fff;
    position: relative;
}

.signature-pad {
    width: 100%;
    height: 200px;
    background-color: #fff;
    border-radius: 4px;
}

.signature-actions {
    display: flex;
    justify-content: flex-end;
    margin-top: 10px;
}

.btn-text {
    background: none;
    border: none;
    color: #007bff;
    cursor: pointer;
    padding: 5px 10px;
    font-size: 14px;
    transition: color 0.15s;
}

.btn-text:hover {
    color: #0056b3;
    text-decoration: underline;
}

/* Toggle Switch */
.toggle-switch {
    display: flex;
    align-items: center;
}

.toggle-switch input[type="checkbox"] {
    height: 0;
    width: 0;
    visibility: hidden;
    position: absolute;
}

.toggle-switch .switch {
    cursor: pointer;
    width: 50px;
    height: 25px;
    background: #ced4da;
    display: inline-block;
    border-radius: 25px;
    position: relative;
    margin-right: 10px;
    transition: background-color 0.2s;
}

.toggle-switch .switch:after {
    content: '';
    position: absolute;
    top: 2px;
    left: 2px;
    width: 21px;
    height: 21px;
    background: #fff;
    border-radius: 21px;
    transition: transform 0.2s;
}

.toggle-switch input:checked + .switch {
    background: #007bff;
}

.toggle-switch input:checked + .switch:after {
    transform: translateX(25px);
}

.toggle-label {
    color: #2d3436;
}

/* Form Buttons */
.form-buttons {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 20px;
}

.cancel-button,
.submit-button {
    padding: 10px 20px;
    border-radius: 4px;
    font-weight: 500;
    cursor: pointer;
    border: none;
}

.cancel-button {
    background-color: #e9ecef;
    color: #2d3436;
}

.cancel-button:hover {
    background-color: #dee2e6;
}

.submit-button {
    background-color: #007bff;
    color: white;
}

.submit-button:hover {
    background-color: #0069d9;
}

/* Mobile Menu Toggle */
.mobile-menu-toggle {
    display: none;
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: #007bff;
    color: white;
    width: 50px;
    height: 50px;
    border-radius: 50%;
    border: none;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    z-index: 1000;
    cursor: pointer;
    font-size: 20px;
}

/* Responsive styles */
@media (max-width: 992px) {
    .sidebar {
        transform: translateX(-100%);
    }
    
    .main-content {
        margin-left: 0;
    }
    
    .mobile-menu-toggle {
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .sidebar.active {
        transform: translateX(0);
    }
}

@media (max-width: 768px) {
    .form-row {
        flex-direction: column;
    }
    
    .form-group {
        flex: 1 1 100%;
    }
    
    .system-info {
        flex-direction: column;
    }
}

/* JavaScript */
@media (max-width: 576px) {
    .form-buttons {
        flex-direction: column-reverse;
    }
    
    .cancel-button, 
    .submit-button {
        width: 100%;
    }
}
</style>
<script>
    // Mobile menu toggle functionality
    document.getElementById('mobileMenuToggle').addEventListener('click', function() {
        document.querySelector('.sidebar').classList.toggle('active');
    });

    // Initialize signature pad
    document.addEventListener('DOMContentLoaded', function() {
        const canvas = document.getElementById('signature-pad');
        const signaturePad = new SignaturePad(canvas, {
            backgroundColor: 'rgb(255, 255, 255)',
            penColor: 'rgb(0, 0, 0)'
        });
        
        // Adjust canvas size
        function resizeCanvas() {
            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            canvas.width = canvas.offsetWidth * ratio;
            canvas.height = canvas.offsetHeight * ratio;
            canvas.getContext("2d").scale(ratio, ratio);
            signaturePad.clear();
        }
        
        window.onresize = resizeCanvas;
        resizeCanvas();
        
        // Clear signature button functionality
        document.getElementById('clearSignature').addEventListener('click', function() {
            signaturePad.clear();
        });
        
        // Update name and company in consent text when input changes
        document.getElementById('full_name').addEventListener('input', function() {
            document.querySelectorAll('.dynamic-name').forEach(el => {
                el.textContent = this.value;
            });
            
            // Also update the confirm name field if it matches
            const confirmName = document.getElementById('confirm_full_name');
            if (confirmName.value === '' || confirmName.value === document.querySelectorAll('.dynamic-name')[0].textContent) {
                confirmName.value = this.value;
            }
        });
        
        document.getElementById('management_for').addEventListener('input', function() {
            document.querySelectorAll('.dynamic-company').forEach(el => {
                el.textContent = this.value;
            });
        });
        
        // Update recipient email display when email input changes
        document.getElementById('email').addEventListener('input', function() {
            document.getElementById('recipient-email').textContent = this.value || 'client@example.com';
        });
        
        // Form submission - save signature data
        document.getElementById('consentForm').addEventListener('submit', function(e) {
            if (signaturePad.isEmpty()) {
                e.preventDefault();
                alert('Please provide a signature');
                return false;
            }
            
            const signatureData = signaturePad.toDataURL();
            document.getElementById('signature_data').value = signatureData;
        });
    });
</script>

</body>
</html>