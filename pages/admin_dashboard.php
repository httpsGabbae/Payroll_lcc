<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

/* Role workspaces (5 fixes to AI slop in dashboards):
   - superadmin = System control center (exceptions + accounts, counts only, no peso figures)
   - finance = Payroll pipeline (money owned here: Draft -> Approved -> Paid)
   - hr = People operations (attendance today + record completeness, no money)
   Each workspace has its own H1, primary metric, actions and empty states.
   Every number links to the screen that explains it. */
$dashRole = admin_role();
$canPeople = in_array($dashRole, ['superadmin', 'hr'], true);
$canPay = in_array($dashRole, ['superadmin', 'finance'], true);

$totalEmployees = (int)$conn->query("SELECT COUNT(*) total FROM employees")->fetch_assoc()['total'];
$activeEmployees = (int)$conn->query("SELECT COUNT(*) total FROM employees WHERE COALESCE(employment_status,'') NOT IN ('Separated','Inactive')")->fetch_assoc()['total'];
$regularEmployees = (int)$conn->query("SELECT COUNT(*) total FROM employees WHERE employment_status='Regular'")->fetch_assoc()['total'];
$totalDepartments = (int)$conn->query("SELECT COUNT(*) total FROM departments WHERE status='Active'")->fetch_assoc()['total'];

$today = date('Y-m-d');
$timedIn = $completed = $late = 0;
if ($r = $conn->query("SELECT COUNT(CASE WHEN time_in IS NOT NULL THEN 1 END) timed_in, COUNT(CASE WHEN time_in IS NOT NULL AND time_out IS NOT NULL THEN 1 END) completed, COUNT(CASE WHEN status='Late' THEN 1 END) late_count FROM attendance WHERE attendance_date='" . $conn->real_escape_string($today) . "'")) {
    $a = $r->fetch_assoc(); $timedIn=(int)$a['timed_in']; $completed=(int)$a['completed']; $late=(int)$a['late_count'];
}

$payrollCount = $payrollGross = $payrollNet = $payrollDed = $payrollPaid = $payrollDrafts = 0; $payrollLabel = 'No payroll records yet — create the first payroll to start the pipeline';
if ($r = $conn->query("SELECT COUNT(*) total, COALESCE(SUM(p.gross_pay),0) gross, COALESCE(SUM(p.net_pay),0) net, COALESCE(SUM(p.deductions),0) ded, COALESCE(SUM(CASE WHEN p.status='Paid' THEN 1 ELSE 0 END),0) paid, COALESCE(SUM(CASE WHEN p.status='Draft' THEN 1 ELSE 0 END),0) drafts FROM payroll_records p JOIN payroll_periods pp ON pp.period_id=p.period_id WHERE pp.pay_date >= '" . date('Y-m-01') . "' AND pp.pay_date <= '" . date('Y-m-t') . "'")) {
    $p = $r->fetch_assoc(); $payrollCount=(int)$p['total']; $payrollGross=(float)$p['gross']; $payrollNet=(float)$p['net']; $payrollDed=(float)$p['ded']; $payrollPaid=(int)$p['paid']; $payrollDrafts=(int)$p['drafts'];
}
if ($payrollCount) $payrollLabel = $payrollCount . ' payroll record' . ($payrollCount === 1 ? '' : 's') . ' this month';

$missingDept = (int)$conn->query("SELECT COUNT(*) total FROM employees WHERE department IS NULL OR TRIM(department)='' ")->fetch_assoc()['total'];
$missingSalary = (int)$conn->query("SELECT COUNT(*) total FROM employees WHERE basic_salary IS NULL OR basic_salary<=0")->fetch_assoc()['total'];

