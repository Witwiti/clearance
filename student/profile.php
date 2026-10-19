<?php
$pageTitle = "Profile";
$activePage = "profile";
require_once "../config/db.php";
require_once "../config/auth.php";
require_role("student");

$userId = (int)$_SESSION["user_id"];
$message = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $formAction = $_POST["form"] ?? "";
    if ($formAction === "upload_picture") {
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
                if (move_uploaded_file($_FILES["avatar"]["tmp_name"], $uploadDirectory . "/" . $avatarFile)) {
                    $avatarPath = "assets/uploads/profiles/" . $avatarFile;
                } else {
                    $error = "Unable to save the profile picture.";
                }
            }
        } else {
            $error = "Please choose a photo to upload.";
        }
        if ($error === "" && $avatarPath !== null) {
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
    } elseif ($formAction === "save_info") {
        $suffix = trim($_POST["suffix"] ?? "");
        $gender = trim($_POST["gender"] ?? "");
        $birthDate = trim($_POST["birth_date"] ?? "");
        $contactNumber = trim($_POST["contact_number"] ?? "");
        $address = trim($_POST["address"] ?? "");
        $allowedGenders = ["", "Male", "Female"];
        if (!in_array($gender, $allowedGenders, true)) {
            $error = "Please select a valid gender.";
        } elseif ($birthDate !== "" && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate) || strtotime($birthDate) === false || strtotime($birthDate) > time())) {
            $error = "Please enter a valid birth date.";
        } elseif (strlen($suffix) > 20 || strlen($contactNumber) > 30 || strlen($address) > 255) {
            $error = "One of the fields is too long.";
        } else {
            $birthDateValue = $birthDate !== "" ? $birthDate : null;
            $stmt = $conn->prepare("UPDATE students SET suffix = ?, gender = ?, birth_date = ?, contact_number = ?, address = ? WHERE user_id = ?");
            $stmt->bind_param("sssssi", $suffix, $gender, $birthDateValue, $contactNumber, $address, $userId);
            if ($stmt->execute()) {
                $touch = $conn->prepare("UPDATE users SET updated_at = NOW() WHERE user_id = ?");
                if ($touch) {
                    $touch->bind_param("i", $userId);
                    $touch->execute();
                    $touch->close();
                }
                $message = "Profile updated.";
            } else {
                $error = "Unable to update your profile.";
            }
            $stmt->close();
        }
    }
}

$student = [];
$stmt = $conn->prepare("SELECT student_number, first_name, middle_name, last_name, suffix, gender, birth_date, contact_number, address, course, year_level, email, status FROM students WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

$account = [];
$stmt = $conn->prepare("SELECT avatar_path, role, status, last_login, updated_at, created_at FROM users WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();
$account = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

$avatarUrl = !empty($account["avatar_path"]) ? "../" . $account["avatar_path"] : "";
$nameParts = preg_split('/\s+/', trim((string)($_SESSION["full_name"] ?? "")), -1, PREG_SPLIT_NO_EMPTY) ?: [];
$firstWord = $nameParts[0] ?? "";
$lastWord = count($nameParts) > 1 ? $nameParts[count($nameParts) - 1] : "";
$initials = strtoupper(mb_substr($firstWord, 0, 1) . ($lastWord !== "" ? mb_substr($lastWord, 0, 1) : ""));
if ($initials === "") $initials = "S";

$age = "";
if (!empty($student["birth_date"]) && strtotime($student["birth_date"]) !== false) {
    $birth = new DateTime($student["birth_date"]);
    $age = (string)$birth->diff(new DateTime("today"))->y;
}

$formatDateTime = function ($value) {
    if (empty($value) || strtotime($value) === false) return "—";
    return date("M d, Y, h:i A", strtotime($value));
};

require "_header.php";
?>
<div class="page-heading"><div><h1>Profile</h1><p>View and update your personal information.</p></div></div>
<?php if ($message !== ""): ?><div class="notice notice-success"><?= e($message) ?></div><?php endif; ?><?php if ($error !== ""): ?><div class="notice notice-error"><?= e($error) ?></div><?php endif; ?>

<div class="profile-page">
    <section class="form-card profile-card-block">
        <h2>Profile picture</h2>
        <p>Upload a square photo up to 2MB (JPEG, PNG, WebP, or GIF).</p>
        <div class="profile-picture-row">
            <?php if ($avatarUrl !== ""): ?><img src="<?= e($avatarUrl) ?>" class="profile-large-preview" alt="Profile picture"><?php else: ?><span class="profile-large-preview" aria-hidden="true"><?= e($initials) ?></span><?php endif; ?>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="form" value="upload_picture">
                <input type="file" name="avatar" id="avatarUpload" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
                <label class="change-picture-btn" for="avatarUpload"><i data-lucide="camera"></i>Change picture</label>
            </form>
        </div>
    </section>

    <section class="form-card announcement-form profile-card-block">
        <h2>Personal information</h2>
        <p>Update your contact details and profile picture. Name, student number, and course details are managed by the Guidance Counselor.</p>
        <form method="post">
            <input type="hidden" name="form" value="save_info">
            <div class="form-grid">
                <label style="grid-column:1/-1;">Email<input type="email" value="<?= e($student["email"] ?? "") ?>" readonly><small class="readonly-note">Email is used to sign in and cannot be changed here.</small></label>
                <label style="grid-column:1/-1;">Student Number<input value="<?= e($student["student_number"] ?? "") ?>" readonly><small class="readonly-note">Student number is assigned by the Guidance Counselor and cannot be changed here.</small></label>
                <label>First Name<input value="<?= e($student["first_name"] ?? "") ?>" readonly></label>
                <label>Middle Name<input value="<?= e($student["middle_name"] ?? "") ?>" readonly></label>
                <label>Last Name<input value="<?= e($student["last_name"] ?? "") ?>" readonly></label>
                <label>Suffix<input name="suffix" maxlength="20" placeholder="e.g. Jr., Sr., III" value="<?= e($student["suffix"] ?? "") ?>"></label>
                <label>Gender<select name="gender"><option value="">Select gender</option><option value="Male"<?= ($student["gender"] ?? "") === "Male" ? " selected" : "" ?>>Male</option><option value="Female"<?= ($student["gender"] ?? "") === "Female" ? " selected" : "" ?>>Female</option></select></label>
                <label>Birth Date<input type="date" name="birth_date" max="<?= e(date("Y-m-d")) ?>" value="<?= e($student["birth_date"] ?? "") ?>"></label>
                <label>Age<input value="<?= e($age) ?>" readonly placeholder="—"></label>
                <label>Contact Number<input name="contact_number" maxlength="30" placeholder="09XXXXXXXXX" value="<?= e($student["contact_number"] ?? "") ?>"></label>
                <label style="grid-column:1/-1;">Address<textarea name="address" rows="3" maxlength="255" placeholder="House no., street, barangay, municipality"><?= e($student["address"] ?? "") ?></textarea></label>
                <label style="grid-column:1/-1;">Course<input value="<?= e($student["course"] ?? "") ?>" readonly><small class="readonly-note">Course assignment is managed by the Guidance Counselor.</small></label>
                <label>Year Level<input value="<?= e($student["year_level"] ?? "") ?>" readonly></label>
            </div>
            <div class="profile-meta-grid">
                <div><strong>Role</strong><span>Student</span></div>
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
