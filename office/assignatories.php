<?php
$pageTitle = "Department Assignatories";
$activePage = "assignatories";
require_once "../config/db.php";
require_once "../config/auth.php";
require_role("office");

$message = $_SESSION["flash_message"] ?? "";
unset($_SESSION["flash_message"]);
$error = "";
$editAssignatory = null;

$deanContextStmt = $conn->prepare("SELECT department, college_dean_course FROM users WHERE user_id = ? LIMIT 1");
$deanContextStmt->bind_param("i", $_SESSION["user_id"]);
$deanContextStmt->execute();
$deanContext = $deanContextStmt->get_result()->fetch_assoc() ?: [];
$deanContextStmt->close();

$departmentScope = trim((string)($deanContext["department"] ?? "")) !== "" && trim((string)($deanContext["college_dean_course"] ?? "")) === "";
$currentDepartment = trim((string)($deanContext["college_dean_course"] ?? ($deanContext["department"] ?? "")));
$departmentFilter = $departmentScope ? $currentDepartment : "";

if (!is_dean_user()) {
    require "_header.php";
    ?>
    <div class="page-heading">
        <div>
            <h1>Department Assignatories</h1>
            <p>Manage signatories for your selected department course.</p>
        </div>
    </div>
    <div class="empty-panel">
        <i data-lucide="shield-alert"></i>
        <h3>Access restricted</h3>
        <p>Only the department dean can manage assignatories for this department.</p>
    </div>
    <?php require "_footer.php"; exit();
}

