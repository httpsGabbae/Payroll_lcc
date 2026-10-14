<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('superadmin', 'finance');
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/lcc_payroll.php';

$error = '';
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $conn->begin_transaction();
        $action = (string)($_POST['action'] ?? '');
        $id = (int)($_POST['deduction_id'] ?? 0);
        if ($action === 'save') {
            $employeeId = (int)($_POST['employee_id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $amount = lcc_cents($_POST['amount'] ?? '');
            $start = (string)($_POST['start_date'] ?? '');
            $end = trim((string)($_POST['end_date'] ?? ''));
            if ($employeeId < 1 || $name === '' || strlen($name) > 100 || $amount < 1 ||
                !lcc_date($start) || ($end !== '' && (!lcc_date($end) || $end < $start))) {
                throw new InvalidArgumentException('Choose an employee, name, positive amount, and valid dates.');
            }
            $stmt = $conn->prepare("SELECT employee_id FROM employees WHERE employee_id=? AND employment_status<>'Archived'");
            $stmt->bind_param('i', $employeeId); $stmt->execute();
            if (!$stmt->get_result()->fetch_assoc()) throw new DomainException('Employee is inactive or missing.');
            $amountSql = lcc_decimal($amount);
            $endSql = $end === '' ? null : $end;
            if ($id > 0) {
                $stmt = $conn->prepare('UPDATE recurring_deductions SET employee_id=?,name=?,amount=?,start_date=?,end_date=? WHERE deduction_id=?');
                $stmt->bind_param('issssi', $employeeId, $name, $amountSql, $start, $endSql, $id);
            } else {
                $actor = (int)$_SESSION['admin_id'];
                $stmt = $conn->prepare('INSERT INTO recurring_deductions(employee_id,name,amount,start_date,end_date,created_by) VALUES(?,?,?,?,?,?)');
                $stmt->bind_param('issssi', $employeeId, $name, $amountSql, $start, $endSql, $actor);
            }
            if (!$stmt->execute()) throw new RuntimeException('Could not save the recurring deduction.');
            if (!$id) $id = $conn->insert_id;
            lcc_audit($conn, 'recurring_deduction_saved', 'recurring_deduction', $id, $name . ' ' . $amountSql);
            $message = 'Recurring deduction saved.';
        } elseif ($action === 'toggle') {
            $stmt = $conn->prepare('UPDATE recurring_deductions SET is_active=1-is_active WHERE deduction_id=?');
            $stmt->bind_param('i', $id);
            if (!$stmt->execute() || $stmt->affected_rows !== 1) throw new DomainException('Deduction not found.');
            lcc_audit($conn, 'recurring_deduction_status_changed', 'recurring_deduction', $id);
            $message = 'Deduction status updated.';
        } else {
            throw new InvalidArgumentException('Unknown action.');
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        $error = $e->getMessage();
    }
}

$edit = null;
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $stmt = $conn->prepare('SELECT * FROM recurring_deductions WHERE deduction_id=?');
    $stmt->bind_param('i', $editId); $stmt->execute();
    $edit = $stmt->get_result()->fetch_assoc();
}
$employees = $conn->query("SELECT employee_id,employee_no,first_name,last_name FROM employees WHERE employment_status<>'Archived' ORDER BY last_name,first_name");
$deductions = $conn->query('SELECT d.*,e.employee_no,e.first_name,e.last_name FROM recurring_deductions d JOIN employees e ON e.employee_id=d.employee_id ORDER BY d.is_active DESC,e.last_name,d.name');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="icon" href="../assets/favicon.png">
    <title>LCC Payroll System</title>
    <link rel="stylesheet" href="../assets/css/app.css?v=20261014-payroll1">
    <link rel="stylesheet" href="../assets/css/apple-system.css?v=20261012-ux1">
    <script src="../assets/js/app.js?v=20261012-nav1" defer></script>
