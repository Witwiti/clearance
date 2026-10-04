<?php
session_start();
require_once "config/db.php";

$maintenanceMode = false;
$tableResult = $conn->query("SHOW TABLES LIKE 'system_settings'");
if ($tableResult && $tableResult->num_rows > 0) {
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'maintenance_mode' LIMIT 1");
    $stmt->execute();
    $setting = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $maintenanceMode = ($setting["setting_value"] ?? "0") === "1";
}

if ($maintenanceMode && ($_SESSION["role"] ?? "") !== "admin") {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>System Maintenance | Student Clearance System</title>
        <link rel="stylesheet" href="assets/css/auth.css">
    </head>
    <body class="maintenance-page">
        <main class="maintenance-card">
            <img src="assets/images/system_logo.jpg" alt="Student Clearance System logo">
            <span class="maintenance-kicker">Student Clearance System</span>
            <h1>System under maintenance</h1>
            <p>The clearance portal is temporarily unavailable while we make improvements. Please check back shortly.</p>
            <a class="btn btn-primary maintenance-admin-login" href="admin/login.php">Administrator login</a>
        </main>
    </body>
    </html>
    <?php
    exit();
}

if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    if ($_SESSION['role'] === 'admin') {
        header("Location: admin/dashboard.php");
        exit();
    }

    if ($_SESSION['role'] === 'student') {
        header("Location: student/dashboard.php");
        exit();
    }

    if ($_SESSION['role'] === 'office') {
        header("Location: office/dashboard.php");
        exit();
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Clearance Management System</title>
    <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body class="landing-page">
    <main class="landing-shell">
        <nav class="landing-nav">
            <a class="landing-brand" href="index.php">
                <img src="assets/images/system_logo.jpg" alt="Student Clearance System logo">
                <span>Student Clearance<br><strong>Management System</strong></span>
            </a>
            <div class="landing-nav-actions">
                <span class="landing-status"><span></span> Portal online</span>
                <a class="btn btn-primary" href="admin/login.php">Sign in</a>
            </div>
        </nav>

        <section class="landing-hero">
            <div class="landing-copy">
                <span class="landing-kicker">A clearer path to completion</span>
                <h1>Complete your student clearance with confidence.</h1>
                <p>One organized portal for students, offices, and administrators to track every clearance step from request to completion.</p>
            </div>
            <div class="landing-seal-wrap">
                <div class="landing-seal-ring"></div>
                <img class="landing-seal" src="assets/images/system_logo.jpg" alt="Student Clearance System logo">
                <span class="landing-seal-label">W.B. / EST. 2026</span>
            </div>
        </section>

        <section class="landing-proof" aria-label="Portal overview">
            <div><strong>01</strong><span>One connected clearance portal</span></div>
            <div><strong>02</strong><span>Office-by-office progress visibility</span></div>
            <div><strong>03</strong><span>Built for a smoother finish</span></div>
        </section>

        <section class="landing-features" aria-label="Portal features">
            <article><span class="feature-number">01</span><h2>Track progress</h2><p>See the status of your clearance request across every office.</p></article>
            <article><span class="feature-number">02</span><h2>Stay organized</h2><p>Keep requests, remarks, and updates in one dependable place.</p></article>
            <article><span class="feature-number">03</span><h2>Finish smoothly</h2><p>Move from pending to cleared with fewer follow-ups and delays.</p></article>
        </section>
    </main>
</body>
</html>
?>