if ($currentDepartment === "") {
    require "_header.php";
    ?>
    <div class="page-heading">
        <div>
            <h1>Department Assignatories</h1>
            <p>Manage signatories for your selected department course.</p>
        </div>
    </div>
    <div class="empty-panel">
        <i data-lucide="shield-alert"></i>
        <h3>No department assigned</h3>
        <p>Please ask an administrator to select your department course before managing signatories.</p>
    </div>
    <?php require "_footer.php"; exit();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    $assignatoryId = (int)($_POST["assignatory_id"] ?? 0);

    if ($action === "save") {
        $assignatoryName = trim($_POST["assignatory_name"] ?? "");
        $officeId = (int)($_POST["office_id"] ?? 0);
        $position = trim($_POST["position"] ?? "Office Assignatory");
        $status = ($_POST["status"] ?? "active") === "inactive" ? "inactive" : "active";

        if ($assignatoryName === "" || $officeId <= 0 || $position === "") {
            $error = "Signatory name, office, and position are required.";
        } else {
                $duplicateStmt = $conn->prepare("SELECT assignatory_id FROM office_assignatories WHERE assignatory_name = ? AND office_id = ? AND assignatory_id <> ? LIMIT 1");
                $duplicateStmt->bind_param("sii", $assignatoryName, $officeId, $assignatoryId);
                $duplicateStmt->execute();
                $duplicateResult = $duplicateStmt->get_result();
                $duplicateExists = $duplicateResult && $duplicateResult->num_rows > 0;
                $duplicateStmt->close();

                if ($duplicateExists) {
                    $error = "This office is already assigned to this user in the department.";
                } elseif ($assignatoryId > 0) {
                    $stmt = $conn->prepare("UPDATE office_assignatories SET user_id = NULL, assignatory_name = ?, office_id = ?, position = ?, status = ? WHERE assignatory_id = ? AND assigned_by_user_id = ?");
                    $stmt->bind_param("sissii", $assignatoryName, $officeId, $position, $status, $assignatoryId, $_SESSION["user_id"]);
                    if ($stmt->execute()) {
                        $_SESSION["flash_message"] = "Assignatory updated.";
                        header("Location: assignatories.php");
                        exit;
                    }
                    else $error = "Unable to save the assignatory.";
                    $stmt->close();
                } else {
                    $stmt = $conn->prepare("INSERT INTO office_assignatories (user_id, assignatory_name, assigned_by_user_id, office_id, position, status) VALUES (NULL, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("siiss", $assignatoryName, $_SESSION["user_id"], $officeId, $position, $status);
                    if ($stmt->execute()) {
                        $_SESSION["flash_message"] = "Assignatory added.";
                        header("Location: assignatories.php");
                        exit;
                    }
                    else $error = "Unable to add the assignatory.";
                    $stmt->close();
                }
        }
    } elseif ($action === "toggle" && $assignatoryId > 0) {
        $newStatus = ($_POST["new_status"] ?? "") === "inactive" ? "inactive" : "active";
        $stmt = $conn->prepare("UPDATE office_assignatories SET status = ? WHERE assignatory_id = ? AND assigned_by_user_id = ?");
        $stmt->bind_param("sii", $newStatus, $assignatoryId, $_SESSION["user_id"]);
        $stmt->execute();
        $stmt->close();
        $message = "Assignatory status updated.";
    } elseif ($action === "delete" && $assignatoryId > 0) {
        $stmt = $conn->prepare("DELETE FROM office_assignatories WHERE assignatory_id = ? AND assigned_by_user_id = ?");
        $stmt->bind_param("ii", $assignatoryId, $_SESSION["user_id"]);
        $deleted = $stmt->execute() && $stmt->affected_rows > 0;
        $stmt->close();
        if ($deleted) $message = "Assignatory deleted."; else $error = "You cannot delete assignatories outside your department.";
    }
}

if ($_SERVER["REQUEST_METHOD"] !== "POST" && isset($_GET["edit"])) {
    $assignatoryId = (int)$_GET["edit"];
    $stmt = $conn->prepare("SELECT oa.* FROM office_assignatories oa WHERE oa.assignatory_id = ? AND oa.assigned_by_user_id = ? LIMIT 1");
    $stmt->bind_param("ii", $assignatoryId, $_SESSION["user_id"]);
    $stmt->execute();
    $editAssignatory = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

$officeOptions = $conn->query("SELECT office_id, office_name FROM offices ORDER BY office_name");

$rows = $conn->prepare(
    "SELECT oa.assignatory_id, COALESCE(oa.assignatory_name, u.full_name) AS signatory_name, u.email, o.office_name, oa.position, oa.status
     FROM office_assignatories oa
     LEFT JOIN users u ON u.user_id = oa.user_id
     INNER JOIN offices o ON o.office_id = oa.office_id
     WHERE oa.assigned_by_user_id = ?
     ORDER BY o.office_name, u.full_name"
);
$rows->bind_param("i", $_SESSION["user_id"]);
$rows->execute();
$rows = $rows->get_result();

require "_header.php";
?>
<div class="page-heading">
    <div>
        <h1>Department Assignatories</h1>
        <p>Manage office signatories for <?= e($currentDepartment) ?>.</p>
    </div>
    <a class="btn btn-primary" href="#assignatory-form" data-form-target="assignatory-form"><i data-lucide="plus"></i> Add Assignatory</a>
</div>

<?php if ($message !== ""): ?><div class="notice notice-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ""): ?><div class="notice notice-error"><?= e($error) ?></div><?php endif; ?>

    <div class="toolbar-card"><div class="search-field"><i data-lucide="search"></i><input type="search" placeholder="Search course signatories..."></div></div>

<div class="form-card announcement-form floating-form <?= $editAssignatory ? "is-open" : "" ?>" id="assignatory-form">
    <div class="floating-form-header">
        <h2><?= $editAssignatory ? "Edit assignatory" : "Add assignatory" ?></h2>
        <button class="floating-form-close" type="button" aria-label="Close form"><i data-lucide="x"></i></button>
    </div>
    <form method="post">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="assignatory_id" value="<?= (int)($editAssignatory["assignatory_id"] ?? 0) ?>">
        <label>Signatory name<input name="assignatory_name" required value="<?= e($editAssignatory["assignatory_name"] ?? "") ?>" placeholder="e.g. Francis Villarin"></label>
        <label>Office<select name="office_id" required><option value="">Select office</option><?php if ($officeOptions): while ($office = $officeOptions->fetch_assoc()): ?><option value="<?= (int)$office["office_id"] ?>" <?= (int)($editAssignatory["office_id"] ?? 0) === (int)$office["office_id"] ? "selected" : "" ?>><?= e($office["office_name"]) ?></option><?php endwhile; endif; ?></select></label>
        <label>Position<input name="position" required value="<?= e($editAssignatory["position"] ?? "Office Assignatory") ?>"></label>
        <label>Status<select name="status"><option value="active" <?= ($editAssignatory["status"] ?? "active") === "active" ? "selected" : "" ?>>Active</option><option value="inactive" <?= ($editAssignatory["status"] ?? "active") === "inactive" ? "selected" : "" ?>>Inactive</option></select></label>
        <button class="btn btn-primary" type="submit"><i data-lucide="save"></i><?= $editAssignatory ? "Save changes" : "Add assignatory" ?></button>
    </form>
</div>

<div class="table-card">
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Name</th><th>Email</th><th>Office</th><th>Position</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php if ($rows && $rows->num_rows): ?>
                <?php while ($row = $rows->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?= e($row["signatory_name"]) ?></strong></td>
                        <td><?= e($row["email"]) ?></td>
                        <td><?= e($row["office_name"]) ?></td>
                        <td><?= e($row["position"]) ?></td>
                        <td><span class="status status-<?= e($row["status"]) ?>"><?= e(ucfirst($row["status"])) ?></span></td>
                        <td class="action-group">
                            <a class="table-action" href="assignatories.php?edit=<?= (int)$row["assignatory_id"] ?>#assignatory-form">Edit</a>
                            <form method="post" data-delete-confirm="Delete this department assignatory?">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="assignatory_id" value="<?= (int)$row["assignatory_id"] ?>">
                                <button class="table-action table-action-danger" type="submit">Delete</button>
                            </form>
                            <form method="post" <?= $row["status"] === "active" ? "data-delete-confirm=\"Are you sure you want to deactivate this department assignatory?\" data-confirm-title=\"Are you sure you want to deactivate this department assignatory?\" data-confirm-action=\"Deactivate\"" : "data-delete-confirm=\"Are you sure you want to activate this department assignatory again?\" data-confirm-title=\"Are you sure you want to activate this department assignatory again?\" data-confirm-action=\"Activate\"" ?>>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="new_status" value="<?= $row["status"] === "active" ? "inactive" : "active" ?>">
                                <input type="hidden" name="assignatory_id" value="<?= (int)$row["assignatory_id"] ?>">
                                <button class="table-action" type="submit"><?= $row["status"] === "active" ? "Deactivate" : "Activate" ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="6" class="empty-state">No department assignatories found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require "_footer.php"; ?>
