<?php
$pageTitle = "Students";
$activePage = "students";
require_once "../config/auth.php";
require_role("admin");
require_once "../config/db.php";

$message = $_SESSION["flash_message"] ?? "";
unset($_SESSION["flash_message"]);
$error = "";
$editStudent = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    $studentId = (int)($_POST["student_id"] ?? 0);

    if ($action === "save") {
        $studentNumber = trim($_POST["student_number"] ?? "");
        $firstName = trim($_POST["first_name"] ?? "");
        $middleName = trim($_POST["middle_name"] ?? "");
        $lastName = trim($_POST["last_name"] ?? "");
        $course = trim($_POST["course"] ?? "");
        $yearLevel = trim($_POST["year_level"] ?? "");
        $email = trim($_POST["email"] ?? "");
        $status = ($_POST["status"] ?? "active") === "inactive" ? "inactive" : "active";

        $editStudent = compact("studentId", "studentNumber", "firstName", "middleName", "lastName", "course", "yearLevel", "email", "status");
        if ($studentNumber === "" || $firstName === "" || $lastName === "") {
            $error = "Student number, first name, and last name are required.";
        } elseif ($studentId > 0) {
            $stmt = $conn->prepare(
                "UPDATE students SET student_number = ?, first_name = ?, middle_name = ?, last_name = ?, course = ?, year_level = ?, email = ?, status = ? WHERE student_id = ?"
            );
            $stmt->bind_param("ssssssssi", $studentNumber, $firstName, $middleName, $lastName, $course, $yearLevel, $email, $status, $studentId);
            if ($stmt->execute()) {
                $_SESSION["flash_message"] = "Student updated.";
                header("Location: students.php");
                exit;
            }
            else $error = "Unable to save the student. Student number may already exist.";
            $stmt->close();
        } else {
            $error = "Student records must be created from the Users page.";
        }
        if ($message !== "" && $studentId <= 0) $editStudent = null;
    } elseif ($action === "toggle" && $studentId > 0) {
        $stmt = $conn->prepare("UPDATE students SET status = IF(status = 'active', 'inactive', 'active') WHERE student_id = ?");
        $stmt->bind_param("i", $studentId);
        $stmt->execute();
        $stmt->close();
        $message = "Student status updated.";
    } elseif ($action === "delete" && $studentId > 0) {
        $stmt = $conn->prepare("DELETE FROM students WHERE student_id = ?");
        $stmt->bind_param("i", $studentId);
        if ($stmt->execute() && $stmt->affected_rows > 0) $message = "Student record deleted.";
        else $error = "Student record could not be deleted.";
        $stmt->close();
    }
}