$hireTrend = []; $hireLabels = [];
for ($i = 5; $i >= 0; $i--) {
    $k = date('Y-m', strtotime("first day of -$i month"));
    $hireLabels[] = date('M', strtotime($k . '-01'));
    $hireTrend[$k] = 0;
}
$cut = date('Y-m-01', strtotime('-5 month'));
$hireStmt = $conn->prepare("SELECT EXTRACT(YEAR FROM created_at) AS y, EXTRACT(MONTH FROM created_at) AS m, COUNT(*) c FROM employees WHERE created_at IS NOT NULL AND created_at >= ? GROUP BY EXTRACT(YEAR FROM created_at), EXTRACT(MONTH FROM created_at) ORDER BY y, m");
if ($hireStmt) {
    $hireStmt->bind_param('s', $cut);
    $hireStmt->execute();
    $r = $hireStmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $k = sprintf('%04d-%02d', (int)$row['y'], (int)$row['m']);
        if (array_key_exists($k, $hireTrend)) $hireTrend[$k] = (int)$row['c'];
    }
}
$hireVals = array_values($hireTrend);
$hireMax = max(1, max($hireVals));
$hiredThisMonth = end($hireVals);
$pts = []; $n = count($hireVals);
foreach ($hireVals as $i => $v) {
    $x = $n > 1 ? 10 + ($i / ($n - 1)) * 280 : 150;
    $y = 86 - ($v / $hireMax) * 68;
    $pts[] = [round($x, 1), round($y, 1)];
}
$line = 'M ' . $pts[0][0] . ' ' . $pts[0][1];
foreach (array_slice($pts, 1) as $p) $line .= ' L ' . $p[0] . ' ' . $p[1];
$area = $line . ' L ' . $pts[$n-1][0] . ' 96 L ' . $pts[0][0] . ' 96 Z';
$last = $pts[$n-1];

$accountCount = 0;
if ($dashRole === 'superadmin' && ($r = $conn->query('SELECT COUNT(*) total FROM admins'))) {
    $accountCount = (int)$r->fetch_assoc()['total'];
}

$issues = [];
if ($late) $issues[] = ['attendance.php', $late . ' late attendance record' . ($late === 1 ? '' : 's')];
if ($missingDept) $issues[] = ['employees.php', $missingDept . ' without department'];
if ($missingSalary) $issues[] = ['employees.php', $missingSalary . ' without salary'];
$attentionTarget = $issues ? $issues[0][0] : ($dashRole === 'hr' ? 'attendance.php' : 'reports.php');
$attentionText = $issues ? implode(' · ', array_column($issues, 1)) : 'All clear — nothing needs attention.';
if ($dashRole === 'finance') {
    $attentionTarget = 'payroll.php';
}

/* Role task headers: direct labels, no greeting hero, no eyebrow. */
if ($dashRole === 'finance') {
    $workspaceKicker = 'Finance · ' . date('F Y');
    $workspaceTitle = 'Payroll pipeline';
    $workspaceSub = money($payrollNet) . ' net this month · ' . $payrollPaid . ' paid · ' . $payrollDrafts . ' awaiting approval. Draft to Paid lives here.';
} elseif ($dashRole === 'hr') {
    $workspaceKicker = 'People operations · ' . date('l, F d');
    $workspaceTitle = 'People operations';
    $workspaceSub = $activeEmployees . ' active employee' . ($activeEmployees === 1 ? '' : 's') . ' · ' . $timedIn . ' in today · ' . $late . ' late. Verify attendance before payroll runs.';
} elseif ($dashRole === 'superadmin') {
    $workspaceKicker = 'System control center · ' . date('l, F d');
    $workspaceTitle = 'System control center';
    $workspaceSub = $activeEmployees . ' active · ' . $timedIn . ' in today · ' . $payrollCount . ' payroll records · ' . $accountCount . ' accounts. Exceptions first.';
} else {
    $workspaceKicker = 'Workspace · ' . date('l, F d');
    $workspaceTitle = 'Workspace';
    $workspaceSub = $activeEmployees . ' active · ' . $timedIn . ' in today.';
}
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<link rel="icon" type="image/png" href="../assets/favicon.png"><title>LCC Payroll System</title>
<link rel="stylesheet" href="../assets/css/app.css?v=20261012-nav1"><link rel="stylesheet" href="../assets/css/apple-system.css?v=20261012-ux1"><link rel="stylesheet" href="../assets/css/dashboard-polish.css?v=20261005-polish2"><script src="../assets/js/app.js?v=20261012-nav1" defer></script><script src="../assets/js/apple-motion.js?v=20261012-nav1" defer></script>
</head><body><div class="app">
<?php sidebar('dashboard'); ?><main class="main"><?php topbar('Dashboard'); ?><div class="content">
<section class="dash-greet">
  <div class="dash-greet-text"><p class="dash-kicker"><?php echo e($workspaceKicker); ?></p><h1><?php echo e($workspaceTitle); ?></h1><p><?php echo e($workspaceSub); ?></p></div>
  <div class="dash-greet-actions"><?php if ($canPeople): ?><a class="btn btn-primary" href="personalinfo.php?mode=new"><?php echo ui_icon('plus'); ?> Add employee</a><?php endif; ?><?php if ($canPay): ?><a class="btn <?php echo $canPeople ? 'btn-secondary' : 'btn-primary'; ?>" href="payroll.php?add=1#new-payroll">Add New Payroll</a><?php endif; ?></div>
