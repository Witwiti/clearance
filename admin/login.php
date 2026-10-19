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
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($email === "" || $password === "") {
        $error = "Please enter your email and password.";
    } else {
        $stmt = $conn->prepare(
            "SELECT user_id, username, password, full_name, email, avatar_path, role, status, department, college_dean, college_dean_course
             FROM users
             WHERE email = ?
             LIMIT 1"
        );
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if ($user["status"] !== "active") {
                $error = "This account is inactive.";
            } elseif (!password_verify($password, $user["password"])) {
                $error = "Invalid email or password.";
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

                $lastLoginStmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
                if ($lastLoginStmt) {
                    $lastLoginStmt->bind_param("i", $user["user_id"]);
                    $lastLoginStmt->execute();
                    $lastLoginStmt->close();
                }

                $dashboard = [
                    "admin" => "dashboard.php",
                    "student" => "../student/dashboard.php",
                    "office" => "../office/dashboard.php"
                ][$user["role"]] ?? "../index.php";
                header("Location: " . $dashboard);
                exit();
            }
        } else {
            $error = "Invalid email or password.";
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
    <title>SCMS Login | Student Clearance System</title>
    <link rel="stylesheet" href="../assets/css/auth.css?v=20261029">
</head>
<body class="auth-page scms-auth">
    <button class="theme-toggle theme-toggle-fixed" id="themeToggle" type="button" aria-label="Switch to dark mode" aria-pressed="false"><i data-lucide="moon"></i></button>
    <main class="scms-wrap">
        <div class="scms-brand">
            <img src="../assets/images/system_logo.jpg" alt="Student Clearance System logo">
        </div>
        <h1 class="scms-title">SCMS LOGIN</h1>
        <p class="scms-tagline">Clearing the path to your academic freedom.</p>

        <section class="scms-card">
            <h2>Sign in</h2>

            <?php if ($error !== ""): ?>
                <div class="alert alert-danger"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <div class="scms-field">
                    <label for="email">Email</label>
                    <input id="email" name="email" type="email"
                           placeholder="example@ckcm.edu.ph" required autofocus
                           value="<?= e($_POST['email'] ?? '') ?>">
                    <small>Use your institutional email.</small>
                </div>

                <div class="scms-field">
                    <label for="password">Password</label>
                    <div class="password-field">
                        <input id="password" name="password" type="password"
                               placeholder="Enter password" required>
                        <button type="button" class="password-toggle" aria-label="Show password"><i data-lucide="eye"></i></button>
                    </div>
                    <small>The default password is scms1234.</small>
                </div>

                <p class="scms-forgot">Forgot password? See the administrator.</p>

                <button class="scms-btn" type="submit">Login Now</button>
            </form>
        </section>
        <p class="scms-foot">scms.v1.0.2<br>Developed by <span class="version-team">BSCS STUDENT</span></p>
    </main>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        lucide.createIcons();
        (function () {
            var btn = document.getElementById("themeToggle");
            var paint = function (dark) {
                document.body.classList.toggle("dark", dark);
                if (!btn) return;
                btn.innerHTML = '<i data-lucide="' + (dark ? "sun" : "moon") + '"></i>';
                btn.setAttribute("aria-label", dark ? "Switch to light mode" : "Switch to dark mode");
                btn.setAttribute("aria-pressed", dark ? "true" : "false");
                if (window.lucide) lucide.createIcons();
            };
            var dark = false;
            try { dark = localStorage.getItem("clearance-theme") === "dark"; } catch (e) {}
            paint(dark);
            if (btn) btn.addEventListener("click", function () {
                dark = !document.body.classList.contains("dark");
                try { localStorage.setItem("clearance-theme", dark ? "dark" : "light"); } catch (e) {}
                paint(dark);
            });
        })();
        document.querySelector(".password-toggle")?.addEventListener("click", (e) => {
            const btn = e.currentTarget;
            const input = btn.closest(".password-field")?.querySelector("input");
            if (!input) return;
            const show = input.type === "password";
            input.type = show ? "text" : "password";
            btn.setAttribute("aria-label", show ? "Hide password" : "Show password");
            btn.innerHTML = `<i data-lucide="${show ? "eye-off" : "eye"}"></i>`;
            lucide.createIcons();
            input.focus();
        });
        document.querySelector(".auth-page").addEventListener("click", (event) => {
            if (event.target === event.currentTarget) {
                window.location.href = "../index.php";
            }
        });
    </script>
</body>
</html>
