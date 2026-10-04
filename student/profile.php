<?php
$pageTitle = "My Profile";
$activePage = "profile";
require_once "../config/db.php";
require_once "../config/auth.php";
require_role("student");

$message = "";
$error = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $fullName = trim($_POST["full_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";
    if ($fullName === "" || $email === "") $error = "Full name and email are required.";
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error = "Please enter a valid email address.";
    else {
        $emailCheck = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id <> ? LIMIT 1");
        $emailCheck->bind_param("si", $email, $_SESSION["user_id"]);
        $emailCheck->execute();
        $emailTaken = $emailCheck->get_result()->num_rows > 0;
        $emailCheck->close();
        if ($emailTaken) $error = "Email is already in use by another account.";
    }
    if ($error === "") {
        if ($password !== "") {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, password = ? WHERE user_id = ?");
            $stmt->bind_param("sssi", $fullName, $email, $hash, $_SESSION["user_id"]);
        } else {
            $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ? WHERE user_id = ?");
            $stmt->bind_param("ssi", $fullName, $email, $_SESSION["user_id"]);
        }
        if ($stmt->execute()) {
            $_SESSION["full_name"] = $fullName;
            $_SESSION["email"] = $email;
            $message = "Profile updated.";
        } else $error = "Unable to update your profile.";
        $stmt->close();
    }
}

$studentStmt = $conn->prepare("SELECT student_number, course, year_level, status FROM students WHERE user_id = ? LIMIT 1");
$studentStmt->bind_param("i", $_SESSION["user_id"]);
$studentStmt->execute();
$student = $studentStmt->get_result()->fetch_assoc();
$studentStmt->close();
require "_header.php";
?>
<div class="page-heading"><div><h1>My Profile</h1><p>Manage your account information and student details.</p></div></div>
<?php if ($message !== ""): ?><div class="notice notice-success"><?= e($message) ?></div><?php endif; ?><?php if ($error !== ""): ?><div class="notice notice-error"><?= e($error) ?></div><?php endif; ?>
<div class="profile-card form-card"><img src="../assets/images/system_logo.jpg" class="profile-large" alt="Student Clearance System logo"><div><h2><?= e($_SESSION["full_name"]) ?></h2><p><?= e($_SESSION["email"]) ?></p><span class="role-badge">Student</span></div></div>
<div class="form-card announcement-form profile-edit-form"><h2>Edit profile</h2><form method="post"><label>Full name<input name="full_name" required value="<?= e($_SESSION["full_name"]) ?>"></label><label>Email<input type="email" name="email" required value="<?= e($_SESSION["email"]) ?>"></label><label>New password<input type="password" name="password" placeholder="Leave blank to keep current password"></label><button class="btn btn-primary" type="submit"><i data-lucide="save"></i>Save profile</button></form></div>
<div class="form-card student-details"><h2>Student details</h2><div class="detail-grid"><div><span>Student number</span><strong><?= e($student["student_number"] ?? "Not set") ?></strong></div><div><span>Course</span><strong><?= e($student["course"] ?? "Not set") ?></strong></div><div><span>Year level</span><strong><?= e($student["year_level"] ?? "Not set") ?></strong></div><div><span>Status</span><strong><?= e(ucfirst($student["status"] ?? "Unknown")) ?></strong></div></div></div>
<?php require "_footer.php"; ?>