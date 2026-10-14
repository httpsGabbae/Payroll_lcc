<?php
date_default_timezone_set('Asia/Manila');

if (session_status() === PHP_SESSION_NONE) {
    // Isolate this edition from the original Paywise session cookie and files.
    session_name('LCC_PAYROLL_SESSID');
    $sessionDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lcc-payroll-sessions';
    if (!is_dir($sessionDirectory) && !mkdir($sessionDirectory, 0700, true) && !is_dir($sessionDirectory)) {
        throw new RuntimeException('Cannot create the LCC session directory.');
    }
    session_save_path($sessionDirectory);
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/paywise-lcc/',
            'secure' => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    session_start();
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('money')) {
    function money($value): string
    {
        return '₱ ' . number_format((float)$value, 2);
    }
}

if (!function_exists('active_employment_statuses')) {
    function active_employment_statuses(): array
    {
        return ['Regular', 'Probationary', 'Contractual', 'Part-Time'];
    }
}

if (!function_exists('is_active_employment_status')) {
    function is_active_employment_status($status): bool
    {
        return in_array((string)$status, active_employment_statuses(), true);
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('verify_csrf')) {
    function verify_csrf(?string $token): bool
    {
        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            return false;
        }
        if (!is_string($token) || $token === '') {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('require_csrf')) {
    function require_csrf(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf($_POST['csrf_token'] ?? null)) {
            http_response_code(419);
            exit('Invalid session token. Please go back and try again.');
        }
    }
}

if (!defined('MYSQLI_ASSOC')) {
    define('MYSQLI_ASSOC', 1);
}

/* Cross-driver DB layer: MySQL (XAMPP default) or Postgres/Supabase.
   Set DB_DRIVER=pgsql + DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS to use
   Supabase. All app code keeps the mysqli-style API ($conn->prepare /
   bind_param / execute / get_result / query / real_escape_string /
   insert_id), backed by mysqli for mysql and by PDO for pgsql. */
if (!class_exists('DbResult')) {
    class DbResult
    {
        public int $num_rows = 0;
        private array $rows;
        private int $pos = 0;

        public function __construct(array $rows)
        {
            $this->rows = array_values($rows);
            $this->num_rows = count($this->rows);
        }

        public function fetch_assoc(): ?array
        {
            if ($this->pos >= count($this->rows)) return null;
            return $this->rows[$this->pos++];
        }

        public function fetch_all($mode = null): array
        {
            return $this->rows;
        }

        public function data_seek(int $offset): bool
        {
            if ($offset < 0 || $offset > count($this->rows)) return false;
            $this->pos = $offset;
            return true;
        }

        public function free(): void
        {
        }
    }
}

if (!class_exists('DbStmt')) {
    class DbStmt
    {
        private PDO $pdo;
        private string $sql;
        private array $params = [];
        private ?DbResult $result = null;
        public int $affected_rows = 0;
        public string $error = '';

        public function __construct(PDO $pdo, string $sql)
        {
            $this->pdo = $pdo;
            $this->sql = $sql;
        }

        // $types kept for mysqli call-compatibility; values are bound positionally.
        public function bind_param(string $types, mixed ...$vars): bool
        {
            $this->params = array_values($vars);
            return true;
        }

        public function execute(): bool
        {
            try {
                $stmt = $this->pdo->prepare($this->sql);
                $ok = $stmt->execute($this->params);
                if (!$ok) {
                    $this->error = implode(' ', $stmt->errorInfo());
                    return false;
                }
                if ($stmt->columnCount() > 0) {
                    $this->result = new DbResult($stmt->fetchAll(PDO::FETCH_ASSOC));
                } else {
                    $this->result = new DbResult([]);
                    $this->affected_rows = $stmt->rowCount();
                }
                return true;
            } catch (Throwable $e) {
                $this->error = $e->getMessage();
                return false;
            }
        }

        public function get_result(): DbResult
        {
            return $this->result ?? new DbResult([]);
        }

        public function close(): void
        {
        }
    }
}

if (!class_exists('DbConn')) {
    class DbConn
    {
        private PDO $pdo;
        public int $connect_errno = 0;
        public string $connect_error = '';
        public string $error = '';

        public function __construct(PDO $pdo)
        {
            $this->pdo = $pdo;
        }

        public function prepare(string $sql): DbStmt|false
        {
            try {
                $this->pdo->prepare($sql);
            } catch (Throwable $e) {
                $this->error = $e->getMessage();
                return false;
            }
            return new DbStmt($this->pdo, $sql);
        }

        public function query(string $sql): DbResult|false
        {
            // Translate the MySQL timezone bootstrap to Postgres.
            if (preg_match('/^\s*SET\s+time_zone\s*=/i', $sql)) {
                try {
                    $this->pdo->exec("SET TIME ZONE 'Asia/Manila'");
                } catch (Throwable $e) {
                    $this->error = $e->getMessage();
                    return false;
                }
                return new DbResult([]);
            }
            try {
                $stmt = $this->pdo->query($sql);
            } catch (Throwable $e) {
                $this->error = $e->getMessage();
                return false;
            }
            if ($stmt->columnCount() > 0) {
                return new DbResult($stmt->fetchAll(PDO::FETCH_ASSOC));
            }
            return new DbResult([]);
        }

        public function real_escape_string(string $s): string
        {
            $q = $this->pdo->quote($s);
            if ($q === false || strlen($q) < 2) return addslashes($s);
            return substr($q, 1, -1);
        }

        public function set_charset(string $charset): bool
        {
            return true;
        }

        public function __get(string $name): mixed
        {
            if ($name === 'insert_id') {
                try {
                    $v = $this->pdo->query('SELECT lastval()')->fetchColumn();
                    return $v === false ? 0 : (int)$v;
                } catch (Throwable) {
                    return 0;
                }
            }
            return null;
        }
    }
}

$dbDriver = 'mysql'; // The school edition is intentionally MySQL/MariaDB-only.
if ($dbDriver === 'pgsql' || $dbDriver === 'postgres' || $dbDriver === 'supabase') {
    $dbDriver = 'pgsql';
    $dbHost = getenv('DB_HOST') ?: getenv('SUPABASE_DB_HOST') ?: '';
    $dbPort = getenv('DB_PORT') ?: getenv('SUPABASE_DB_PORT') ?: '5432';
    $dbName = getenv('DB_NAME') ?: getenv('SUPABASE_DB_NAME') ?: 'postgres';
    $dbUser = getenv('DB_USER') ?: getenv('SUPABASE_DB_USER') ?: 'postgres';
    $dbPass = getenv('DB_PASS') ?: getenv('SUPABASE_DB_PASS') ?: '';
    try {
        $pdo = new PDO(
            "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName}",
            $dbUser,
            $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $pdo->exec("SET TIME ZONE 'Asia/Manila'");
    } catch (Throwable $e) {
        die('Database connection failed: ' . $e->getMessage());
    }
    $conn = new DbConn($pdo);
} else {
    $dbDriver = 'mysql';
    $dbHost = getenv('LCC_DB_HOST') ?: 'localhost';
    $dbUser = getenv('LCC_DB_USER') ?: 'root';
    $dbPass = getenv('LCC_DB_PASS') ?: '';
    $dbName = getenv('LCC_DB_NAME') ?: 'paywise_lcc_edition';
    if (in_array(strtolower($dbName), ['paywise_payroll','lcc_payroll'], true)) {
        throw new RuntimeException('LCC cannot connect to an existing payroll database.');
    }

    $conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($conn->connect_errno) {
        die('Database connection failed: ' . $conn->connect_error);
    }
    $conn->set_charset('utf8mb4');
    $marker = $conn->query("SELECT setting_value FROM app_settings WHERE setting_key='edition_id' LIMIT 1");
    if (!$marker || ($marker->fetch_assoc()['setting_value'] ?? '') !== 'paywise-lcc-2026') {
        throw new RuntimeException('Database is not initialized for the independent Paywise LCC edition.');
    }
    // Keep database dates/times aligned with the application's Philippines timezone.
    @$conn->query("SET time_zone = '+08:00'");
}

if (!function_exists('dept_color_key')) {
    /* Department identity color key shared by every surface (directory,
       report bars, badges). Codes win; names are the fallback so free-text
       department values still resolve. Unknown => 'default' (brand teal).
       LCC codes: CCTE, CON, CITM, CCJE, CBA, CELA.
       Corporate codes (legacy): OPS, FIN, HR, IT, SALES, SUPPORT. */
    function dept_color_key(?string $code, ?string $name): string
    {
        $codeKey = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)$code));
        $byCode = [
            'CCTE' => 'ops', 'CON' => 'support', 'CITM' => 'sales',
            'CCJE' => 'hr', 'CBA' => 'fin', 'CELA' => 'it',
            'OPS' => 'ops', 'FIN' => 'fin', 'HR' => 'hr',
            'IT' => 'it', 'SALES' => 'sales', 'SUPPORT' => 'support',
        ];
        if (isset($byCode[$codeKey])) return $byCode[$codeKey];
        $n = strtoupper((string)$name);
        if (strpos($n, 'COMPUTING') !== false || strpos($n, 'ENGINEERING') !== false) return 'ops';
        if (strpos($n, 'NURSING') !== false) return 'support';
        if (strpos($n, 'TOURISM') !== false || strpos($n, 'HOSPITALITY') !== false || strpos($n, 'INTERNAL') !== false) return 'sales';
        if (strpos($n, 'CRIMINAL') !== false || strpos($n, 'JUSTICE') !== false) return 'hr';
        if (strpos($n, 'BUSINESS') !== false || strpos($n, 'ACCOUNTANCY') !== false) return 'fin';
        if (strpos($n, 'EDUCATION') !== false || strpos($n, 'LIBERAL ARTS') !== false) return 'it';
        if (strpos($n, 'OPERATIONS') !== false) return 'ops';
        if (strpos($n, 'FINANCE') !== false || strpos($n, 'ACCOUNTING') !== false) return 'fin';
        if (strpos($n, 'HUMAN RESOURCES') !== false || $n === 'HR') return 'hr';
        if (strpos($n, 'INFORMATION TECHNOLOGY') !== false) return 'it';
        if (strpos($n, 'SALES') !== false || strpos($n, 'MARKETING') !== false) return 'sales';
        if (strpos($n, 'SUPPORT') !== false || strpos($n, 'CUSTOMER') !== false) return 'support';
        return 'default';
    }
}
