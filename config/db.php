<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$host = "localhost";
$username = "root";
$password = "";
$database = "school_clearance_db";

$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
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

$assignatoryOwnerColumn = $conn->query("SHOW COLUMNS FROM office_assignatories LIKE 'assigned_by_user_id'");
if ($assignatoryOwnerColumn && $assignatoryOwnerColumn->num_rows === 0) {
    $conn->query("ALTER TABLE office_assignatories ADD COLUMN assigned_by_user_id INT UNSIGNED DEFAULT NULL AFTER assignatory_name");
}
?>
