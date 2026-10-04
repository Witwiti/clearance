<?php
$pageTitle = "Users";
$activePage = "users";
require_once "../config/auth.php";
require_role("admin");
require_once "../config/db.php";

$message = $_SESSION["flash_message"] ?? "";
unset($_SESSION["flash_message"]);
$error = "";
$editUser = null;

$generateStudentNumber = function (): string {
    global $conn;

    $result = $conn->query("SELECT student_number FROM students WHERE student_number IS NOT NULL AND student_number <> ''");
    $highest = 0;

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $value = trim((string)($row["student_number"] ?? ""));
            if (preg_match('/^SC\s*-?\s*(\d+)$/i', $value, $match)) {
                $number = (int)$match[1];
                if ($number > $highest) {
                    $highest = $number;
                }
            }
        }
    }

    return "SC-" . str_pad((string)($highest + 1), 3, "0", STR_PAD_LEFT);
};

$checkUserConflict = function (string $username, string $email, ?int $ignoreUserId = null): ?string {
    global $conn;

    $query = "SELECT user_id, username, email FROM users WHERE (username = ? OR email = ?)";
    $params = [$username, $email];
    $types = "ss";

    if ($ignoreUserId !== null) {
        $query .= " AND user_id <> ?";
        $params[] = $ignoreUserId;
        $types .= "i";
    }

    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$existing) {
        return null;
    }

    if (strcasecmp((string)$existing["username"], $username) === 0) {
        return "This username is already in use.";
    }

    return "This email address is already in use.";
};

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    $userId = (int)($_POST["user_id"] ?? 0);

    if ($action === "save") {
        $username = trim($_POST["username"] ?? "");
        $fullName = trim($_POST["full_name"] ?? "");
        $email = trim($_POST["email"] ?? "");
        $role = in_array($_POST["role"] ?? "", ["admin", "student", "office"], true) ? $_POST["role"] : "student";
        $status = ($_POST["status"] ?? "active") === "inactive" ? "inactive" : "active";
        $collegeDean = isset($_POST["college_dean"]) && $role === "office" ? 1 : 0;
        $collegeDeanCourse = $collegeDean ? trim($_POST["college_dean_course"] ?? "") : "";
        $password = $_POST["password"] ?? "";
        $studentNumber = trim($_POST["student_number"] ?? "");
        $course = trim($_POST["course"] ?? "");
        $yearLevel = trim($_POST["year_level"] ?? "");
        $avatarPath = null;

        if ($role === "student" && $studentNumber === "") {
            $studentNumber = $generateStudentNumber();
        }

        if (isset($_FILES["avatar"]) && $_FILES["avatar"]["error"] !== UPLOAD_ERR_NO_FILE) {
            $allowedTypes = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp"];
            $fileType = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES["avatar"]["tmp_name"]);
            if ($_FILES["avatar"]["error"] !== UPLOAD_ERR_OK || !isset($allowedTypes[$fileType]) || $_FILES["avatar"]["size"] > 2 * 1024 * 1024) {
                $error = "Profile picture must be a JPG, PNG, or WebP image up to 2 MB.";
            } else {
                $uploadDirectory = dirname(__DIR__) . "/assets/uploads/profiles";
                if (!is_dir($uploadDirectory)) mkdir($uploadDirectory, 0755, true);
                $avatarFile = "user_" . bin2hex(random_bytes(12)) . "." . $allowedTypes[$fileType];
                if (move_uploaded_file($_FILES["avatar"]["tmp_name"], $uploadDirectory . "/" . $avatarFile)) $avatarPath = "assets/uploads/profiles/" . $avatarFile;
                else $error = "Unable to save the profile picture.";
            }
        }

        if ($error !== "") {
        } elseif ($username === "" || $fullName === "" || $email === "") {
            $error = "Username, full name, and email are required.";
        } elseif ($role === "student" && $studentNumber === "") {
            $error = "Student number is required for student accounts.";
        } elseif ($collegeDean && $collegeDeanCourse === "") {
            $error = "Select a course for the college dean.";
        } else {
            $conflictError = $checkUserConflict($username, $email, $userId > 0 ? $userId : null);
            if ($conflictError !== null) {
                $error = $conflictError;
            } elseif ($userId > 0) {
                try {
                    if ($avatarPath !== null && $password !== "") {
                        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $conn->prepare("UPDATE users SET username = ?, full_name = ?, email = ?, avatar_path = ?, role = ?, status = ?, password = ?, college_dean = ?, college_dean_course = ? WHERE user_id = ?");
                        $stmt->bind_param("sssssssisi", $username, $fullName, $email, $avatarPath, $role, $status, $passwordHash, $collegeDean, $collegeDeanCourse, $userId);
                    } elseif ($avatarPath !== null) {
                        $stmt = $conn->prepare("UPDATE users SET username = ?, full_name = ?, email = ?, avatar_path = ?, role = ?, status = ?, college_dean = ?, college_dean_course = ? WHERE user_id = ?");
                        $stmt->bind_param("ssssssisi", $username, $fullName, $email, $avatarPath, $role, $status, $collegeDean, $collegeDeanCourse, $userId);
                    } elseif ($password !== "") {
                        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $conn->prepare("UPDATE users SET username = ?, full_name = ?, email = ?, role = ?, status = ?, password = ?, college_dean = ?, college_dean_course = ? WHERE user_id = ?");
                        $stmt->bind_param("ssssssisi", $username, $fullName, $email, $role, $status, $passwordHash, $collegeDean, $collegeDeanCourse, $userId);
                    } else {
                        $stmt = $conn->prepare("UPDATE users SET username = ?, full_name = ?, email = ?, role = ?, status = ?, college_dean = ?, college_dean_course = ? WHERE user_id = ?");
                        $stmt->bind_param("sssssssi", $username, $fullName, $email, $role, $status, $collegeDean, $collegeDeanCourse, $userId);
                    }

                    if ($stmt->execute()) {
                        if ($userId === (int)$_SESSION["user_id"]) {
                            $_SESSION["college_dean"] = $collegeDean;
                            $_SESSION["college_dean_course"] = $collegeDeanCourse;
                            $_SESSION["department"] = $collegeDeanCourse;
                        }
                        if ($role === "student") {
                            $nameParts = preg_split('/\s+/', $fullName, -1, PREG_SPLIT_NO_EMPTY);
                            $firstName = $nameParts[0] ?? $fullName;
                            $lastName = count($nameParts) > 1 ? array_pop($nameParts) : $firstName;
                            $middleName = count($nameParts) > 1 ? implode(" ", array_slice($nameParts, 1)) : "";
                            $studentStmt = $conn->prepare(
                                "UPDATE students
                                 SET student_number = ?, first_name = ?, middle_name = ?, last_name = ?, course = ?, year_level = ?, email = ?, status = ?
                                 WHERE user_id = ?"
                            );
                            $studentStmt->bind_param("ssssssssi", $studentNumber, $firstName, $middleName, $lastName, $course, $yearLevel, $email, $status, $userId);
                            $studentStmt->execute();
                            $studentStmt->close();
                        }
                        $editUser = [
                            "user_id" => $userId,
                            "username" => $username,
                            "full_name" => $fullName,
                            "email" => $email,
                            "avatar_path" => $avatarPath,
                            "role" => $role,
                            "status" => $status,
                            "student_number" => $studentNumber,
                            "course" => $course,
                            "year_level" => $yearLevel,
                            "college_dean" => $collegeDean,
                            "college_dean_course" => $collegeDeanCourse
                        ];
                        $_SESSION["flash_message"] = "User updated.";
                        header("Location: users.php");
                        exit;
                    } else {
                        $error = "Unable to save the user.";
                    }
                    $stmt->close();
                } catch (mysqli_sql_exception $e) {
                    $error = "This username or email is already in use.";
                }
            } elseif ($password === "") {
                $error = "A password is required for a new user.";
            } else {
                try {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $conn->begin_transaction();
                    $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, email, avatar_path, role, status, college_dean, college_dean_course) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("sssssssis", $username, $passwordHash, $fullName, $email, $avatarPath, $role, $status, $collegeDean, $collegeDeanCourse);
                    $userCreated = $stmt->execute();
                    $newUserId = $stmt->insert_id;
                    $stmt->close();

                    $studentCreated = true;
                    if ($userCreated && $role === "student") {
                        $nameParts = preg_split('/\s+/', $fullName, -1, PREG_SPLIT_NO_EMPTY);
                        $firstName = $nameParts[0] ?? $fullName;
                        $lastName = count($nameParts) > 1 ? array_pop($nameParts) : $firstName;
                        $middleName = count($nameParts) > 1 ? implode(" ", array_slice($nameParts, 1)) : "";
                        $stmt = $conn->prepare(
                            "INSERT INTO students (user_id, student_number, first_name, middle_name, last_name, course, year_level, email, status)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                        );
                        $stmt->bind_param("issssssss", $newUserId, $studentNumber, $firstName, $middleName, $lastName, $course, $yearLevel, $email, $status);
                        $studentCreated = $stmt->execute();
                        $stmt->close();
                    }

                    if ($userCreated && $studentCreated) {
                        $conn->commit();
                        $_SESSION["flash_message"] = $role === "student" ? "Student account and student record added." : "User added.";
                        header("Location: users.php");
                        exit;
                    } else {
                        $conn->rollback();
                        $error = "Unable to add the user. Username, email, or student number may already exist.";
                    }
                } catch (mysqli_sql_exception $e) {
                    $conn->rollback();
                    $error = "This username or email is already in use.";
                }
            }
        }
    } elseif ($action === "toggle" && $userId > 0 && $userId !== (int)$_SESSION["user_id"]) {
        $stmt = $conn->prepare("UPDATE users SET status = IF(status = 'active', 'inactive', 'active') WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->close();
        $message = "User status updated.";
    } elseif ($action === "delete" && $userId > 0) {
        $stmt = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $target = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$target) {
            $error = "User account not found.";
        } elseif ($target["role"] === "admin" || $userId === (int)$_SESSION["user_id"]) {
            $error = "Admin accounts cannot be deleted.";
        } else {
            $conn->begin_transaction();
            $linkedRecordsDeleted = true;

            if ($target["role"] === "student") {
                $stmt = $conn->prepare("DELETE FROM students WHERE user_id = ?");
                $stmt->bind_param("i", $userId);
                $linkedRecordsDeleted = $stmt->execute();
                $stmt->close();
            } elseif ($target["role"] === "office") {
                $stmt = $conn->prepare("DELETE FROM office_assignatories WHERE user_id = ?");
                $stmt->bind_param("i", $userId);
                $linkedRecordsDeleted = $stmt->execute();
                $stmt->close();
            }

            if ($linkedRecordsDeleted) {
                $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ? AND role <> 'admin'");
                $stmt->bind_param("i", $userId);
                $accountDeleted = $stmt->execute() && $stmt->affected_rows > 0;
                $stmt->close();
            } else {
                $accountDeleted = false;
            }

            if ($accountDeleted) {
                $conn->commit();
                $message = "User account and linked record deleted.";
            } else {
                $conn->rollback();
                $error = "Unable to delete the user and linked record.";
            }
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] !== "POST" && isset($_GET["edit"])) {
    $userId = (int)$_GET["edit"];
    $stmt = $conn->prepare(
        "SELECT u.user_id, u.username, u.full_name, u.email, u.avatar_path, u.role, u.status, u.college_dean, u.college_dean_course,
                s.student_number, s.course, s.year_level
         FROM users u LEFT JOIN students s ON s.user_id = u.user_id
         WHERE u.user_id = ?"
    );
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $editUser = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

$courseResult = $conn->query(
    "SELECT course_name FROM courses WHERE status = 'active' ORDER BY course_name ASC"
);
$courseOptions = [];
if ($courseResult) {
    while ($courseRow = $courseResult->fetch_assoc()) {
        $courseOptions[] = $courseRow;
    }
}

$users = $conn->query(
    "SELECT user_id, username, full_name, email, avatar_path, role, status, created_at
     FROM users ORDER BY created_at DESC"
);

require "_header.php";
?>
<div class="page-heading">
    <div>
        <h1>Users</h1>
        <p>View accounts used by administrators, students, and offices.</p>
    </div>
    <a class="btn btn-primary" href="#user-form" data-form-target="user-form"><i data-lucide="plus"></i> Add User</a>
</div>

<?php if ($message !== ""): ?><div class="notice notice-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ""): ?><div class="notice notice-error"><?= e($error) ?></div><?php endif; ?>

<div class="form-card announcement-form floating-form <?= $editUser ? "is-open" : "" ?>" id="user-form">
    <div class="floating-form-header">
        <h2><?= $editUser ? "Edit user" : "Add user" ?></h2>
        <button class="floating-form-close" type="button" aria-label="Close form"><i data-lucide="x"></i></button>
    </div>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="user_id" value="<?= (int)($editUser["user_id"] ?? 0) ?>">
        <div class="form-grid">
            <label>Full name<input name="full_name" required value="<?= e($editUser["full_name"] ?? "") ?>"></label>
            <label>Username<input name="username" required value="<?= e($editUser["username"] ?? "") ?>"></label>
            <label>Email<input type="email" name="email" required value="<?= e($editUser["email"] ?? "") ?>"></label>
            <label>Password<input type="password" name="password" <?= $editUser ? "placeholder=\"Leave blank to keep current\"" : "required" ?>></label>
                <label>Role<select name="role" id="userRole"><option value="admin" <?= ($editUser["role"] ?? "") === "admin" ? "selected" : "" ?>>Admin</option><option value="student" <?= ($editUser["role"] ?? "student") === "student" ? "selected" : "" ?>>Student</option><option value="office" <?= ($editUser["role"] ?? "") === "office" ? "selected" : "" ?>>Office</option></select></label>
            <label>Status<select name="status"><option value="active" <?= ($editUser["status"] ?? "active") === "active" ? "selected" : "" ?>>Active</option><option value="inactive" <?= ($editUser["status"] ?? "active") === "inactive" ? "selected" : "" ?>>Inactive</option></select></label>
            <label class="checkbox-field office-only-field <?= ($editUser["role"] ?? "student") === "office" ? "" : "is-hidden" ?>" <?= ($editUser["role"] ?? "student") === "office" ? "" : "hidden" ?> style="<?= ($editUser["role"] ?? "student") === "office" ? "" : "display: none !important;" ?>"><input type="checkbox" name="college_dean" id="collegeDeanToggle" value="1" <?= !empty($editUser["college_dean"]) ? "checked" : "" ?>> College dean (Office user)</label>
            <label class="dean-course-field office-only-field <?= !empty($editUser["college_dean"]) && ($editUser["role"] ?? "") === "office" ? "" : "is-hidden" ?>" hidden style="<?= !empty($editUser["college_dean"]) && ($editUser["role"] ?? "") === "office" ? "" : "display: none !important;" ?>">Department course
                <select name="college_dean_course" id="collegeDeanCourse">
                    <option value="">Select course</option>
                    <?php foreach ($courseOptions as $courseRow): ?>
                        <option value="<?= e($courseRow["course_name"]) ?>" <?= ($editUser["college_dean_course"] ?? "") === (string)$courseRow["course_name"] ? "selected" : "" ?>><?= e($courseRow["course_name"]) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="student-only-field">Course
                <select name="course">
                    <option value="" <?= (($editUser["course"] ?? "") === "") ? "selected" : "" ?>>Select course</option>
                    <?php if ($courseOptions): ?>
                        <?php foreach ($courseOptions as $courseRow): ?>
                            <option value="<?= e($courseRow["course_name"]) ?>" <?= (($editUser["course"] ?? "") === (string)$courseRow["course_name"]) ? "selected" : "" ?>><?= e($courseRow["course_name"]) ?></option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="" disabled>No courses available</option>
                    <?php endif; ?>
                </select>
            </label>
            <label class="student-only-field">Year level
                <select name="year_level">
                    <option value="" <?= (($editUser["year_level"] ?? "") === "") ? "selected" : "" ?>>Select year level</option>
                    <option value="1st Year" <?= (($editUser["year_level"] ?? "") === "1st Year") ? "selected" : "" ?>>1st Year</option>
                    <option value="2nd Year" <?= (($editUser["year_level"] ?? "") === "2nd Year") ? "selected" : "" ?>>2nd Year</option>
                    <option value="3rd Year" <?= (($editUser["year_level"] ?? "") === "3rd Year") ? "selected" : "" ?>>3rd Year</option>
                    <option value="4th Year" <?= (($editUser["year_level"] ?? "") === "4th Year") ? "selected" : "" ?>>4th Year</option>
                </select>
            </label>
            <label>Profile picture<input type="file" name="avatar" accept="image/jpeg,image/png,image/webp"><small class="field-help">JPG, PNG, or WebP up to 2 MB.</small></label>
        </div>
        <button class="btn btn-primary" type="submit"><i data-lucide="save"></i><?= $editUser ? "Save changes" : "Add user" ?></button>
    </form>
</div>

<div class="toolbar-card filter-row">
    <div class="search-field"><i data-lucide="search"></i><input type="search" placeholder="Search users..."></div>
    <select data-filter="role" aria-label="Filter users by role">
        <option value="all">All users</option>
        <option value="admin">Admins</option>
        <option value="student">Students</option>
        <option value="office">Offices</option>
    </select>
</div>

<div class="table-card">
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php if ($users && $users->num_rows): ?>
                <?php while ($row = $users->fetch_assoc()): ?>
                    <tr data-role="<?= e($row["role"]) ?>">
                        <td><div class="user-cell"><img src="<?= e($row["avatar_path"] ? "../" . $row["avatar_path"] : "../assets/images/system_logo.jpg") ?>" alt=""><strong><?= e($row["full_name"]) ?></strong></div></td>
                        <td><?= e($row["username"]) ?></td>
                        <td><?= e($row["email"]) ?></td>
                        <td><span class="role-badge"><?= e(ucfirst($row["role"])) ?></span></td>
                        <td><span class="status status-<?= e($row["status"]) ?>"><?= e(ucfirst($row["status"])) ?></span></td>
                        <td class="action-group">
                            <a class="table-action" href="users.php?edit=<?= (int)$row["user_id"] ?>#user-form">Edit</a>
                            <?php if ((int)$row["user_id"] !== (int)$_SESSION["user_id"]): ?>
                                <form method="post"><input type="hidden" name="action" value="toggle"><input type="hidden" name="user_id" value="<?= (int)$row["user_id"] ?>"><button class="table-action" type="submit"><?= $row["status"] === "active" ? "Deactivate" : "Activate" ?></button></form>
                            <?php endif; ?>
                            <?php if ($row["role"] !== "admin"): ?>
                                <form method="post" data-delete-confirm="Delete this user account? This cannot be undone.">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="user_id" value="<?= (int)$row["user_id"] ?>">
                                    <button class="table-action table-action-danger" type="submit">Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="6" class="empty-state">No users found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require "_footer.php"; ?>
