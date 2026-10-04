<?php
$pageTitle = "Courses";
$activePage = "courses";
require_once "../config/auth.php";
require_role("admin");
require_once "../config/db.php";

$conn->query(
    "CREATE TABLE IF NOT EXISTS courses (
        course_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        course_code VARCHAR(30) NOT NULL UNIQUE,
        course_name VARCHAR(120) NOT NULL,
        description VARCHAR(255) DEFAULT NULL,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )"
);

$message = $_SESSION["flash_message"] ?? "";
unset($_SESSION["flash_message"]);
$error = "";
$editCourse = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    $courseId = (int)($_POST["course_id"] ?? 0);

    if ($action === "save") {
        $courseCode = trim($_POST["course_code"] ?? "");
        $courseName = trim($_POST["course_name"] ?? "");
        $description = trim($_POST["description"] ?? "");
        $status = ($_POST["status"] ?? "active") === "inactive" ? "inactive" : "active";
        $editCourse = compact("courseId", "courseCode", "courseName", "description", "status");

        if ($courseCode === "" || $courseName === "") {
            $error = "Course code and course name are required.";
        } elseif ($courseId > 0) {
            $stmt = $conn->prepare("UPDATE courses SET course_code = ?, course_name = ?, description = ?, status = ? WHERE course_id = ?");
            $stmt->bind_param("ssssi", $courseCode, $courseName, $description, $status, $courseId);
            if ($stmt->execute()) {
                $_SESSION["flash_message"] = "Course updated.";
                header("Location: courses.php");
                exit;
            }
            else $error = "Unable to save the course. The course code may already exist.";
            $stmt->close();
        } else {
            $stmt = $conn->prepare("INSERT INTO courses (course_code, course_name, description, status) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $courseCode, $courseName, $description, $status);
            if ($stmt->execute()) {
                $_SESSION["flash_message"] = "Course added.";
                header("Location: courses.php");
                exit;
            }
            else $error = "Unable to add the course. The course code may already exist.";
            $stmt->close();
        }

    } elseif ($action === "toggle" && $courseId > 0) {
        $stmt = $conn->prepare("UPDATE courses SET status = IF(status = 'active', 'inactive', 'active') WHERE course_id = ?");
        $stmt->bind_param("i", $courseId);
        $stmt->execute();
        $stmt->close();
        $_SESSION["flash_message"] = "Course status updated.";
        header("Location: courses.php");
        exit;
    }
}

if ($_SERVER["REQUEST_METHOD"] !== "POST" && isset($_GET["edit"])) {
    $courseId = (int)$_GET["edit"];
    $stmt = $conn->prepare("SELECT * FROM courses WHERE course_id = ?");
    $stmt->bind_param("i", $courseId);
    $stmt->execute();
    $editCourse = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

$courses = $conn->query(
    "SELECT course_id, course_code, course_name, description, status, created_at
     FROM courses ORDER BY course_name ASC"
);

require "_header.php";
?>
<div class="page-heading">
    <div>
        <h1>Courses</h1>
        <p>Manage the academic programs offered by the school.</p>
    </div>
    <a class="btn btn-primary" href="#course-form" data-form-target="course-form"><i data-lucide="plus"></i> Add Course</a>
</div>

<?php if ($message !== ""): ?><div class="notice notice-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ""): ?><div class="notice notice-error"><?= e($error) ?></div><?php endif; ?>

<div class="toolbar-card"><div class="search-field"><i data-lucide="search"></i><input type="search" placeholder="Search courses..."></div></div>

<div class="form-card announcement-form floating-form <?= $editCourse ? "is-open" : "" ?>" id="course-form">
    <div class="floating-form-header">
        <h2><?= $editCourse ? "Edit course" : "Add course" ?></h2>
        <button class="floating-form-close" type="button" aria-label="Close form"><i data-lucide="x"></i></button>
    </div>
    <form method="post">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="course_id" value="<?= (int)($editCourse["course_id"] ?? $editCourse["courseId"] ?? 0) ?>">
        <label>Course code<input name="course_code" required value="<?= e($editCourse["course_code"] ?? $editCourse["courseCode"] ?? "") ?>"></label>
        <label>Course name<input name="course_name" required value="<?= e($editCourse["course_name"] ?? $editCourse["courseName"] ?? "") ?>"></label>
        <label>Description<textarea name="description" rows="3"><?= e($editCourse["description"] ?? "") ?></textarea></label>
        <label>Status<select name="status"><option value="active" <?= ($editCourse["status"] ?? "active") === "active" ? "selected" : "" ?>>Active</option><option value="inactive" <?= ($editCourse["status"] ?? "active") === "inactive" ? "selected" : "" ?>>Inactive</option></select></label>
        <button class="btn btn-primary" type="submit"><i data-lucide="save"></i><?= $editCourse ? "Save changes" : "Add course" ?></button>
    </form>
</div>

<div class="table-card">
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Code</th><th>Course</th><th>Description</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php if ($courses && $courses->num_rows): ?>
                <?php while ($row = $courses->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?= e($row["course_code"]) ?></strong></td>
                        <td><?= e($row["course_name"]) ?></td>
                        <td><?= e($row["description"] ?: "—") ?></td>
                        <td><span class="status status-<?= e($row["status"]) ?>"><?= e(ucfirst($row["status"])) ?></span></td>
                        <td class="action-group">
                            <a class="table-action" href="courses.php?edit=<?= (int)$row["course_id"] ?>#course-form">Edit</a>
                            <form method="post">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="course_id" value="<?= (int)$row["course_id"] ?>">
                                <button class="table-action" type="submit"><?= $row["status"] === "active" ? "Deactivate" : "Activate" ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="5" class="empty-state">No courses found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require "_footer.php"; ?>
