<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('superadmin', 'finance', 'hr');
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/lcc_payroll.php';

/* HR sees a masked, read-only view: pay figures are never queried and the
   create/status actions are refused server-side (not just hidden). */
$isHr = admin_role() === 'hr';

if (($_GET['quote'] ?? '') === '1') {
    require_role('superadmin', 'finance');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        [$scheduled, $total] = lcc_scheduled_deductions($conn, (int)($_GET['employee_id'] ?? 0), (string)($_GET['pay_date'] ?? ''));
        echo json_encode(['total' => lcc_decimal($total), 'items' => array_map(static fn($line) => ['name' => $line[2], 'amount' => lcc_decimal($line[3])], $scheduled)]);
    } catch (Throwable $e) {
        http_response_code(422);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

$message = '';
$error = '';
$showForm = isset($_GET['add']) && $_GET['add'] === '1';
if ($isHr) {
    $showForm = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role('superadmin', 'finance');
    require_csrf();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'create') {
            lcc_create_simple_payroll($conn, (int)($_POST['employee_id'] ?? 0), (string)($_POST['period_start'] ?? ''),
                (string)($_POST['period_end'] ?? ''), (string)($_POST['pay_date'] ?? ''),
                (string)($_POST['allowances'] ?? '0'), (string)($_POST['other_earnings'] ?? '0'),
                (string)($_POST['deductions'] ?? '0'), trim((string)($_POST['notes'] ?? '')));
            header('Location: payroll.php?saved=1'); exit;
        }
        if ($action !== 'advance_status') throw new InvalidArgumentException('Unknown payroll action.');
        lcc_transition_payroll($conn,(int)($_POST['payroll_id']??0),(string)($_POST['status']??''));
        header('Location: payroll.php?status_updated=1'); exit;
    } catch (Throwable $e) { $error=$e->getMessage(); if (($_POST['action'] ?? '') === 'create') $showForm = true; }
}
$formValues = (($_POST['action'] ?? '') === 'create') ? $_POST : [];

if (isset($_GET['saved'])) {
    $message = 'Payroll record saved successfully.';
}
if (isset($_GET['status_updated'])) {
    $message = 'Payroll status updated.';
}

$filterPeriodId = max(0, (int)($_GET['period_id'] ?? 0));
$sql = $isHr
    ? 'SELECT p.payroll_id,p.employee_id,p.period_start,p.period_end,p.status,e.employee_no,e.first_name,e.middle_name,e.last_name,e.department,e.position FROM payroll_records p JOIN employees e ON e.employee_id=p.employee_id'
    : 'SELECT p.*,e.employee_no,e.first_name,e.middle_name,e.last_name,e.department,e.position FROM payroll_records p JOIN employees e ON e.employee_id=p.employee_id';
if ($filterPeriodId > 0) {
    $stmt = $conn->prepare($sql . ' WHERE p.period_id=? ORDER BY p.payroll_id DESC');
    $stmt->bind_param('i', $filterPeriodId);
    $stmt->execute();
    $records = $stmt->get_result();
} else {
    $records = $conn->query($sql . ' ORDER BY p.payroll_id DESC');
}
$employees = $isHr ? false : $conn->query("SELECT employee_id,employee_no,first_name,last_name,basic_salary FROM employees WHERE employment_status<>'Archived' ORDER BY last_name,first_name");

/* HR attendance-per-period: one query for the whole list, bucketed in PHP
   (no N+1), so HR can verify attendance logs behind each pay period. */
