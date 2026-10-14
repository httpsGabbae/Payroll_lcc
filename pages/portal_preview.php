<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('superadmin', 'hr');
require_once __DIR__ . '/../includes/layout.php';
$id = (int)($_GET['id'] ?? 0);
$stmt = $conn->prepare('SELECT employee_no,first_name,middle_name,last_name,department,position,employment_status,date_hired FROM employees WHERE employee_id=? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$employee = $stmt->get_result()->fetch_assoc();
if (!$employee) { http_response_code(404); exit('Employee not found.'); }
$stmt = $conn->prepare('SELECT attendance_date,time_in,time_out,status FROM attendance WHERE employee_id=? ORDER BY attendance_date DESC LIMIT 12');
$stmt->bind_param('i', $id);
$stmt->execute();
$attendance = $stmt->get_result();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="icon" href="../assets/favicon.png"><title>LCC Payroll System · Read-only Preview</title><link rel="stylesheet" href="../assets/css/app.css?v=20261012-nav1"><link rel="stylesheet" href="../assets/css/apple-system.css"><script src="../assets/js/app.js?v=20261012-nav1" defer></script></head><body><div class="app"><?php sidebar('employees'); ?><main class="main"><?php topbar('Employee Preview'); ?><div class="content"><div class="page-heading"><div><div class="eyebrow">READ-ONLY ADMINISTRATOR PREVIEW</div><h1><?php echo e(trim($employee['first_name'].' '.$employee['middle_name'].' '.$employee['last_name'])); ?></h1><p><?php echo e($employee['employee_no']); ?> · <?php echo e($employee['employment_status']); ?></p></div><a class="btn btn-secondary" href="employees.php">Back to Employees</a></div><section class="card"><div class="card-head"><div><h2>Employee profile</h2><p>Administrator identity remains active. No employee actions are available here.</p></div></div><div class="form-grid" style="padding:20px"><div class="field"><label>Department</label><strong><?php echo e($employee['department'] ?: 'Not assigned'); ?></strong></div><div class="field"><label>Position</label><strong><?php echo e($employee['position'] ?: 'Not assigned'); ?></strong></div><div class="field"><label>Date hired</label><strong><?php echo e($employee['date_hired'] ?: 'Not provided'); ?></strong></div></div></section><section class="card"><div class="card-head"><div><h2>Recent attendance</h2></div></div><div class="table-wrap"><table class="table"><thead><tr><th>Date</th><th>Time in</th><th>Time out</th><th>Status</th></tr></thead><tbody><?php if ($attendance->num_rows === 0): ?><tr><td colspan="4" class="empty">No attendance records.</td></tr><?php else: while ($row=$attendance->fetch_assoc()): ?><tr><td><?php echo e($row['attendance_date']); ?></td><td><?php echo e($row['time_in'] ?: '—'); ?></td><td><?php echo e($row['time_out'] ?: '—'); ?></td><td><span class="badge"><?php echo e($row['status']); ?></span></td></tr><?php endwhile; endif; ?></tbody></table></div></section></div></main></div></body></html>
