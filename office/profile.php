<?php
$pageTitle = "My Profile";
$activePage = "profile";
require_once "../config/db.php";
require_once "../config/auth.php";
require_role("office");

$message = "";
$error = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $fullName = trim($_POST["full_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";
    $userId = (int)$_SESSION["user_id"];
    $avatarPath = null;
    if (isset($_FILES["avatar"]) && $_FILES["avatar"]["error"] !== UPLOAD_ERR_NO_FILE) {
        $allowedTypes = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp"];
        $fileType = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES["avatar"]["tmp_name"]);
        if ($_FILES["avatar"]["error"] !== UPLOAD_ERR_OK || !isset($allowedTypes[$fileType]) || $_FILES["avatar"]["size"] > 2 * 1024 * 1024) $error = "Profile picture must be a JPG, PNG, or WebP image up to 2 MB.";
        else {
            $uploadDirectory = dirname(__DIR__) . "/assets/uploads/profiles";
            if (!is_dir($uploadDirectory)) mkdir($uploadDirectory, 0755, true);
            $avatarFile = "user_" . bin2hex(random_bytes(12)) . "." . $allowedTypes[$fileType];
            if (move_uploaded_file($_FILES["avatar"]["tmp_name"], $uploadDirectory . "/" . $avatarFile)) $avatarPath = "assets/uploads/profiles/" . $avatarFile;
            else $error = "Unable to save the profile picture.";
        }
    }
    if ($error === "" && ($fullName === "" || $email === "")) $error = "Full name and email are required.";
    if ($error === "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) $error = "Please enter a valid email address.";
    if ($error === "") {
        $emailCheck = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id <> ? LIMIT 1");
        $emailCheck->bind_param("si", $email, $userId);
        $emailCheck->execute();
        $emailTaken = $emailCheck->get_result()->num_rows > 0;
        $emailCheck->close();
        if ($emailTaken) $error = "Email is already in use by another account.";
    }
    if ($error === "") {
        if ($avatarPath !== null && $password !== "") { $hash = password_hash($password, PASSWORD_DEFAULT); $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, avatar_path = ?, password = ? WHERE user_id = ?"); $stmt->bind_param("ssssi", $fullName, $email, $avatarPath, $hash, $userId); }
        elseif ($avatarPath !== null) { $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, avatar_path = ? WHERE user_id = ?"); $stmt->bind_param("sssi", $fullName, $email, $avatarPath, $userId); }
        elseif ($password !== "") { $hash = password_hash($password, PASSWORD_DEFAULT); $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, password = ? WHERE user_id = ?"); $stmt->bind_param("sssi", $fullName, $email, $hash, $userId); }
        else { $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ? WHERE user_id = ?"); $stmt->bind_param("ssi", $fullName, $email, $userId); }
        if ($stmt->execute()) { $message = "Profile updated."; $_SESSION["full_name"] = $fullName; $_SESSION["email"] = $email; if ($avatarPath !== null) $_SESSION["avatar_path"] = $avatarPath; }
        else $error = "Unable to update your profile.";
        $stmt->close();
    }
}
$avatarStmt = $conn->prepare("SELECT avatar_path FROM users WHERE user_id = ?");
$avatarStmt->bind_param("i", $_SESSION["user_id"]);
$avatarStmt->execute();
$avatar = $avatarStmt->get_result()->fetch_assoc();
$avatarStmt->close();
$avatarUrl = !empty($avatar["avatar_path"]) ? "../" . $avatar["avatar_path"] : "../assets/images/system_logo.jpg";
$currentProfileUserId = (int)($_SESSION["user_id"] ?? 0);
$assignmentStmt = $conn->prepare("SELECT o.office_name, oa.position FROM office_assignatories oa INNER JOIN offices o ON o.office_id = oa.office_id WHERE (oa.user_id = ? OR oa.assigned_by_user_id = ?) AND oa.status = 'active' LIMIT 1");
$assignmentStmt->bind_param("ii", $currentProfileUserId, $currentProfileUserId);
$assignmentStmt->execute();
$assignment = $assignmentStmt->get_result()->fetch_assoc();
$assignmentStmt->close();
require "_header.php";
?>
<div class="page-heading"><div><h1>My Profile</h1><p>Manage your office account and profile picture.</p></div></div>
<?php if ($message !== ""): ?><div class="notice notice-success"><?= e($message) ?></div><?php endif; ?><?php if ($error !== ""): ?><div class="notice notice-error"><?= e($error) ?></div><?php endif; ?>
<div class="profile-card form-card"><img src="<?= e($avatarUrl) ?>" class="profile-large" alt="Office profile picture"><div><h2><?= e($_SESSION["full_name"]) ?></h2><p><?= e($_SESSION["email"]) ?></p><span class="role-badge">Office · <?= e($assignment["office_name"] ?? "Unassigned") ?></span></div></div>
<div class="form-card announcement-form profile-edit-form"><h2>Edit profile</h2><form method="post" enctype="multipart/form-data"><label>Full name<input name="full_name" required value="<?= e($_SESSION["full_name"]) ?>"></label><label>Email<input type="email" name="email" required value="<?= e($_SESSION["email"]) ?>"></label><label>New password<input type="password" name="password" placeholder="Leave blank to keep current password"></label><label>Profile picture<input type="file" name="avatar" accept="image/jpeg,image/png,image/webp"><small class="field-help">JPG, PNG, or WebP up to 2 MB.</small></label><button class="btn btn-primary" type="submit"><i data-lucide="save"></i>Save profile</button></form></div>
<?php require "_footer.php"; ?>
