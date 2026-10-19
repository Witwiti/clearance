<?php
$pageTitle = "Profile";
$activePage = "profile";
require_once "../config/auth.php";
require_role("admin");
require_once "../config/db.php";

$message = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $userId = (int)$_SESSION["user_id"];
    $avatarPath = null;

    if (isset($_FILES["avatar"]) && $_FILES["avatar"]["error"] !== UPLOAD_ERR_NO_FILE) {
        $allowedTypes = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp", "image/gif" => "gif"];
        $fileType = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES["avatar"]["tmp_name"]);
        if ($_FILES["avatar"]["error"] !== UPLOAD_ERR_OK || !isset($allowedTypes[$fileType]) || $_FILES["avatar"]["size"] > 2 * 1024 * 1024) {
            $error = "Profile picture must be a JPG, PNG, WebP, or GIF image up to 2 MB.";
        } else {
            $uploadDirectory = dirname(__DIR__) . "/assets/uploads/profiles";
            if (!is_dir($uploadDirectory)) mkdir($uploadDirectory, 0755, true);
            $avatarFile = "user_" . bin2hex(random_bytes(12)) . "." . $allowedTypes[$fileType];
            if (move_uploaded_file($_FILES["avatar"]["tmp_name"], $uploadDirectory . "/" . $avatarFile)) $avatarPath = "assets/uploads/profiles/" . $avatarFile;
            else $error = "Unable to save the profile picture.";
        }
    }

    // Picture-only form (Change picture button) carries no name/email fields.
    $infoForm = isset($_POST["full_name"]) || isset($_POST["email"]);
    if ($error !== "") {
    } elseif (!$infoForm) {
        if ($avatarPath === null) {
            $error = "Please choose a photo to upload.";
        } else {
            $stmt = $conn->prepare("UPDATE users SET avatar_path = ?, updated_at = NOW() WHERE user_id = ?");
            $stmt->bind_param("si", $avatarPath, $userId);
            if ($stmt->execute()) {
                $_SESSION["avatar_path"] = $avatarPath;
                $message = "Profile picture updated.";
            } else {
                $error = "Unable to update your profile picture.";
            }
            $stmt->close();
        }
    } else {
        $fullName = trim($_POST["full_name"] ?? "");
        $email = trim($_POST["email"] ?? "");
        if ($fullName === "" || $email === "") {
            $error = "Full name and email are required.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } else {
            $emailCheck = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id <> ? LIMIT 1");
            $emailCheck->bind_param("si", $email, $userId);
            $emailCheck->execute();
            $emailTaken = $emailCheck->get_result()->num_rows > 0;
            $emailCheck->close();
            if ($emailTaken) {
                $error = "Email is already in use by another account.";
            }
        }

        if ($error === "") {
            if ($avatarPath !== null) {
                $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, avatar_path = ?, updated_at = NOW() WHERE user_id = ?");
                $stmt->bind_param("sssi", $fullName, $email, $avatarPath, $userId);
            } else {
                $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, updated_at = NOW() WHERE user_id = ?");
                $stmt->bind_param("ssi", $fullName, $email, $userId);
            }
            if ($stmt->execute()) {
                $_SESSION["full_name"] = $fullName;
                $_SESSION["email"] = $email;
                if ($avatarPath !== null) $_SESSION["avatar_path"] = $avatarPath;
                $message = "Profile updated.";
            } else {
                $error = "Unable to update your profile.";
            }
            $stmt->close();
        }
    }
}
$accountStmt = $conn->prepare("SELECT avatar_path, role, status, last_login, updated_at FROM users WHERE user_id = ? LIMIT 1");
$accountStmt->bind_param("i", $_SESSION["user_id"]);
$accountStmt->execute();
$account = $accountStmt->get_result()->fetch_assoc() ?: [];
$accountStmt->close();
$avatarUrl = !empty($account["avatar_path"]) ? "../" . $account["avatar_path"] : "";
$nameParts = preg_split('/\s+/', trim((string)($_SESSION["full_name"] ?? "")), -1, PREG_SPLIT_NO_EMPTY) ?: [];
$firstWord = $nameParts[0] ?? "";
$lastWord = count($nameParts) > 1 ? $nameParts[count($nameParts) - 1] : "";
$initials = strtoupper(mb_substr($firstWord, 0, 1) . ($lastWord !== "" ? mb_substr($lastWord, 0, 1) : ""));
if ($initials === "") $initials = "A";
$formatDateTime = function ($value) {
    if (empty($value) || strtotime($value) === false) return "—";
    return date("M d, Y, h:i A", strtotime($value));
};
require "_header.php";
?>
<div class="page-heading">
    <div>
        <h1>Profile</h1>
        <p>View and update your personal information.</p>
    </div>
</div>

<?php if ($message !== ""): ?><div class="notice notice-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ""): ?><div class="notice notice-error"><?= e($error) ?></div><?php endif; ?>

<div class="profile-page">
    <section class="form-card profile-card-block">
        <h2>Profile picture</h2>
        <p>Upload a square photo up to 2MB (JPEG, PNG, WebP, or GIF).</p>
        <div class="profile-picture-row">
            <?php if ($avatarUrl !== ""): ?><img src="<?= e($avatarUrl) ?>" class="profile-large-preview" alt="Administrator profile picture"><?php else: ?><span class="profile-large-preview" aria-hidden="true"><?= e($initials) ?></span><?php endif; ?>
            <form method="post" enctype="multipart/form-data">
                <input type="file" name="avatar" id="avatarUpload" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
                <label class="change-picture-btn" for="avatarUpload"><i data-lucide="camera"></i>Change picture</label>
            </form>
        </div>
    </section>

    <section class="form-card announcement-form profile-card-block">
        <h2>Personal information</h2>
        <p>Update your contact details and profile picture.</p>
        <form method="post" enctype="multipart/form-data">
            <div class="form-grid">
                <label style="grid-column:1/-1;">Full name<input name="full_name" required value="<?= e($_SESSION["full_name"]) ?>"></label>
                <label style="grid-column:1/-1;">Email<input type="email" name="email" required value="<?= e($_SESSION["email"]) ?>"><small class="readonly-note">Email is used to sign in.</small></label>
            </div>
            <div class="profile-meta-grid">
                <div><strong>Role</strong><span>Admin</span></div>
                <div><strong>Status</strong><span><?= e(ucfirst($account["status"] ?? "Active")) ?></span></div>
                <div><strong>Last Login</strong><span><?= e($formatDateTime($account["last_login"] ?? null)) ?></span></div>
                <div><strong>Updated At</strong><span><?= e($formatDateTime($account["updated_at"] ?? null)) ?></span></div>
            </div>
            <div style="margin-top:18px;"><button class="btn btn-primary" type="submit"><i data-lucide="save"></i>Save changes</button></div>
        </form>
    </section>
</div>
<script>
document.getElementById("avatarUpload")?.addEventListener("change", function () {
    if (this.files && this.files.length) this.form.submit();
});
</script>
<?php require "_footer.php"; ?>
