<?php
require_once __DIR__ . '/../includes/auth.php'; require_role('superadmin','finance'); require_once __DIR__.'/../includes/layout.php'; require_once __DIR__.'/../includes/lcc_payroll.php';
$error=''; $message='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    require_csrf();
    try {
        $id=(int)($_POST['employee_id']??0); $basis='per_period';
        if ($id<=0) throw new InvalidArgumentException('Choose an employee.');
        $amount=lcc_cents($_POST['basic_salary']??'');
        if ($amount<=0) throw new InvalidArgumentException('Base pay must be positive.');
        $decimal=lcc_decimal($amount);
        $conn->begin_transaction();
        $stmt=$conn->prepare('UPDATE employees SET basic_salary=?,compensation_basis=? WHERE employee_id=? AND employment_status<>\'Archived\'');
        $stmt->bind_param('ssi',$decimal,$basis,$id);
        if (!$stmt->execute()) throw new RuntimeException('Could not update compensation.');
        $stale=$conn->prepare("UPDATE payroll_records SET needs_recalculation=1 WHERE employee_id=? AND status='Draft' AND compensation_basis_snapshot='per_period'");
        $stale->bind_param('i',$id);$stale->execute();
        lcc_audit($conn,'compensation_updated','employee',$id,'Basis '.$basis.'; amount '.$decimal);
        $conn->commit(); $message='Compensation saved. Existing Approved and Paid payroll snapshots remain unchanged.';
    } catch (Throwable $e) { $conn->rollback(); $error=$e->getMessage(); }
}
$rows=$conn->query("SELECT employee_id,employee_no,first_name,last_name,employment_status,basic_salary,compensation_basis FROM employees ORDER BY last_name,first_name");
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="icon" href="../assets/favicon.png"><title>LCC Payroll System</title><link rel="stylesheet" href="../assets/css/app.css?v=20261012-nav1"><link rel="stylesheet" href="../assets/css/apple-system.css?v=20261012-ux1"><script src="../assets/js/app.js?v=20261012-nav1" defer></script></head><body class="lcc-ux"><div class="app"><?php sidebar('compensation'); ?><main class="main"><?php topbar('Compensation'); ?><div class="content"><div class="page-heading"><div><div class="eyebrow">FINANCE</div><h1>Set Base Pay</h1><p>Enter the amount this employee earns for one payroll period.</p></div></div><div class="lcc-help-strip"><div><strong>Start with base pay</strong>Enter the amount for one payroll period. The Payroll form uses it automatically.</div><a href="payroll.php?add=1#new-payroll">Add New Payroll →</a></div><?php if($message): ?><div class="notice ok"><?php echo e($message); ?></div><?php endif; ?><?php if($error): ?><div class="notice err"><?php echo e($error); ?></div><?php endif; ?><section class="card form-card"><div class="card-head"><div><h2>Set base pay</h2><p>Use the same amount you want to appear as Basic Salary on each payroll record.</p></div></div><form method="post"><?php echo csrf_field(); ?><div class="form-body"><div class="form-grid"><div class="field"><label>Employee</label><select name="employee_id" required><option value="">Select employee</option><?php foreach($rows as $row): if($row['employment_status']==='Archived')continue; ?><option value="<?php echo (int)$row['employee_id']; ?>"><?php echo e($row['employee_no'].' · '.$row['last_name'].', '.$row['first_name']); ?></option><?php endforeach; ?></select></div><div class="field"><label>Base pay per payroll</label><input name="basic_salary" type="number" min="0.01" step="0.01" required></div></div></div><div class="form-actions"><button class="btn btn-primary">Save Base Pay</button></div></form></section><section class="card"><div class="card-head"><div><h2>Current configuration</h2></div></div><div class="table-wrap"><table class="table"><thead><tr><th>Employee</th><th>Basis</th><th>Base amount</th><th>Status</th></tr></thead><tbody><?php foreach($rows as $row): ?><tr><td><?php echo e($row['employee_no'].' · '.$row['last_name'].', '.$row['first_name']); ?></td><td><?php echo e($row['compensation_basis']?:'Not configured'); ?></td><td><?php echo money($row['basic_salary']); ?></td><td><?php echo e($row['employment_status']); ?></td></tr><?php endforeach; ?></tbody></table></div></section></div></main></div></body></html>
