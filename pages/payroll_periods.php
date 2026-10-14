<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('superadmin','finance');
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/lcc_payroll.php';
$error=''; $message='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    require_csrf();
    try {
        $action=$_POST['action']??'';
        if ($action==='create') {
            $id=lcc_create_period($conn,(string)($_POST['label']??''),(string)($_POST['period_start']??''),(string)($_POST['period_end']??''),(string)($_POST['pay_date']??''));
            header('Location: payroll_periods.php?id='.$id.'&saved=1#period-details'); exit;
        } elseif ($action==='edit') {
            $id=(int)($_POST['period_id']??0);
            lcc_edit_period($conn,$id,(string)($_POST['label']??''),(string)($_POST['period_start']??''),(string)($_POST['period_end']??''),(string)($_POST['pay_date']??''));
            header('Location: payroll_periods.php?id='.$id.'&saved=1#period-details'); exit;
        } elseif ($action==='generate') {
            $id=(int)($_POST['period_id']??0);
            $employeeId=(int)($_POST['employee_id']??0);
            $ids=[];
            if ($employeeId>0) $ids[]=$employeeId;
            else {
                $all=$conn->query("SELECT employee_id FROM employees WHERE compensation_basis='per_period' AND employment_status<>'Archived' ORDER BY employee_id");
                while ($row=$all->fetch_assoc()) $ids[]=(int)$row['employee_id'];
            }
            if (!$ids) throw new DomainException('No eligible employees have an explicit per-period compensation basis.');
            $done=0; $failed=[];
            foreach ($ids as $oneId) {
                try { lcc_generate_employee($conn,$id,$oneId); $done++; }
                catch (Throwable $e) { $failed[]='Employee #'.$oneId.': '.$e->getMessage(); }
            }
            $message=$done.' Draft payroll record(s) generated or recalculated.';
            if ($failed) $error=implode(' ', $failed);
            $_GET['id']=$id;
        } else throw new InvalidArgumentException('Unknown action.');
    } catch (Throwable $e) { $error=$e->getMessage(); }
}
if (isset($_GET['saved'])) $message='Payroll period saved.';
$selectedId=(int)($_GET['id']??0);
$selected=null; $payroll=[];
if ($selectedId>0) {
    $stmt=$conn->prepare('SELECT * FROM payroll_periods WHERE period_id=?');
    $stmt->bind_param('i',$selectedId); $stmt->execute(); $selected=$stmt->get_result()->fetch_assoc();
    if ($selected) {
        $stmt=$conn->prepare('SELECT p.payroll_id,p.status,p.gross_pay,p.net_pay,e.employee_no,e.first_name,e.last_name FROM payroll_records p JOIN employees e ON e.employee_id=p.employee_id WHERE p.period_id=? ORDER BY e.last_name,e.first_name');
        $stmt->bind_param('i',$selectedId); $stmt->execute(); $payroll=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}
$search=trim((string)($_GET['search']??'')); $status=(string)($_GET['status']??'');
if (!in_array($status,['','Draft','Approved','Paid','Legacy'],true)) $status='';
$like='%'.$search.'%';
$stmt=$conn->prepare("SELECT pp.*,COUNT(p.payroll_id) AS record_count,COALESCE(SUM(p.net_pay),0) AS net_total FROM payroll_periods pp LEFT JOIN payroll_records p ON p.period_id=pp.period_id WHERE (?='' OR pp.label LIKE ?) AND (?='' OR pp.status=?) GROUP BY pp.period_id ORDER BY pp.period_start DESC,pp.period_id DESC");
$stmt->bind_param('ssss',$search,$like,$status,$status); $stmt->execute(); $periods=$stmt->get_result();
$employees=$conn->query("SELECT employee_id,employee_no,first_name,last_name,compensation_basis FROM employees WHERE employment_status<>'Archived' ORDER BY last_name,first_name");
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="icon" href="../assets/favicon.png"><title>LCC Payroll System</title><link rel="stylesheet" href="../assets/css/app.css?v=20261012-nav1"><link rel="stylesheet" href="../assets/css/apple-system.css?v=20261012-ux1"><script src="../assets/js/app.js?v=20261012-nav1" defer></script></head><body class="lcc-ux"><div class="app"><?php sidebar('periods'); ?><main class="main"><?php topbar('Payroll Periods'); ?><div class="content">
<div class="page-heading"><div><div class="eyebrow">PAYROLL MANAGEMENT</div><h1>Payroll Periods</h1><p>Set coverage and pay dates before generating employee payroll.</p></div><a class="btn btn-secondary" href="payroll.php">Payroll Records</a></div>
<div class="lcc-help-strip"><div><strong>What to do here</strong>First, save the dates. Then open that pay period below and create Draft payroll. Need to set pay first?</div><a href="compensation.php">Set base pay →</a></div><?php if ($message): ?><div class="notice ok" role="status"><?php echo e($message); ?></div><?php endif; ?><?php if ($error): ?><div class="notice err" role="alert"><?php echo e($error); ?></div><?php endif; ?>
<section class="card form-card"><div class="card-head"><div><h2><?php echo $selected && $selected['status']==='Draft' && !$payroll ? 'Edit period' : 'Create period'; ?></h2><p>Overlapping periods are rejected. Legacy records keep unknown pay dates.</p></div></div><form method="post"><?php echo csrf_field(); ?><?php $editing=$selected && $selected['status']==='Draft' && !$payroll; ?><input type="hidden" name="action" value="<?php echo $editing?'edit':'create'; ?>"><?php if ($editing): ?><input type="hidden" name="period_id" value="<?php echo $selectedId; ?>"><?php endif; ?><div class="form-body"><div class="form-grid"><div class="field"><label>Pay period name</label><input name="label" maxlength="100" placeholder="Example: October 1–15, 2026" required value="<?php echo e($editing?$selected['label']:''); ?>"></div><div class="field"><label>Start date</label><input type="date" name="period_start" required value="<?php echo e($editing?$selected['period_start']:''); ?>"></div><div class="field"><label>End date</label><input type="date" name="period_end" required value="<?php echo e($editing?$selected['period_end']:''); ?>"></div><div class="field"><label>Date employees will be paid</label><input type="date" name="pay_date" required value="<?php echo e($editing?$selected['pay_date']:''); ?>"></div></div></div><div class="form-actions"><button class="btn btn-primary" type="submit"><?php echo $editing?'Save changes':'Save pay period'; ?></button><?php if ($editing): ?><a class="btn btn-secondary" href="payroll_periods.php">Cancel edit</a><?php endif; ?></div></form></section>
<section class="card"><div class="card-head"><div><h2>Periods</h2></div></div><form class="toolbar" method="get"><div><label>Search label</label><input name="search" value="<?php echo e($search); ?>"></div><div><label>Status</label><select name="status"><option value="">All</option><?php foreach (['Draft','Approved','Paid','Legacy'] as $choice): ?><option value="<?php echo $choice; ?>" <?php echo $status===$choice?'selected':''; ?>><?php echo $choice; ?></option><?php endforeach; ?></select></div><button class="btn btn-secondary">Filter</button></form><div class="table-wrap"><table class="table"><thead><tr><th>Period</th><th>Dates</th><th>Pay date</th><th>Status</th><th>Records</th><th>Net</th><th></th></tr></thead><tbody><?php if (!$periods->num_rows): ?><tr><td colspan="7" class="empty">No periods found.</td></tr><?php else: while ($row=$periods->fetch_assoc()): ?><tr><td><strong><?php echo e($row['label']); ?></strong></td><td><?php echo e($row['period_start'].' – '.$row['period_end']); ?></td><td><?php echo e($row['pay_date']?:'Unknown'); ?></td><td><span class="badge"><?php echo e($row['status']); ?></span></td><td><?php echo (int)$row['record_count']; ?></td><td><?php echo money($row['net_total']); ?></td><td><a class="btn btn-secondary btn-small" href="payroll_periods.php?id=<?php echo (int)$row['period_id']; ?>#period-details">Open period</a></td></tr><?php endwhile; endif; ?></tbody></table></div></section>
<?php if ($selected): ?><section class="card" id="period-details"><div class="card-head"><div><h2><?php echo e($selected['label']); ?></h2><p><?php echo e($selected['period_start'].' – '.$selected['period_end']); ?> · Pay date <?php echo e($selected['pay_date']?:'unknown'); ?></p></div></div><?php if ($selected['status']==='Draft'): ?><form method="post" class="toolbar"><?php echo csrf_field(); ?><input type="hidden" name="action" value="generate"><input type="hidden" name="period_id" value="<?php echo $selectedId; ?>"><div><label>Employee</label><select name="employee_id"><option value="0">All configured employees</option><?php while($employee=$employees->fetch_assoc()): ?><option value="<?php echo (int)$employee['employee_id']; ?>"><?php echo e($employee['employee_no'].' · '.$employee['last_name'].', '.$employee['first_name'].' · '.($employee['compensation_basis']?:'basis missing')); ?></option><?php endwhile; ?></select></div><button class="btn btn-primary" type="submit">Create Draft payroll</button></form><?php endif; ?><div class="table-wrap"><table class="table"><thead><tr><th>Employee</th><th>Gross</th><th>Net</th><th>Status</th><th></th></tr></thead><tbody><?php if (!$payroll): ?><tr><td colspan="5" class="empty">No payroll records in this period.</td></tr><?php else: foreach ($payroll as $record): ?><tr><td><?php echo e($record['employee_no'].' · '.$record['last_name'].', '.$record['first_name']); ?></td><td><?php echo money($record['gross_pay']); ?></td><td><?php echo money($record['net_pay']); ?></td><td><span class="badge"><?php echo e($record['status']); ?></span></td><td><a class="btn btn-secondary btn-small" href="payroll.php?period_id=<?php echo $selectedId; ?>">Open payroll</a></td></tr><?php endforeach; endif; ?></tbody></table></div></section><?php endif; ?>
</div></main></div></body></html>