</section>

<div class="dash-grid">
<?php if ($dashRole === 'finance'): ?>
<section class="card dash-employees">
  <p class="dash-kicker">Payroll · <?php echo e(date('F Y')); ?></p>
  <div class="dash-big"><?php echo money($payrollNet); ?></div>
  <div class="dash-sub">Net pay · gross <?php echo money($payrollGross); ?> · deductions <?php echo money($payrollDed); ?></div>
  <?php if (!$payrollCount): ?><div class="dash-empty">No payroll records yet — <a href="payroll.php?add=1#new-payroll">add the first payroll</a> to get started.</div><?php endif; ?>
  <div class="dash-months"><span><?php echo $payrollPaid; ?> paid</span><span><?php echo $payrollDrafts; ?> drafts</span><span><?php echo $payrollCount; ?> records</span></div>
  <a class="dash-link" href="reports.php">Open reports →</a>
</section>
<?php elseif ($dashRole === 'hr'): ?>
<section class="card dash-employees">
  <p class="dash-kicker">Attendance · today</p>
  <div class="dash-big"><?php echo $timedIn; ?></div>
  <div class="dash-sub">timed in · <?php echo $late; ?> late · <?php echo $completed; ?> done · <?php echo $activeEmployees; ?> active employees</div>
  <?php if (!$timedIn): ?><div class="dash-empty">Nobody timed in yet today — <a href="attendance.php">open attendance</a> to review.</div><?php endif; ?>
  <div class="dash-months"><?php foreach ($hireLabels as $l): ?><span><?php echo e($l); ?></span><?php endforeach; ?></div>
  <?php if ($canPeople): ?><a class="dash-link" href="employees.php">View directory →</a><?php endif; ?>
</section>
<?php else: ?>
<section class="card dash-employees">
  <p class="dash-kicker">Workforce · 6-month hires</p>
  <div class="dash-big"><?php echo $activeEmployees; ?></div>
  <div class="dash-sub"><?php echo $regularEmployees; ?> regular<?php echo $hiredThisMonth ? ' · ' . $hiredThisMonth . ' joined this month' : ' · ' . $totalEmployees . ' total records'; ?></div>
  <svg class="dash-spark" viewBox="0 0 300 96" role="img" aria-label="Hires over the last six months" preserveAspectRatio="none"><path class="spark-area" d="<?php echo e($area); ?>"/><path class="spark-line" d="<?php echo e($line); ?>"/><circle class="spark-dot" cx="<?php echo $last[0]; ?>" cy="<?php echo $last[1]; ?>" r="4"/></svg>
  <div class="dash-months"><?php foreach ($hireLabels as $l): ?><span><?php echo e($l); ?></span><?php endforeach; ?></div>
  <?php if ($canPeople): ?><a class="dash-link" href="employees.php">View directory →</a><?php endif; ?>
</section>
<?php endif; ?>

