<?php
require_once "../config/auth.php";
require_once "../config/db.php";

if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    $dashboard = [
        "admin" => "dashboard.php",
        "student" => "../student/dashboard.php",
        "office" => "../office/dashboard.php"
    ][$_SESSION["role"]] ?? "../index.php";
    header("Location: " . $dashboard);
    exit();
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($username === "" || $password === "") {
        $error = "Please enter your username and password.";
    } else {
        $stmt = $conn->prepare(
            "SELECT user_id, username, password, full_name, email, avatar_path, role, status, department, college_dean, college_dean_course
             FROM users
             WHERE username = ?
             LIMIT 1"
        );
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if ($user["status"] !== "active") {
                $error = "This account is inactive.";
            } elseif (!password_verify($password, $user["password"])) {
                $error = "Invalid username or password.";
            } elseif ($user["role"] !== "admin") {
                $maintenanceStmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'maintenance_mode' LIMIT 1");
                $maintenanceStmt->execute();
                $maintenanceSetting = $maintenanceStmt->get_result()->fetch_assoc();
                $maintenanceStmt->close();
                if (($maintenanceSetting["setting_value"] ?? "0") === "1") {
                    $error = "The system is under maintenance. Only administrators can sign in right now.";
                }
            }

            if ($error === "") {
                session_regenerate_id(true);
                $_SESSION["user_id"] = $user["user_id"];
                $_SESSION["username"] = $user["username"];
                $_SESSION["full_name"] = $user["full_name"];
                $_SESSION["email"] = $user["email"];
                $_SESSION["avatar_path"] = $user["avatar_path"];
                $_SESSION["role"] = $user["role"];
                $_SESSION["college_dean"] = (int)($user["college_dean"] ?? 0);
                $_SESSION["college_dean_course"] = trim((string)($user["college_dean_course"] ?? ""));
                $_SESSION["department"] = trim((string)($user["department"] ?: ($user["college_dean_course"] ?? "")));

                $dashboard = [
                    "admin" => "dashboard.php",
                    "student" => "../student/dashboard.php",
                    "office" => "../office/dashboard.php"
                ][$user["role"]] ?? "../index.php";
                header("Location: " . $dashboard);
                exit();
            }
        } else {
            $error = "Invalid username or password.";
        }

        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Student Clearance System</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
</head>
<body class="auth-page">
    <main class="auth-card">
        <div class="auth-brand">
            <img src="../assets/images/system_logo.jpg" alt="Student Clearance System logo">
        </div>

        <h1>Login</h1>
        <p class="auth-subtitle">Sign in as an administrator, student, or office user.</p>

        <?php if ($error !== ""): ?>
            <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <div class="form-group">
                <label for="username">Username</label>
                <input id="username" name="username" type="text"
                       placeholder="Enter username" required autofocus>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input id="password" name="password" type="password"
                       placeholder="Enter password" required>
            </div>

            <button class="btn btn-primary btn-full" type="submit">Sign in</button>
        </form>
    </main>
    <script>
        document.querySelector(".auth-page").addEventListener("click", (event) => {
            if (event.target === event.currentTarget) {
                window.location.href = "../index.php";
            }
        });
    </script>
</body>
</html>
