<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---------------------------------------------------------------------------
// Database credentials, resolved in this order:
//   1. config/db.local.php  - git-ignored file created on the server (recommended)
//   2. Environment variables DB_HOST / DB_USER / DB_PASSWORD / DB_NAME
//   3. Local XAMPP defaults (below)
// Copy config/db.local.example.php to config/db.local.php on the host and fill
// in the values from hPanel > Databases > Management.
// ---------------------------------------------------------------------------
$localConfigPath = __DIR__ . "/db.local.php";
$localConfig = [];
if (is_file($localConfigPath)) {
    $loadedConfig = require $localConfigPath;
    if (is_array($loadedConfig)) $localConfig = $loadedConfig;
}

// Environment variables: real process env (hPanel env vars / SetEnv in .htaccess),
// $_ENV or $_SERVER - whichever the host provides.
$readEnv = function (string $key): string {
    $value = getenv($key);
    if ($value === false || $value === "") $value = $_ENV[$key] ?? ($_SERVER[$key] ?? "");
    return trim((string)$value);
};

$host = (string)($localConfig["host"] ?? ($readEnv("DB_HOST") ?: "localhost"));
$username = (string)($localConfig["username"] ?? ($readEnv("DB_USER") ?: "root"));
$password = (string)($localConfig["password"] ?? $readEnv("DB_PASSWORD"));
$database = (string)($localConfig["name"] ?? ($readEnv("DB_NAME") ?: "school_clearance_db"));

try {
    $conn = new mysqli($host, $username, $password, $database);
} catch (Throwable $connectionError) {
    error_log("Clearance DB connection failed: " . $connectionError->getMessage());
    http_response_code(500);
    die("<h3>Database connection failed</h3><p>Check <code>config/db.local.php</code> (or the DB_HOST / DB_USER / DB_PASSWORD / DB_NAME environment variables).</p>");
}

if ($conn->connect_error) {
    error_log("Clearance DB connection failed: " . $conn->connect_error);
    http_response_code(500);
    die("<h3>Database connection failed</h3><p>Check <code>config/db.local.php</code> (or the DB_HOST / DB_USER / DB_PASSWORD / DB_NAME environment variables).</p>");
}

$conn->set_charset("utf8mb4");

$conn->query(
    "CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(80) PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )"
);

$maintenanceResult = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'maintenance_mode' LIMIT 1");
if ($maintenanceResult && ($maintenanceResult->fetch_assoc()["setting_value"] ?? "0") === "1" && isset($_SESSION["user_id"]) && ($_SESSION["role"] ?? "") !== "admin") {
    $scriptDirectory = trim(dirname($_SERVER["SCRIPT_NAME"] ?? ""), "/");
    $maintenanceLocation = $scriptDirectory === "" ? "index.php" : "../index.php";
    header("Location: " . $maintenanceLocation);
    exit();
}

$avatarColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'avatar_path'");
if ($avatarColumn && $avatarColumn->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN avatar_path VARCHAR(255) DEFAULT NULL AFTER email");
}

$departmentColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'department'");
if ($departmentColumn && $departmentColumn->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN department VARCHAR(120) DEFAULT NULL AFTER avatar_path");
}

$collegeDeanColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'college_dean'");
if ($collegeDeanColumn && $collegeDeanColumn->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN college_dean TINYINT(1) NOT NULL DEFAULT 0 AFTER department");
}

$collegeDeanCourseColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'college_dean_course'");
if ($collegeDeanCourseColumn && $collegeDeanCourseColumn->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN college_dean_course VARCHAR(120) DEFAULT NULL AFTER college_dean");
}

$assignatoryNameColumn = $conn->query("SHOW COLUMNS FROM office_assignatories LIKE 'assignatory_name'");
if ($assignatoryNameColumn && $assignatoryNameColumn->num_rows === 0) {
    $conn->query("ALTER TABLE office_assignatories ADD COLUMN assignatory_name VARCHAR(120) DEFAULT NULL AFTER user_id");
}

