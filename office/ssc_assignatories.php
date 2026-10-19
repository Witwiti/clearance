<?php
$pageTitle = "SSC Assignatories";
$activePage = "ssc_assignatories";
require_once "../config/db.php";
require_once "../config/auth.php";
require_role("office");

$message = $_SESSION["flash_message"] ?? "";
unset($_SESSION["flash_message"]);
$error = "";
$editAssignatory = null;
$currentUserId = (int)($_SESSION["user_id"] ?? 0);

// Resolve DSA + SSC offices dynamically (no hardcoded ids in logic).
$dsaOfficeId = 0;
$sscOfficeId = 0;
$officeRes = $conn->query("SELECT office_id, office_name FROM offices WHERE status = 'active'");
if ($officeRes) {
    while ($o = $officeRes->fetch_assoc()) {
        $name = (string)($o["office_name"] ?? "");
        if ($dsaOfficeId <= 0 && (stripos($name, "Student Affairs") !== false || preg_match('/\(DSA\)/i', $name))) $dsaOfficeId = (int)$o["office_id"];
        if ($sscOfficeId <= 0 && stripos($name, "SSC President") !== false) $sscOfficeId = (int)$o["office_id"];
    }
    $officeRes->close();
}
if ($dsaOfficeId <= 0) $dsaOfficeId = 1;
if ($sscOfficeId <= 0) $sscOfficeId = 7;

// Only the assigned DSA user can manage SSC assignatories.
$isDsaUser = false;
if ($dsaOfficeId > 0 && $currentUserId > 0) {
    $chk = $conn->prepare("SELECT assignatory_id FROM office_assignatories WHERE office_id = ? AND user_id = ? AND status = 'active' LIMIT 1");
    if ($chk) {
        $chk->bind_param("ii", $dsaOfficeId, $currentUserId);
        $chk->execute();
        $isDsaUser = (bool)$chk->get_result()->fetch_assoc();
        $chk->close();
    }
}

if (!$isDsaUser) {
    require "_header.php";
    ?>
    <div class="page-heading">
        <div>
            <h1>SSC Assignatories</h1>
            <p>Manage the SSC President signatory assigned to DSA.</p>
        </div>
    </div>
    <div class="empty-panel">
        <i data-lucide="shield-alert"></i>
        <h3>Access restricted</h3>
        <p>Only the assigned Dean of Student Affairs (DSA) user can manage SSC assignatories. Please ask the administrator to assign you to the DSA office.</p>
    </div>
    <?php require "_footer.php"; exit();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    $assignatoryId = (int)($_POST["assignatory_id"] ?? 0);

    if ($action === "save") {
        $assignatoryName = trim($_POST["assignatory_name"] ?? "");
        $position = trim($_POST["position"] ?? "SSC President");
        $status = ($_POST["status"] ?? "active") === "inactive" ? "inactive" : "active";

        if ($assignatoryName === "" || $position === "") {
            $error = "Signatory name and position are required.";
        } else {
            $duplicateStmt = $conn->prepare("SELECT assignatory_id FROM office_assignatories WHERE assignatory_name = ? AND office_id = ? AND assigned_by_user_id = ? AND assignatory_id <> ? LIMIT 1");
            $duplicateStmt->bind_param("siii", $assignatoryName, $sscOfficeId, $currentUserId, $assignatoryId);
            $duplicateStmt->execute();
            $duplicateExists = (bool)$duplicateStmt->get_result()->fetch_assoc();
            $duplicateStmt->close();

            if ($duplicateExists) {
                $error = "This SSC signatory already exists.";
            } elseif ($assignatoryId > 0) {
                $stmt = $conn->prepare("UPDATE office_assignatories SET user_id = NULL, assignatory_name = ?, office_id = ?, position = ?, status = ? WHERE assignatory_id = ? AND assigned_by_user_id = ?");
                $stmt->bind_param("sissii", $assignatoryName, $sscOfficeId, $position, $status, $assignatoryId, $currentUserId);
                if ($stmt->execute() && $stmt->affected_rows >= 0) {
                    $_SESSION["flash_message"] = "Assignatory updated.";
                    header("Location: ssc_assignatories.php");
                    exit;
                } else $error = "Unable to save the assignatory.";
                $stmt->close();
            } else {
                $stmt = $conn->prepare("INSERT INTO office_assignatories (user_id, assignatory_name, assigned_by_user_id, office_id, position, status) VALUES (NULL, ?, ?, ?, ?, ?)");
                $stmt->bind_param("siiss", $assignatoryName, $currentUserId, $sscOfficeId, $position, $status);
                if ($stmt->execute()) {
                    $_SESSION["flash_message"] = "Assignatory added.";
                    header("Location: ssc_assignatories.php");
                    exit;
                } else $error = "Unable to add the assignatory.";
                $stmt->close();
            }
        }
    } elseif ($action === "toggle" && $assignatoryId > 0) {
        $newStatus = ($_POST["new_status"] ?? "") === "inactive" ? "inactive" : "active";
        $stmt = $conn->prepare("UPDATE office_assignatories SET status = ? WHERE assignatory_id = ? AND assigned_by_user_id = ? AND office_id = ?");
        $stmt->bind_param("siii", $newStatus, $assignatoryId, $currentUserId, $sscOfficeId);
        $stmt->execute();
        $stmt->close();
        $message = "Assignatory status updated.";
    } elseif ($action === "delete" && $assignatoryId > 0) {
        $stmt = $conn->prepare("DELETE FROM office_assignatories WHERE assignatory_id = ? AND assigned_by_user_id = ? AND office_id = ?");
        $stmt->bind_param("iii", $assignatoryId, $currentUserId, $sscOfficeId);
        $deleted = $stmt->execute() && $stmt->affected_rows > 0;
        $stmt->close();
        if ($deleted) $message = "Assignatory deleted."; else $error = "You cannot delete assignatories outside your SSC assignment.";
    }
}