$attByRecord = [];
if ($isHr && $records && $records->num_rows > 0) {
    $rows = [];
    $minStart = null; $maxEnd = null; $empIds = [];
    while ($r = $records->fetch_assoc()) {
        $rows[] = $r;
        $empIds[(int)$r['employee_id']] = true;
        if ($minStart === null || $r['period_start'] < $minStart) $minStart = $r['period_start'];
        if ($maxEnd === null || $r['period_end'] > $maxEnd) $maxEnd = $r['period_end'];
    }
    // Rewind for the template loop below.
    $records->data_seek(0);
    if ($empIds && $minStart !== null) {
        $ids = implode(',', array_map('intval', array_keys($empIds)));
        $stmt = $conn->prepare("SELECT employee_id,attendance_date,status,time_in FROM attendance WHERE employee_id IN ($ids) AND attendance_date >= ? AND attendance_date <= ?");
        if ($stmt) {
            $stmt->bind_param('ss', $minStart, $maxEnd);
            $stmt->execute();
            $logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($rows as $r) {
                $logged = 0; $late = 0;
                foreach ($logs as $l) {
                    if ((int)$l['employee_id'] !== (int)$r['employee_id']) continue;
                    if ($l['attendance_date'] < $r['period_start'] || $l['attendance_date'] > $r['period_end']) continue;
                    if ($l['time_in'] !== null || $l['status'] !== 'Absent') $logged++;
                    if ($l['status'] === 'Late') $late++;
                }
                $attByRecord[(int)$r['payroll_id']] = $logged . ' day' . ($logged === 1 ? '' : 's') . ' logged' . ($late ? ' · ' . $late . ' late' : '');
            }
        }
    }
    foreach ($rows as $r) {
        if (!isset($attByRecord[(int)$r['payroll_id']])) $attByRecord[(int)$r['payroll_id']] = 'No logs in period';
    }
}

?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<!-- FAVICON: Put your favicon file at /assets/favicon.png -->
<link rel="icon" type="image/png" href="../assets/favicon.png">
    <title>LCC Payroll System</title>
    <link rel="stylesheet" href="../assets/css/app.css?v=20261014-payroll1">
    <link rel="stylesheet" href="../assets/css/apple-system.css?v=20261012-ux1">
    <script src="../assets/js/app.js?v=20261012-nav1" defer></script>
    <script src="../assets/js/apple-motion.js?v=20261012-nav1" defer></script>
</head>