<div class="dash-rows">
  <?php if ($dashRole === 'finance'): ?>
  <a class="card dash-row" href="payroll.php"><span class="dash-row-icon <?php echo $payrollDrafts ? 'warn' : 'ok'; ?>"><?php echo ui_icon('eye'); ?></span><span class="dash-row-text"><strong>Awaiting approval</strong><small><?php echo $payrollDrafts; ?> draft<?php echo $payrollDrafts === 1 ? '' : 's'; ?> to review<?php echo !$payrollDrafts ? ' — pipeline is clear' : ''; ?></small></span><span class="dash-chev">›</span></a>
  <a class="card dash-row" href="reports.php"><span class="dash-row-icon"><?php echo ui_icon('trend'); ?></span><span class="dash-row-text"><strong>Paid this month</strong><small><?php echo $payrollPaid; ?> paid · <?php echo money($payrollNet); ?> net</small></span><span class="dash-chev">›</span></a>
  <a class="card dash-row" href="payroll.php"><span class="dash-row-icon <?php echo $missingSalary ? 'warn' : 'ok'; ?>"><?php echo ui_icon('wallet'); ?></span><span class="dash-row-text"><strong>Salary gaps</strong><small><?php echo $missingSalary; ?> employee<?php echo $missingSalary === 1 ? '' : 's'; ?> without salary — ask HR to complete</small></span><span class="dash-chev">›</span></a>
  <?php elseif ($dashRole === 'hr'): ?>
  <a class="card dash-row" href="attendance.php"><span class="dash-row-icon"><?php echo ui_icon('clock'); ?></span><span class="dash-row-text"><strong>Attendance today</strong><small><?php echo $timedIn; ?> timed in · <?php echo $late; ?> late · <?php echo $completed; ?> done</small></span><span class="dash-chev">›</span></a>
  <a class="card dash-row" href="<?php echo e($attentionTarget); ?>"><span class="dash-row-icon <?php echo $issues ? 'warn' : 'ok'; ?>"><?php echo ui_icon($issues ? 'eye' : 'shield'); ?></span><span class="dash-row-text"><strong>Needs attention</strong><small><?php echo e($attentionText); ?></small></span><span class="dash-chev">›</span></a>
  <a class="card dash-row" href="payroll.php"><span class="dash-row-icon"><?php echo ui_icon('wallet'); ?></span><span class="dash-row-text"><strong>Payroll periods</strong><small><?php echo e($payrollLabel); ?> · verify attendance first</small></span><span class="dash-chev">›</span></a>
  <?php else: ?>
  <a class="card dash-row" href="attendance.php"><span class="dash-row-icon"><?php echo ui_icon('clock'); ?></span><span class="dash-row-text"><strong>Attendance today</strong><small><?php echo $timedIn; ?> timed in · <?php echo $late; ?> late · <?php echo $completed; ?> done</small></span><span class="dash-chev">›</span></a>
  <a class="card dash-row" href="payroll.php"><span class="dash-row-icon"><?php echo ui_icon('wallet'); ?></span><span class="dash-row-text"><strong>Payroll status</strong><small><?php echo e($payrollLabel); ?> · <?php echo $payrollDrafts; ?> drafts · <?php echo $payrollPaid; ?> paid (amounts in Finance workspace)</small></span><span class="dash-chev">›</span></a>
  <a class="card dash-row" href="<?php echo e($attentionTarget); ?>"><span class="dash-row-icon <?php echo $issues ? 'warn' : 'ok'; ?>"><?php echo ui_icon($issues ? 'eye' : 'shield'); ?></span><span class="dash-row-text"><strong>Needs attention</strong><small><?php echo e($attentionText); ?></small></span><span class="dash-chev">›</span></a>
  <a class="card dash-row" href="accounts.php"><span class="dash-row-icon"><?php echo ui_icon('shield'); ?></span><span class="dash-row-text"><strong>Administration</strong><small><?php echo $accountCount; ?> account<?php echo $accountCount === 1 ? '' : 's'; ?> · roles & access</small></span><span class="dash-chev">›</span></a>
  <?php endif; ?>
</div>
</div>
</div></main></div></body></html>