if ($_SERVER["REQUEST_METHOD"] !== "POST" && isset($_GET["edit"])) {
    $studentId = (int)$_GET["edit"];
    $stmt = $conn->prepare("SELECT * FROM students WHERE student_id = ?");
    $stmt->bind_param("i", $studentId);
    $stmt->execute();
    $editStudent = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

$courseFilter = trim($_GET["course_filter"] ?? "");

$studentCourseOptions = $conn->query(
    "SELECT DISTINCT course FROM students WHERE course IS NOT NULL AND course <> '' ORDER BY course ASC"
);

if ($courseFilter !== "") {
    $stmt = $conn->prepare(
        "SELECT student_id, student_number,
                CONCAT(first_name, ' ', last_name) AS full_name,
                course, year_level, status, created_at
         FROM students
         WHERE course = ?
         ORDER BY CASE
             WHEN student_number REGEXP '^SC-[0-9]+$' THEN CAST(SUBSTRING(student_number, 4) AS UNSIGNED)
             ELSE 0
         END ASC, student_number ASC"
    );
    $stmt->bind_param("s", $courseFilter);
    $stmt->execute();
    $students = $stmt->get_result();
    $stmt->close();
} else {
    $students = $conn->query(
        "SELECT student_id, student_number,
                CONCAT(first_name, ' ', last_name) AS full_name,
                course, year_level, status, created_at
         FROM students
         ORDER BY CASE
             WHEN student_number REGEXP '^SC-[0-9]+$' THEN CAST(SUBSTRING(student_number, 4) AS UNSIGNED)
             ELSE 0
         END ASC, student_number ASC"
    );
}

require "_header.php";
?>
<div class="page-heading">
    <div>
        <h1>Students</h1>
        <p>Manage student records enrolled in the clearance system.</p>
    </div>
    <span class="muted">Student records are created from student user accounts.</span>
</div>

<?php if ($message !== ""): ?><div class="notice notice-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ""): ?><div class="notice notice-error"><?= e($error) ?></div><?php endif; ?>

<div class="form-card announcement-form floating-form <?= $editStudent ? "is-open" : "" ?>" id="student-form">
    <div class="floating-form-header">
        <h2>Edit student</h2>
        <button class="floating-form-close" type="button" aria-label="Close form"><i data-lucide="x"></i></button>
    </div>
    <form method="post">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="student_id" value="<?= (int)($editStudent["student_id"] ?? $editStudent["studentId"] ?? 0) ?>">
        <div class="form-grid">
            <label>Student number<input name="student_number" required value="<?= e($editStudent["student_number"] ?? $editStudent["studentNumber"] ?? "") ?>"></label>
            <label>First name<input name="first_name" required value="<?= e($editStudent["first_name"] ?? $editStudent["firstName"] ?? "") ?>"></label>
            <label>Middle name<input name="middle_name" value="<?= e($editStudent["middle_name"] ?? $editStudent["middleName"] ?? "") ?>"></label>
            <label>Last name<input name="last_name" required value="<?= e($editStudent["last_name"] ?? $editStudent["lastName"] ?? "") ?>"></label>
            <label>Course<input name="course" value="<?= e($editStudent["course"] ?? "") ?>"></label>
            <label>Year level
                <select name="year_level">
                    <option value="" <?= (($editStudent["year_level"] ?? $editStudent["yearLevel"] ?? "") === "") ? "selected" : "" ?>>Select year level</option>
                    <option value="1st Year" <?= (($editStudent["year_level"] ?? $editStudent["yearLevel"] ?? "") === "1st Year") ? "selected" : "" ?>>1st Year</option>
                    <option value="2nd Year" <?= (($editStudent["year_level"] ?? $editStudent["yearLevel"] ?? "") === "2nd Year") ? "selected" : "" ?>>2nd Year</option>
                    <option value="3rd Year" <?= (($editStudent["year_level"] ?? $editStudent["yearLevel"] ?? "") === "3rd Year") ? "selected" : "" ?>>3rd Year</option>
                    <option value="4th Year" <?= (($editStudent["year_level"] ?? $editStudent["yearLevel"] ?? "") === "4th Year") ? "selected" : "" ?>>4th Year</option>
                </select>
            </label>
            <label>Email<input type="email" name="email" value="<?= e($editStudent["email"] ?? "") ?>"></label>
            <label>Status<select name="status"><option value="active" <?= ($editStudent["status"] ?? "active") === "active" ? "selected" : "" ?>>Active</option><option value="inactive" <?= ($editStudent["status"] ?? "active") === "inactive" ? "selected" : "" ?>>Inactive</option></select></label>
        </div>
        <button class="btn btn-primary" type="submit"><i data-lucide="save"></i><?= $editStudent ? "Save changes" : "Add student" ?></button>
    </form>
</div>

<div class="toolbar-card student-toolbar">
    <div class="search-field">
        <i data-lucide="search"></i>
        <input type="search" placeholder="Search students...">
    </div>
    <form method="get" class="course-filter-form">
        <label class="course-filter-label">
            <span>Course</span>
            <span class="review-select-wrap">
                <select name="course_filter" onchange="this.form.submit()">
                    <option value="">All Courses</option>
                    <?php if ($studentCourseOptions): while ($option = $studentCourseOptions->fetch_assoc()): ?>
                        <option value="<?= e($option["course"]) ?>" <?= $courseFilter === (string)$option["course"] ? "selected" : "" ?>><?= e($option["course"]) ?></option>
                    <?php endwhile; endif; ?>
                </select>
                <i data-lucide="chevron-down" aria-hidden="true"></i>
            </span>
        </label>
    </form>
</div>

<div class="table-card">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Student Number</th><th>Name</th><th>Course</th>
                    <th>Year Level</th><th>Status</th><th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($students && $students->num_rows): ?>
                <?php while ($row = $students->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?= e($row["student_number"]) ?></strong></td>
                        <td><strong><?= e($row["full_name"]) ?></strong></td>
                        <td><?= e($row["course"] ?: "—") ?></td>
                        <td><?= e($row["year_level"] ?: "—") ?></td>
                        <td><span class="status status-<?= e($row["status"]) ?>"><?= e(ucfirst($row["status"])) ?></span></td>
                        <td class="action-group">
                            <a class="table-action" href="students.php?edit=<?= (int)$row["student_id"] ?>#student-form">Edit</a>
                            <form method="post">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="student_id" value="<?= (int)$row["student_id"] ?>">
                                <button class="table-action" type="submit"><?= $row["status"] === "active" ? "Deactivate" : "Activate" ?></button>
                            </form>
                            <form method="post" data-delete-confirm="Delete this student record? Related clearance requests will also be removed.">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="student_id" value="<?= (int)$row["student_id"] ?>">
                                <button class="table-action table-action-danger" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="6" class="empty-state">No students found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require "_footer.php"; ?>