</head>
<body class="lcc-ux">
<div class="app">
    <?php sidebar('recurring_deductions'); ?>
    <main class="main">
        <?php topbar('Recurring Deductions'); ?>
        <div class="content">
            <div class="page-heading">
                <div><div class="eyebrow">PAYROLL DEDUCTIONS</div><h1>Recurring Deductions</h1>
                    <p>Set the amount to deduct each payroll for SSS, Pag-IBIG, PhilHealth, or another recurring item.</p></div>
                <a class="btn btn-secondary" href="payroll.php">Back to Payroll</a>
            </div>
            <?php if ($error): ?><div class="notice err"><?=e($error)?></div><?php endif; ?>
            <?php if ($message): ?><div class="notice ok"><?=e($message)?></div><?php endif; ?>
            <section class="card form-card">
                <div class="card-head"><div><h2><?=$edit ? 'Edit deduction' : 'Add recurring deduction'?></h2>
                    <p>The amount is entered by an administrator and appears automatically in new payroll records within these dates.</p></div></div>
                <form method="post">
                    <?=csrf_field()?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="deduction_id" value="<?=(int)($edit['deduction_id'] ?? 0)?>">
                    <div class="form-body"><div class="form-grid">
                        <div class="field"><label>Employee</label><select name="employee_id" required><option value="">Select employee</option>
                            <?php while ($employee = $employees->fetch_assoc()): ?><option value="<?=(int)$employee['employee_id']?>" <?=((int)($edit['employee_id'] ?? 0) === (int)$employee['employee_id']) ? 'selected' : ''?>><?=e($employee['employee_no'] . ' · ' . $employee['last_name'] . ', ' . $employee['first_name'])?></option><?php endwhile; ?>
                        </select></div>
                        <div class="field"><label>Deduction name</label><input name="name" list="deductionNames" maxlength="100" required placeholder="e.g. SSS" value="<?=e($edit['name'] ?? '')?>">
                            <datalist id="deductionNames"><option value="SSS"><option value="Pag-IBIG"><option value="PhilHealth"></datalist></div>
                        <div class="field"><label>Amount per payroll</label><input type="number" name="amount" min="0.01" step="0.01" required value="<?=e($edit['amount'] ?? '')?>"></div>
                        <div class="field"><label>Start date</label><input type="date" name="start_date" required value="<?=e($edit['start_date'] ?? '')?>"></div>
                        <div class="field"><label>End date (optional)</label><input type="date" name="end_date" value="<?=e($edit['end_date'] ?? '')?>"><small>Leave blank to continue until disabled.</small></div>
                    </div></div>
                    <div class="form-actions"><button class="btn btn-primary">Save deduction</button><?php if ($edit): ?><a class="btn btn-secondary" href="recurring_deductions.php">Cancel</a><?php endif; ?></div>
                </form>
            </section>
            <section class="card"><div class="card-head"><div><h2>Scheduled deductions</h2></div></div>
                <div class="table-wrap"><table class="table"><thead><tr><th>Employee</th><th>Deduction</th><th>Amount per payroll</th><th>Dates</th><th>Status</th><th>Actions</th></tr></thead><tbody>
                    <?php if (!$deductions->num_rows): ?><tr><td colspan="6" class="empty">No recurring deductions yet.</td></tr>
                    <?php else: while ($row = $deductions->fetch_assoc()): ?><tr>
                        <td><?=e($row['employee_no'] . ' · ' . $row['last_name'] . ', ' . $row['first_name'])?></td>
                        <td><?=e($row['name'])?></td><td><?=money($row['amount'])?></td>
                        <td><?=e($row['start_date'])?><?=!empty($row['end_date']) ? ' to ' . e($row['end_date']) : ' onward'?></td>
                        <td><span class="badge"><?=$row['is_active'] ? 'Active' : 'Paused'?></span></td>
                        <td><a class="btn btn-secondary btn-small" href="recurring_deductions.php?edit=<?=(int)$row['deduction_id']?>">Edit</a>
                            <form method="post" style="display:inline"><?=csrf_field()?><input type="hidden" name="action" value="toggle"><input type="hidden" name="deduction_id" value="<?=(int)$row['deduction_id']?>"><button class="btn btn-secondary btn-small"><?=$row['is_active'] ? 'Pause' : 'Resume'?></button></form></td>
                    </tr><?php endwhile; endif; ?>
                </tbody></table></div>
            </section>
        </div>
    </main>
</div>
</body>
</html>
