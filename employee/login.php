<?php
require_once __DIR__ . '/../config/database.php';
if (isset($_SESSION['employee_id'])) { header('Location: dashboard.php'); exit; }
$error = '';

// Administrator previews use pages/portal_preview.php and never create employee sessions.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'admin_preview') {
    http_response_code(403);
    exit('Employee impersonation is disabled.');
}

$employeeNo = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $employeeNo = trim($_POST['employee_no'] ?? '');
    $password = $_POST['password'] ?? '';
    $stmt = $conn->prepare('SELECT employee_id,employee_no,portal_password,first_name,last_name,employment_status FROM employees WHERE employee_no=? LIMIT 1');
    $stmt->bind_param('s', $employeeNo); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc();
    $valid = false;
    if ($row && is_active_employment_status($row['employment_status'] ?? '')) {
        $stored=(string)($row['portal_password']??'');
        $valid=$stored!=='' && password_verify($password,$stored);
    }
    if($valid){session_regenerate_id(true);$_SESSION['employee_id']=(int)$row['employee_id'];$_SESSION['employee_no']=$row['employee_no'];$_SESSION['employee_name']=trim($row['first_name'].' '.$row['last_name']);header('Location: dashboard.php');exit;}
    $error='Incorrect employee ID or password.';
}

if (isset($_GET['error']) && $_GET['error'] === 'inactive' && $error === '') {
    $error = 'Your account is no longer active. Please contact the administrator.';
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<!-- FAVICON: Put your favicon file at /assets/favicon.png -->
<link rel="icon" type="image/png" href="../assets/favicon.png"><title>LCC Payroll System</title><link rel="stylesheet" href="../assets/css/login.css"><link rel="stylesheet" href="../assets/css/employee.css"><link rel="stylesheet" href="../assets/css/apple-system.css?v=20260916-apple9"><script src="../assets/js/apple-motion.js?v=20260916-apple1" defer></script></head><body class="employee-login"><script>try{if(localStorage.getItem("lccTheme")==="dark")document.body.classList.add("dark-mode");}catch(_e){}</script>
<section class="brand-side"><div class="brand-content"><img class="login-logo" src="../uploads/logo1.jpg" alt="LCC Logo"><div class="eyebrow" style="color:#b8f1ee">LCC PAYROLL SYSTEM</div><h1>Your employee space, all in one place.</h1><p>Access your profile, payroll records, and daily attendance through a simple employee portal.</p></div></section>
<section class="login-side"><div class="login-card"><div class="login-mobile-brand"><img src="../uploads/logo1.jpg" alt="LCC logo"><div><strong>LCC PAYROLL</strong><span>Employee Portal Sign In</span></div></div><div class="eyebrow">EMPLOYEE PORTAL</div><h2>Welcome back</h2><p class="hint">Sign in with the Employee ID and password provided by your administrator.</p><?php if($error): ?><div class="error" role="alert"><?php echo htmlspecialchars($error); ?></div><?php endif; ?><form method="post" autocomplete="on"><?php echo csrf_field(); ?><label for="employee_no">Employee ID</label><input id="employee_no" type="text" name="employee_no" value="<?php echo htmlspecialchars($employeeNo); ?>" placeholder="25-0001" autocomplete="username" required><label for="password">Password</label><input id="password" type="password" name="password" placeholder="Enter your password" autocomplete="current-password" required><button type="submit">Sign In</button></form><a class="portal-link" href="../login.php">← Administrator Login</a><div class="employee-login-note">Need access? Contact your payroll administrator.</div></div></section></body></html>