if ($_SERVER["REQUEST_METHOD"] !== "POST" && isset($_GET["edit"])) {
    $assignatoryId = (int)$_GET["edit"];
    $stmt = $conn->prepare("SELECT oa.* FROM office_assignatories oa WHERE oa.assignatory_id = ? AND oa.assigned_by_user_id = ? AND oa.office_id = ? LIMIT 1");
    $stmt->bind_param("iii", $assignatoryId, $currentUserId, $sscOfficeId);
    $stmt->execute();
    $editAssignatory = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

$rows = $conn->prepare(
    "SELECT oa.assignatory_id, COALESCE(oa.assignatory_name, u.full_name) AS signatory_name, u.email, o.office_name, oa.position, oa.status
     FROM office_assignatories oa
     LEFT JOIN users u ON u.user_id = oa.user_id
     INNER JOIN offices o ON o.office_id = oa.office_id
     WHERE oa.assigned_by_user_id = ? AND oa.office_id = ?
     ORDER BY oa.assignatory_name"
);
$rows->bind_param("ii", $currentUserId, $sscOfficeId);
$rows->execute();
$rows = $rows->get_result();

require "_header.php";
?>
<div class="page-heading">
    <div>
        <h1>SSC Assignatories</h1>
        <p>Manage the SSC President signatory for DSA-assigned students.</p>
    </div>
    <a class="btn btn-primary" href="#assignatory-form" data-form-target="assignatory-form"><i data-lucide="plus"></i> Add Assignatory</a>
</div>

<?php if ($message !== ""): ?><div class="notice notice-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ""): ?><div class="notice notice-error"><?= e($error) ?></div><?php endif; ?>

<div class="toolbar-card"><div class="search-field"><i data-lucide="search"></i><input type="search" placeholder="Search SSC signatories..."></div></div>

<div class="form-card announcement-form floating-form <?= $editAssignatory ? "is-open" : "" ?>" id="assignatory-form">
    <div class="floating-form-header">
        <h2><?= $editAssignatory ? "Edit assignatory" : "Add assignatory" ?></h2>
        <button class="floating-form-close" type="button" aria-label="Close form"><i data-lucide="x"></i></button>
    </div>
    <form method="post">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="assignatory_id" value="<?= (int)($editAssignatory["assignatory_id"] ?? 0) ?>">
        <label>SSC President name<input name="assignatory_name" required value="<?= e($editAssignatory["assignatory_name"] ?? "") ?>" placeholder="e.g. SSC President name"></label>
        <label>Position<input name="position" required value="<?= e($editAssignatory["position"] ?? "SSC President") ?>" placeholder="e.g. SSC President"></label>
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
                            <a class="table-action" href="ssc_assignatories.php?edit=<?= (int)$row["assignatory_id"] ?>#assignatory-form">Edit</a>
                            <form method="post" data-delete-confirm="Delete this SSC assignatory?">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="assignatory_id" value="<?= (int)$row["assignatory_id"] ?>">
                                <button class="table-action table-action-danger" type="submit">Delete</button>
                            </form>
                            <form method="post" <?= $row["status"] === "active" ? "data-delete-confirm=\"Are you sure you want to deactivate this SSC assignatory?\" data-confirm-title=\"Are you sure you want to deactivate this SSC assignatory?\" data-confirm-action=\"Deactivate\"" : "data-delete-confirm=\"Are you sure you want to activate this SSC assignatory again?\" data-confirm-title=\"Are you sure you want to activate this SSC assignatory again?\" data-confirm-action=\"Activate\"" ?>>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="new_status" value="<?= $row["status"] === "active" ? "inactive" : "active" ?>">
                                <input type="hidden" name="assignatory_id" value="<?= (int)$row["assignatory_id"] ?>">
                                <button class="table-action" type="submit"><?= $row["status"] === "active" ? "Deactivate" : "Activate" ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="6" class="empty-state">No SSC assignatories found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require "_footer.php"; ?>