<body class="lcc-ux">
    <div class="app">
        <?php sidebar('payroll'); ?>
        <main class="main">
            <?php topbar('Payroll'); ?>
            <div class="content">
                <div class="page-heading">
                    <div>
                        <?php if ($isHr): ?>
                        <div class="eyebrow">Attendance Review</div>
                        <h1>Payroll Periods</h1>
                        <p>Verify the attendance logs behind each pay period. Pay figures are handled by Finance.</p>
                        <?php else: ?>
                        <div class="eyebrow">Payroll Management</div>
                        <h1><?php echo $showForm ? 'Add New Payroll' : 'All Payroll'; ?></h1>
                        <p>Choose an employee, enter the dates and any extra amounts, then save. Loan and recurring deductions are added automatically.</p>
                        <?php endif; ?>
                    </div>
                    <?php if (!$isHr): ?>
                    <div class="print-tools">
                        <a class="btn btn-secondary" href="reports.php"><?php echo ui_icon('file-chart'); ?> Payroll Reports</a>
                        <?php if (!$showForm): ?><button type="button" class="btn btn-secondary" onclick="window.print()"><?php echo ui_icon('printer'); ?> Print All Payroll</button><?php endif; ?>
                        <?php if (!$showForm): ?><a class="btn btn-primary" href="payroll.php?add=1#new-payroll"><?php echo ui_icon('plus'); ?> Add New Payroll</a><?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($message): ?><div class="notice ok"><?php echo e($message); ?></div><?php endif; ?>
                <?php if ($error): ?><div class="notice err"><?php echo e($error); ?></div><?php endif; ?>
                <?php if ($filterPeriodId): ?><div class="lcc-help-strip"><div><strong>Showing one pay period</strong>These are the payroll records for the pay period you opened.</div><a href="payroll.php">Show all payroll →</a></div><?php endif; ?>

                <?php if (!$isHr): ?><div class="lcc-help-strip"><div><strong>Simple payroll</strong>Add a payroll here, review the Draft, then approve it. Record payment only after the employee has actually been paid.</div><a href="recurring_deductions.php">Manage recurring deductions →</a></div><?php endif; ?>

                <?php if (!$showForm): ?>
                <section class="card payroll-list-card">
                    <div class="card-head">
                        <div>
                            <div class="eyebrow">Payroll Records</div>
                            <?php if ($isHr): ?>
                            <h2>Periods & Attendance</h2>
                            <p>Attendance logs per pay period. Open Attendance for day-by-day detail.</p>
                            <?php else: ?>
                            <h2>All Payroll Records</h2>
                            <p>Open a record to print a clean payroll slip.</p>
                            <?php endif; ?>
                        </div>
                        
                    </div>
                    <div class="table-wrap">
                        <table class="table payroll-table">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Department</th>
                                    <th>Position</th>
                                    <th>Period</th>
                                    <?php if ($isHr): ?>
                                    <th>Attendance</th>
                                    <th>Status</th>
                                    <?php else: ?>
                                    <th>Gross Pay</th>
                                    <th>Net Pay</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$records || $records->num_rows === 0): ?>
                                    <tr>
                                        <?php if ($isHr): ?>
                                        <td colspan="6" class="empty">No payroll periods yet.</td>
                                        <?php else: ?>
                                        <td colspan="8" class="empty">No payroll records yet. Select <strong>Add New Payroll</strong> to create the first one.</td>
                                        <?php endif; ?>
                                    </tr>
                                    <?php else: while ($row = $records->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo e($row['last_name'] . ', ' . $row['first_name']); ?></strong>
                                                <div class="mini">Employee ID: <?php echo e($row['employee_no']); ?></div>
                                            </td>
                                            <td><?php echo e($row['department'] ?: 'Not assigned'); ?></td>
                                            <td><?php echo e($row['position'] ?: 'Not assigned'); ?></td>
                                            <td><?php echo e(date('M d, Y', strtotime($row['period_start'])) . ' - ' . date('M d, Y', strtotime($row['period_end']))); ?></td>
                                            <?php if ($isHr): ?>
                                            <td><a class="dash-link" style="margin:0;padding:0;border:0" href="attendance.php"><?php echo e($attByRecord[(int)$row['payroll_id']] ?? 'No logs in period'); ?></a></td>
                                            <td><span class="badge"><?php echo e($row['status']); ?></span></td>
                                            <?php else: ?>
                                            <td><?php echo money($row['gross_pay']); ?></td>
                                            <td><strong><?php echo money($row['net_pay']); ?></strong></td>
                                            <td><span class="badge"><?php echo e($row['status']); ?></span><?php if (!empty($row['period_id']) && in_array($row['status'],['Draft','Approved'],true)): ?><form method="post" style="display:inline" onsubmit="return confirm(<?php echo $row['status']==='Draft' ? "'Approve these amounts and lock this payroll record?'" : "'Confirm that payment has actually been made? This also posts any loan repayment.'"; ?>)"><?php echo csrf_field(); ?><input type="hidden" name="action" value="advance_status"><input type="hidden" name="payroll_id" value="<?php echo (int)$row['payroll_id']; ?>"><input type="hidden" name="status" value="<?php echo $row['status']==='Draft'?'Approved':'Paid'; ?>"><button type="submit" class="btn btn-secondary"><?php echo $row['status']==='Draft'?'Approve payroll':'Record payment'; ?></button></form><?php endif; ?></td>
                                            <td><button type="button" class="btn btn-secondary btn-small" onclick="printPayrollRecord(<?php echo (int)$row['payroll_id']; ?>); return false;">View / print</button></td>
                                            <?php endif; ?>
                                        </tr>
                                <?php endwhile;
                                endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <?php if (!$isHr): ?>
                <section class="card report-shortcut">
                    <div class="report-shortcut-icon"><?php echo ui_icon('file-chart'); ?></div>
                    <div><div class="eyebrow">Payroll Reporting</div><h3>Need a month-by-month view?</h3><p>Open Payroll Reports to filter a month, review totals, and create a formal printable register.</p></div>
                    <a class="btn btn-secondary" href="reports.php">Open Reports <?php echo ui_icon('arrow-right'); ?></a>
                </section>
                <?php endif; ?>

                <div id="nativePayrollPrintSheet" class="native-print-sheet" aria-hidden="true"></div>
                <?php endif; ?>

    <?php if ($showForm): ?>
                    <section class="card form-card payroll-form-card" id="new-payroll">
                        <div class="card-head">
                            <div>
                                <div class="eyebrow">New Payroll</div>
                                <h2>Add New Payroll</h2>
                                <p>Basic salary comes from the employee record. Active loans and recurring deductions are included when you save.</p>
                            </div>
                            <a class="btn btn-secondary" href="payroll.php">Cancel</a>
                        </div>
                        <form method="post">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="create">
                            <div class="form-body">
                                <div class="section-title">Employee and Payroll Period</div>
                                <div class="form-grid">
                                    <div class="field full">
                                        <label>Employee <span class="required">*</span></label>
                                        <select name="employee_id" id="employee_id" required>
                                            <option value="">Select employee</option>
                                            <?php while ($employee = $employees->fetch_assoc()): ?>
                                                <option value="<?php echo (int)$employee['employee_id']; ?>" data-salary="<?php echo e($employee['basic_salary']); ?>" <?php echo (int)($formValues['employee_id'] ?? 0) === (int)$employee['employee_id'] ? 'selected' : ''; ?>>
                                                    <?php echo e($employee['employee_no'] . ' — ' . $employee['last_name'] . ', ' . $employee['first_name']); ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                    <div class="field"><label>Period Start <span class="required">*</span></label><input type="date" name="period_start" value="<?php echo e($formValues['period_start'] ?? ''); ?>" required></div>
                                    <div class="field"><label>Period End <span class="required">*</span></label><input type="date" name="period_end" value="<?php echo e($formValues['period_end'] ?? ''); ?>" required></div>
                                    <div class="field"><label>Pay Date <span class="required">*</span></label><input type="date" name="pay_date" id="pay_date" value="<?php echo e($formValues['pay_date'] ?? ''); ?>" required></div>
                                </div>

                                <div class="section-title">Earnings and Deductions</div>
                                <div class="form-grid">
                                    <div class="field"><label>Basic Salary</label><input id="basic_salary" value="0.00" disabled></div>
                                    <div class="field"><label>Allowances</label><input type="number" min="0" step="0.01" name="allowances" id="allowances" value="<?php echo e($formValues['allowances'] ?? '0'); ?>"></div>
                                    <div class="field"><label>Other Earnings</label><input type="number" min="0" step="0.01" name="other_earnings" id="other_earnings" value="<?php echo e($formValues['other_earnings'] ?? '0'); ?>"></div>
                                    <div class="field"><label>Other Deductions</label><input type="number" min="0" step="0.01" name="deductions" id="deductions" value="<?php echo e($formValues['deductions'] ?? '0'); ?>"><small>Loan installments, SSS, Pag-IBIG and PhilHealth amounts are added automatically.</small></div>
                                    <div class="field full"><label>Notes</label><textarea name="notes" placeholder="Optional payroll notes"><?php echo e($formValues['notes'] ?? ''); ?></textarea></div>
                                </div>

                                <div class="pay-preview">
                                    <div><span>Gross Pay</span><strong id="gross">₱ 0.00</strong></div>
                                    <div><span>Automatic Deductions</span><strong id="automaticTotal">₱ 0.00</strong></div>
                                    <div><span>Estimated Net Pay</span><strong id="net">₱ 0.00</strong></div>
                                </div>
                                <div class="payroll-auto-breakdown" id="automaticBreakdown">Choose an employee and pay date to see scheduled loan and recurring deductions.</div>
                            </div>
                            <div class="form-actions">
                                <button class="btn btn-primary" type="submit">✓ Save Payroll</button>
                                <a class="btn btn-secondary" href="payroll.php">Cancel</a>
                            </div>
                        </form>
                    </section>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <?php if ($showForm): ?>
        <script>
            let automaticDeductions = 0;
            let quoteController;
            const peso = value => '₱ ' + value.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            function updatePay() {
                const employee = document.getElementById('employee_id');
                const selected = employee.options[employee.selectedIndex];
                const basic = parseFloat(selected?.dataset.salary || 0);
                const allowances = parseFloat(document.getElementById('allowances').value || 0);
                const other = parseFloat(document.getElementById('other_earnings').value || 0);
                const deductions = parseFloat(document.getElementById('deductions').value || 0);
                const gross = basic + allowances + other;
                const net = gross - deductions - automaticDeductions;
                document.getElementById('basic_salary').value = basic.toFixed(2);
                document.getElementById('gross').textContent = peso(gross);
                document.getElementById('automaticTotal').textContent = peso(automaticDeductions);
                document.getElementById('net').textContent = peso(net);
            }
            async function loadAutomaticDeductions() {
                quoteController?.abort();
                automaticDeductions = 0;
                const breakdown = document.getElementById('automaticBreakdown');
                const employeeId = document.getElementById('employee_id').value;
                const payDate = document.getElementById('pay_date').value;
                breakdown.textContent = 'Choose an employee and pay date to see scheduled loan and recurring deductions.';
                updatePay();
                if (!employeeId || !payDate) return;
                quoteController = new AbortController();
                const controller = quoteController;
                breakdown.textContent = 'Checking scheduled deductions…';
                try {
                    const response = await fetch('payroll.php?quote=1&employee_id=' + encodeURIComponent(employeeId) + '&pay_date=' + encodeURIComponent(payDate), { signal: controller.signal, credentials: 'same-origin' });
                    const data = await response.json();
                    if (!response.ok) throw new Error(data.error || 'Could not check scheduled deductions.');
                    if (controller.signal.aborted) return;
                    automaticDeductions = Number(data.total) || 0;
                    breakdown.replaceChildren();
                    if (!data.items.length) breakdown.textContent = 'No scheduled deductions for this employee and pay date.';
                    data.items.forEach(item => {
                        const row = document.createElement('div');
                        const label = document.createElement('span');
                        const amount = document.createElement('strong');
                        label.textContent = item.name;
                        amount.textContent = peso(Number(item.amount));
                        row.append(label, amount);
                        breakdown.append(row);
                    });
                    updatePay();
                } catch (error) {
                    if (!controller.signal.aborted) breakdown.textContent = error.message;
                }
            }
            document.getElementById('employee_id').addEventListener('change', loadAutomaticDeductions);
            document.getElementById('pay_date').addEventListener('change', loadAutomaticDeductions);
            document.querySelector('[name="period_end"]').addEventListener('change', function () {
                const payDate = document.getElementById('pay_date');
                if (!payDate.value) { payDate.value = this.value; loadAutomaticDeductions(); }
            });
            document.getElementById('allowances').addEventListener('input', updatePay);
            document.getElementById('other_earnings').addEventListener('input', updatePay);
            document.getElementById('deductions').addEventListener('input', updatePay);
            updatePay();
            loadAutomaticDeductions();
        </script>
    <?php endif; ?>
<script>
async function printPayrollRecord(id){
    const sheet=document.getElementById('nativePayrollPrintSheet');
    if(!sheet) return;

    sheet.innerHTML='<div class="print-loading">Preparing payroll statement…</div>';
    sheet.classList.add('active');

    const cleanup=()=>{
        sheet.classList.remove('active');
        sheet.innerHTML='';
    };

    window.addEventListener('afterprint', cleanup, {once:true});

    try{
        const response=await fetch('payroll_preview.php?id='+encodeURIComponent(id),{headers:{'X-Requested-With':'XMLHttpRequest'}});
        if(!response.ok) throw new Error('Request failed');
        sheet.innerHTML=await response.text();
        window.print();
    }catch(error){
        cleanup();
        alert('Unable to prepare the payroll statement.');
    }
}
</script>
</body>

</html>
