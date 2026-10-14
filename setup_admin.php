<?php
// One-time local CLI bootstrap; no HTTP access and no embedded credentials.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/config/database.php';
$count = (int)$conn->query('SELECT COUNT(*) AS total FROM admins')->fetch_assoc()['total'];
if ($count > 0) {
    fwrite(STDERR, "An administrator already exists. Use Accounts in the application.\n");
    exit(1);
}
fwrite(STDOUT, "LCC administrator username: ");
$username = trim((string)fgets(STDIN));
fwrite(STDOUT, "Full name: ");
$fullName = trim((string)fgets(STDIN));
fwrite(STDOUT, "Password (input visible locally): ");
$password = rtrim((string)fgets(STDIN), "\r\n");
if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username) || $fullName === '' || strlen($fullName) > 100 || strlen($password) < 12) {
    fwrite(STDERR, "Invalid input. Username 3–50 characters; full name required; password at least 12 characters.\n");
    exit(1);
}
$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $conn->prepare("INSERT INTO admins(username,password_hash,full_name,role,is_active) VALUES(?,?,?,'superadmin',1)");
$stmt->bind_param('sss', $username, $hash, $fullName);
if (!$stmt->execute()) {
    fwrite(STDERR, "Could not create administrator.\n");
    exit(1);
}
fwrite(STDOUT, "Independent LCC administrator created.\n");
