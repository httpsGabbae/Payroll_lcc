<?php
require_once __DIR__.'/auth.php';
require_once __DIR__.'/layout.php';

$employee = current_employee($conn);
if (!$employee) { header('Location: logout.php'); exit; }

$attStmt = $conn->prepare("SELECT time_in,time_out,status FROM attendance WHERE employee_id=? AND attendance_date=CURRENT_DATE LIMIT 1");
$attStmt->bind_param('i', $employee['employee_id']);
$attStmt->execute();
$todayAttendance = $attStmt->get_result()->fetch_assoc();

$payStmt = $conn->prepare("SELECT COUNT(*) AS total, MAX(period_end) AS latest_period FROM payroll_records WHERE employee_id=? AND status IN ('Approved','Paid')");
$payStmt->bind_param('i', $employee['employee_id']);
$payStmt->execute();
$pay = $payStmt->get_result()->fetch_assoc();

$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$monthStmt = $conn->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN status='Present' THEN 1 ELSE 0 END) AS present, SUM(CASE WHEN status='Late' THEN 1 ELSE 0 END) AS late, SUM(CASE WHEN status='Absent' THEN 1 ELSE 0 END) AS absent FROM attendance WHERE employee_id=? AND attendance_date>=? AND attendance_date<=?");
$monthStmt->bind_param('iss', $employee['employee_id'], $monthStart, $monthEnd);
$monthStmt->execute();
$monthAttendance = $monthStmt->get_result()->fetch_assoc();

$profileFields = ['first_name','last_name','department','position','employment_status'];
$complete = 0;
foreach ($profileFields as $field) { if (!empty(trim((string)($employee[$field] ?? '')))) $complete++; }
$profilePercent = (int)round(($complete / count($profileFields)) * 100);
$stateTitle = $todayAttendance && $todayAttendance['time_in'] ? ($todayAttendance['time_out'] ? 'Done for today' : 'Checked in — remember to time out') : 'Time in to start today';
$stateSub = $todayAttendance && $todayAttendance['time_in'] ? ($todayAttendance['time_out'] ? 'Timed in and out recorded.' : 'Timed in ' . e(date('h:i A', strtotime($todayAttendance['time_in']))) . '.') : 'You have not timed in yet.';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<link rel="icon" type="image/png" href="../assets/favicon.png"><title>LCC Payroll System</title>
<link rel="stylesheet" href="../assets/css/app.css?v=20260909-rail4"><link rel="stylesheet" href="../assets/css/employee.css"><link rel="stylesheet" href="../assets/css/apple-system.css?v=20260916-apple9"><script src="../assets/js/app.js?v=20260916-rail5" defer></script><script src="../assets/js/apple-motion.js?v=20260916-apple1" defer></script>
</head>
<body><div class="app">
<?php employee_sidebar('dashboard'); ?>
<main class="main">
<?php employee_topbar('My work'); ?>
<div class="content">
<section class="dash-greet">
  <div class="dash-greet-text"><p class="dash-kicker">My work · <?php echo e(date('l, F d')); ?></p><h1>My work today</h1><p><?php echo e($stateTitle); ?> · <?php echo (int)($pay['total'] ?? 0); ?> payroll records · profile <?php echo $profilePercent; ?>% complete.</p></div>
</section>

<section class="card dashboard-attendance">
  <div><p class="dash-kicker">Attendance · today</p><h2><?php echo $todayAttendance && $todayAttendance['time_in'] ? ($todayAttendance['time_out'] ? 'Done for today' : 'Checked in') : 'Time in to start today'; ?></h2><p><?php echo $stateSub; ?></p></div>
  <div class="dashboard-attendance-action"><?php if (!$todayAttendance || !$todayAttendance['time_in']): ?><form method="post" action="attendance.php"><?php echo csrf_field(); ?><input type="hidden" name="action" value="time_in"><button class="btn btn-primary" type="submit">Time In</button></form><?php elseif (!$todayAttendance['time_out']): ?><form method="post" action="attendance.php"><?php echo csrf_field(); ?><input type="hidden" name="action" value="time_out"><button class="btn btn-secondary" type="submit">Time Out</button></form><?php else: ?><a class="attendance-link" href="attendance.php">View history</a><?php endif; ?></div>
</section>

<div class="dash-grid">
  <a class="card dash-row" href="payroll.php"><span class="dash-row-icon"><?php echo emp_icon('wallet'); ?></span><span class="dash-row-text"><strong>My payroll</strong><small><?php echo (int)($pay['total'] ?? 0); ?> approved or paid<?php echo !empty($pay['latest_period']) ? ' · latest ' . e(date('M Y', strtotime($pay['latest_period']))) : ' — no records yet'; ?></small></span><span class="dash-chev">›</span></a>
  <a class="card dash-row" href="attendance.php"><span class="dash-row-icon"><?php echo emp_icon('calendar'); ?></span><span class="dash-row-text"><strong>This month</strong><small><?php echo (int)($monthAttendance['total'] ?? 0); ?> days · <?php echo (int)($monthAttendance['present'] ?? 0); ?> present · <?php echo (int)($monthAttendance['late'] ?? 0); ?> late</small></span><span class="dash-chev">›</span></a>
  <section class="card dash-employees dash-identity">
    <p class="dash-kicker">My profile · <?php echo $profilePercent; ?>% complete</p>
    <div class="dash-id-person"><?php if ($employee['photo_path']): ?><img src="<?php echo e(employee_photo_url($employee['photo_path'], (int)$employee['employee_id'])); ?>" class="portal-photo" alt="Employee photo"><?php else: ?><div class="portal-avatar"><?php echo e(strtoupper(substr($employee['first_name'], 0, 1) . substr($employee['last_name'], 0, 1))); ?></div><?php endif; ?><div><strong><?php echo e(trim($employee['first_name'] . ' ' . ($employee['middle_name'] ?? '') . ' ' . $employee['last_name'])); ?></strong><span><?php echo e($employee['employee_no']); ?></span></div></div>
    <div class="dash-sub"><?php echo e($employee['department'] ?: 'Department not assigned'); ?> · <?php echo e($employee['position'] ?: 'Position not assigned'); ?></div>
    <div class="dash-progress" role="progressbar" aria-valuenow="<?php echo $profilePercent; ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Profile completeness"><i style="width:<?php echo $profilePercent; ?>%"></i></div>
    <div class="dash-progress-note"><?php echo $profilePercent >= 100 ? 'Profile complete.' : $profilePercent . '% complete — keep your information updated.'; ?></div>
    <a class="dash-link" href="profile.php">View profile →</a>
  </section>
</div>

</div></main></div></body></html>