$conn->query("ALTER TABLE office_assignatories MODIFY user_id INT UNSIGNED NULL");

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: no-referrer-when-downgrade');
}

$assignatoryOwnerColumn = $conn->query("SHOW COLUMNS FROM office_assignatories LIKE 'assigned_by_user_id'");
if ($assignatoryOwnerColumn && $assignatoryOwnerColumn->num_rows === 0) {
    $conn->query("ALTER TABLE office_assignatories ADD COLUMN assigned_by_user_id INT UNSIGNED DEFAULT NULL AFTER assignatory_name");
}

// Department scoping for offices: deans only assign department offices for their course.
// scope = general (all clearances) or department (dean-managed, e.g. Dept. Treasurer).
// course_name = NULL/empty means shared across all departments; otherwise course-specific.
$officeScopeColumn = $conn->query("SHOW COLUMNS FROM offices LIKE 'scope'");
if ($officeScopeColumn && $officeScopeColumn->num_rows === 0) {
    $conn->query("ALTER TABLE offices ADD COLUMN scope ENUM('general','department') NOT NULL DEFAULT 'general' AFTER description");
}
$officeCourseColumn = $conn->query("SHOW COLUMNS FROM offices LIKE 'course_name'");
if ($officeCourseColumn && $officeCourseColumn->num_rows === 0) {
    $conn->query("ALTER TABLE offices ADD COLUMN course_name VARCHAR(120) DEFAULT NULL AFTER scope");
}
// One-time backfill: tag Dept./Department offices as department so existing installs work.
// Only runs while no department office exists, so admin changes are never overwritten.
$deptSeedCheck = $conn->query("SELECT COUNT(*) AS c FROM offices WHERE scope = 'department'");
if ($deptSeedCheck) {
    $deptCount = (int)($deptSeedCheck->fetch_assoc()["c"] ?? 0);
    $deptSeedCheck->close();
    if ($deptCount === 0) {
        $conn->query("UPDATE offices SET scope = 'department' WHERE office_name LIKE '%Dept.%' OR office_name LIKE '%Department%'");
    }
}

// Extended schema for manuscript features: notifications, requirement types,
// school terms, and clearance archive. Self-heals on every request.
require_once __DIR__ . '/notifications.php';
if (function_exists('ensure_extended_schema')) {
    ensure_extended_schema($conn);
}

// Student profile extensions: contact details editable by the student.
// Name, student number, course, and year level stay managed by the administrator.
$studentProfileColumns = [
    'suffix' => "ALTER TABLE students ADD COLUMN suffix VARCHAR(20) DEFAULT NULL AFTER last_name",
    'gender' => "ALTER TABLE students ADD COLUMN gender VARCHAR(20) DEFAULT NULL AFTER suffix",
    'birth_date' => "ALTER TABLE students ADD COLUMN birth_date DATE DEFAULT NULL AFTER gender",
    'contact_number' => "ALTER TABLE students ADD COLUMN contact_number VARCHAR(30) DEFAULT NULL AFTER birth_date",
    'address' => "ALTER TABLE students ADD COLUMN address VARCHAR(255) DEFAULT NULL AFTER contact_number",
];
foreach ($studentProfileColumns as $studentProfileColumn => $studentProfileAlter) {
    $studentProfileCheck = $conn->query("SHOW COLUMNS FROM students LIKE '" . $studentProfileColumn . "'");
    if ($studentProfileCheck && $studentProfileCheck->num_rows === 0) {
        $conn->query($studentProfileAlter);
    }
    if ($studentProfileCheck) $studentProfileCheck->close();
}

// Account timestamps shown on the student profile (role, status, last login, updated at).
$userLastLoginColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'last_login'");
if ($userLastLoginColumn && $userLastLoginColumn->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN last_login TIMESTAMP NULL DEFAULT NULL AFTER status");
}
if ($userLastLoginColumn) $userLastLoginColumn->close();
$userUpdatedAtColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'updated_at'");
if ($userUpdatedAtColumn && $userUpdatedAtColumn->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL AFTER last_login");
}
if ($userUpdatedAtColumn) $userUpdatedAtColumn->close();
?>
