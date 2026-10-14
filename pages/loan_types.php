<?php
require_once __DIR__.'/../includes/auth.php'; require_role('superadmin','finance'); require_once __DIR__.'/../includes/layout.php'; require_once __DIR__.'/../includes/lcc_payroll.php';
$error=''; $message='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    require_csrf();
    try {
        $conn->begin_transaction();
        $action=$_POST['action']??'';
        if ($action==='save') {
            $id=(int)($_POST['loan_type_id']??0); $name=trim((string)($_POST['name']??'')); $description=trim((string)($_POST['description']??''));
            if ($name==='' || strlen($name)>100 || strlen($description)>255) throw new InvalidArgumentException('Enter a name of up to 100 characters and a short description.');
            if ($id>0) { $stmt=$conn->prepare('UPDATE loan_types SET name=?,description=? WHERE loan_type_id=?'); $stmt->bind_param('ssi',$name,$description,$id); }
            else { $stmt=$conn->prepare('INSERT INTO loan_types(name,description) VALUES(?,?)'); $stmt->bind_param('ss',$name,$description); }
            if (!$stmt->execute()) throw new DomainException('Loan type could not be saved. The name may already exist or the record may be in use.');
            lcc_audit($conn,'loan_type_saved','loan_type',$id?:$conn->insert_id,$name);
            $message='Loan type saved.';
        } elseif ($action==='toggle') {
            $id=(int)($_POST['loan_type_id']??0);
            $stmt=$conn->prepare('UPDATE loan_types SET is_active=1-is_active WHERE loan_type_id=?');
            $stmt->bind_param('i',$id);
            if (!$stmt->execute() || $stmt->affected_rows!==1) throw new DomainException('Loan type not found.');
            lcc_audit($conn,'loan_type_status_changed','loan_type',$id);
            $message='Loan type status changed.';
        } else throw new InvalidArgumentException('Unknown action.');
        $conn->commit();
    } catch(Throwable $e) { $conn->rollback(); $error=$e->getMessage(); }
}
$search=trim((string)($_GET['search']??'')); $like='%'.$search.'%';
$stmt=$conn->prepare('SELECT t.*,COUNT(l.loan_id) AS loan_count FROM loan_types t LEFT JOIN employee_loans l ON l.loan_type_id=t.loan_type_id WHERE t.name LIKE ? GROUP BY t.loan_type_id ORDER BY t.name');
$stmt->bind_param('s',$like); $stmt->execute(); $types=$stmt->get_result();
$edit=null; $editId=(int)($_GET['edit']??0);
if($editId>0){$stmt=$conn->prepare('SELECT * FROM loan_types WHERE loan_type_id=?');$stmt->bind_param('i',$editId);$stmt->execute();$edit=$stmt->get_result()->fetch_assoc();}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="icon" href="../assets/favicon.png"><title>LCC Payroll System</title><link rel="stylesheet" href="../assets/css/app.css?v=20261012-nav1"><link rel="stylesheet" href="../assets/css/apple-system.css?v=20261012-ux1"><script src="../assets/js/app.js?v=20261012-nav1" defer></script></head><body class="lcc-ux"><div class="app"><?php sidebar('loan_types'); ?><main class="main"><?php topbar('Loan Types'); ?><div class="content"><div class="page-heading"><div><div class="eyebrow">LOAN MANAGEMENT</div><h1>Loan Types</h1><p>Configure categories without changing historical loans.</p></div><a class="btn btn-secondary" href="loans.php">Employee Loans</a></div><div class="lcc-help-strip"><div><strong>What is a loan category?</strong>It is a name used to group loans, such as emergency or salary advance. Create it before registering a loan.</div><a href="loans.php">Go to employee loans →</a></div><?php if($error): ?><div class="notice err"><?php echo e($error); ?></div><?php endif; ?><?php if($message): ?><div class="notice ok"><?php echo e($message); ?></div><?php endif; ?><section class="card form-card"><div class="card-head"><div><h2><?php echo $edit?'Edit loan type':'Add loan type'; ?></h2></div></div><form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="save"><input type="hidden" name="loan_type_id" value="<?php echo (int)($edit['loan_type_id']??0); ?>"><div class="form-body"><div class="form-grid"><div class="field"><label>Name</label><input name="name" maxlength="100" required value="<?php echo e($edit['name']??''); ?>"></div><div class="field"><label>Description</label><input name="description" maxlength="255" value="<?php echo e($edit['description']??''); ?>"></div></div></div><div class="form-actions"><button class="btn btn-primary">Save</button><?php if($edit): ?><a class="btn btn-secondary" href="loan_types.php">Cancel</a><?php endif; ?></div></form></section><section class="card"><div class="card-head"><div><h2>Configured types</h2></div></div><form method="get" class="toolbar"><div><label>Search</label><input name="search" value="<?php echo e($search); ?>"></div><button class="btn btn-secondary">Search</button></form><div class="table-wrap"><table class="table"><thead><tr><th>Type</th><th>Description</th><th>Loans</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php if(!$types->num_rows): ?><tr><td colspan="5" class="empty">No loan types.</td></tr><?php else: while($row=$types->fetch_assoc()): ?><tr><td><?php echo e($row['name']); ?></td><td><?php echo e($row['description']); ?></td><td><?php echo (int)$row['loan_count']; ?></td><td><span class="badge"><?php echo $row['is_active']?'Active':'Inactive'; ?></span></td><td><a class="btn btn-secondary btn-small" href="loan_types.php?edit=<?php echo (int)$row['loan_type_id']; ?>">Edit</a> <form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="loan_type_id" value="<?php echo (int)$row['loan_type_id']; ?>"><button class="btn btn-secondary btn-small"><?php echo $row['is_active']?'Disable':'Enable'; ?></button></form></td></tr><?php endwhile; endif; ?></tbody></table></div></section></div></main></div></body></html>
