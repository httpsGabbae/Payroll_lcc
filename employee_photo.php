<?php
require_once __DIR__ . '/config/database.php';

$employeeId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$employeeId || $employeeId < 1) {
    http_response_code(404);
    exit;
}

$allowedAsAdmin = false;
$adminId = (int)($_SESSION['admin_id'] ?? 0);
if ($adminId > 0) {
    $adminStatement = $conn->prepare('SELECT role, is_active FROM admins WHERE admin_id=? LIMIT 1');
    $adminStatement->bind_param('i', $adminId);
    $adminStatement->execute();
    $admin = $adminStatement->get_result()->fetch_assoc();
    $allowedAsAdmin = $admin && (int)$admin['is_active'] === 1
        && in_array($admin['role'], ['superadmin', 'hr'], true);
}

if (!$allowedAsAdmin && (int)($_SESSION['employee_id'] ?? 0) !== $employeeId) {
    http_response_code(403);
    exit;
}

$statement = $conn->prepare('SELECT photo_path, employment_status FROM employees WHERE employee_id=? LIMIT 1');
$statement->bind_param('i', $employeeId);
$statement->execute();
$employee = $statement->get_result()->fetch_assoc();
if (!$employee || (!$allowedAsAdmin && !is_active_employment_status($employee['employment_status'] ?? ''))) {
    http_response_code(404);
    exit;
}

$path = str_replace('\\', '/', trim((string)($employee['photo_path'] ?? '')));
if (!preg_match('~^uploads/employee_photos/[A-Za-z0-9_.-]+\.(?:jpe?g|png)$~i', $path)) {
    http_response_code(404);
    exit;
}

$file = __DIR__ . '/' . $path;
if (!is_file($file)) {
    http_response_code(404);
    exit;
}

$image = @getimagesize($file);
$mime = $image['mime'] ?? '';
if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($file);
