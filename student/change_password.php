<?php
$pageTitle = "Change password";
$activePage = "change_password";
require_once "../config/db.php";
require_once "../config/auth.php";
require_role("student");

$userId = (int)$_SESSION["user_id"];
$message = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $current = $_POST["current_password"] ?? "";
    $new = $_POST["new_password"] ?? "";
    $confirm = $_POST["confirm_password"] ?? "";
    if ($current === "" || $new === "" || $confirm === "") {
        $error = "Please fill in all password fields.";
    } elseif (strlen($new) < 8) {
        $error = "The new password must be at least 8 characters long.";
    } elseif ($new !== $confirm) {
        $error = "The new passwords do not match.";
    } else {
        $stmt = $conn->prepare("SELECT password FROM users WHERE user_id = ? LIMIT 1");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || !password_verify($current, $row["password"])) {
            $error = "Your current password is incorrect.";
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE user_id = ?");
            $stmt->bind_param("si", $hash, $userId);
            if ($stmt->execute()) {
                $message = "Password updated successfully.";
            } else {
                $error = "Unable to update your password.";
            }
            $stmt->close();
        }
    }
}

require "_header.php";
?>
<div class="page-heading"><div><h1>Change password</h1><p>Update the password used to sign in to Student Clearance System.</p></div></div>
<?php if ($message !== ""): ?><div class="notice notice-success"><?= e($message) ?></div><?php endif; ?><?php if ($error !== ""): ?><div class="notice notice-error"><?= e($error) ?></div><?php endif; ?>
<div class="form-card announcement-form profile-card-block password-card">
    <h2>Change password</h2>
    <p>Confirm your current password, then choose a new one.</p>
    <form method="post" data-delete-confirm="Update your password? You will use the new password the next time you sign in." data-confirm-title="Update password?" data-confirm-action="Update" data-confirm-kind="warning" data-confirm-icon="key-round">
        <label>Current password<span class="password-field"><input type="password" name="current_password" required autocomplete="current-password"><button type="button" class="password-toggle" aria-label="Show password"><i data-lucide="eye"></i></button></span></label>
        <label>New password<span class="password-field"><input type="password" name="new_password" required autocomplete="new-password"><button type="button" class="password-toggle" aria-label="Show password"><i data-lucide="eye"></i></button></span></label>
        <label>Confirm new password<span class="password-field"><input type="password" name="confirm_password" required autocomplete="new-password"><button type="button" class="password-toggle" aria-label="Show password"><i data-lucide="eye"></i></button></span></label>
        <div style="margin-top:16px;"><button class="btn btn-primary" style="width:100%;justify-content:center;" type="submit">Update password</button></div>
    </form>
</div>
<?php require "_footer.php"; ?>
