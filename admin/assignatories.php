<?php
$pageTitle = "Assignatories";
$activePage = "assignatories";
require_once "../config/auth.php";
require_role("admin");
require_once "../config/db.php";

$message = $_SESSION["flash_message"] ?? "";
unset($_SESSION["flash_message"]);
$error = "";
$editAssignatory = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    $assignatoryId = (int)($_POST["assignatory_id"] ?? 0);

    if ($action === "save") {
        $userId = (int)($_POST["user_id"] ?? 0);
        $assignatoryName = trim($_POST["assignatory_name"] ?? "");
        $officeId = (int)($_POST["office_id"] ?? 0);
        $position = trim($_POST["position"] ?? "Office Assignatory");
        $status = ($_POST["status"] ?? "active") === "inactive" ? "inactive" : "active";

        if (($userId <= 0 && $assignatoryName === "") || $officeId <= 0 || $position === "") {
            $error = "Signatory name or user, office, and position are required.";
        } elseif ($assignatoryId > 0) {
            if ($userId > 0) {
                $stmt = $conn->prepare("UPDATE office_assignatories SET user_id = ?, assignatory_name = NULL, office_id = ?, position = ?, status = ? WHERE assignatory_id = ?");
                $stmt->bind_param("iissi", $userId, $officeId, $position, $status, $assignatoryId);
            } else {
                $stmt = $conn->prepare("UPDATE office_assignatories SET user_id = NULL, assignatory_name = ?, office_id = ?, position = ?, status = ? WHERE assignatory_id = ?");
                $stmt->bind_param("sissi", $assignatoryName, $officeId, $position, $status, $assignatoryId);
            }
            if ($stmt->execute()) {
                $editAssignatory = compact("assignatoryId", "userId", "officeId", "position", "status");
                $editAssignatory["assignatory_id"] = $assignatoryId;
                $editAssignatory["user_id"] = $userId;
                $editAssignatory["office_id"] = $officeId;
                $_SESSION["flash_message"] = "Assignatory updated.";
                header("Location: assignatories.php");
                exit;
            }
            else $error = "Unable to save the assignatory.";
            $stmt->close();
        } else {
            if ($userId > 0) {
                $stmt = $conn->prepare("INSERT INTO office_assignatories (user_id, office_id, position, status) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("iiss", $userId, $officeId, $position, $status);
            } else {
                $stmt = $conn->prepare("INSERT INTO office_assignatories (user_id, assignatory_name, office_id, position, status) VALUES (NULL, ?, ?, ?, ?)");
                $stmt->bind_param("siss", $assignatoryName, $officeId, $position, $status);
            }
            if ($stmt->execute()) {
                $_SESSION["flash_message"] = "Assignatory added.";
                header("Location: assignatories.php");
                exit;
            }
            else $error = "Unable to add the assignatory.";
            $stmt->close();
        }
    } elseif ($action === "toggle" && $assignatoryId > 0) {
        $stmt = $conn->prepare("UPDATE office_assignatories SET status = IF(status = 'active', 'inactive', 'active') WHERE assignatory_id = ?");
        $stmt->bind_param("i", $assignatoryId);
        $stmt->execute();
        $stmt->close();
        $message = "Assignatory status updated.";
    }
}

if ($_SERVER["REQUEST_METHOD"] !== "POST" && isset($_GET["edit"])) {
    $assignatoryId = (int)$_GET["edit"];
    $stmt = $conn->prepare("SELECT * FROM office_assignatories WHERE assignatory_id = ?");
    $stmt->bind_param("i", $assignatoryId);
    $stmt->execute();
    $editAssignatory = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

$officeOptions = $conn->query("SELECT office_id, office_name FROM offices ORDER BY office_name");
$userOptions = $conn->query("SELECT user_id, full_name, email FROM users WHERE role = 'office' ORDER BY full_name");

$rows = $conn->query(
    "SELECT oa.assignatory_id, COALESCE(oa.assignatory_name, u.full_name) AS signatory_name, u.email,
            o.office_name, oa.position, oa.status
     FROM office_assignatories oa
     LEFT JOIN users u ON u.user_id = oa.user_id
     INNER JOIN offices o ON o.office_id = oa.office_id
     ORDER BY o.office_name, u.full_name"
);

require "_header.php";
?>
<div class="page-heading">
    <div>
        <h1>Office Assignatories</h1>
        <p>Manage users responsible for approving student clearances.</p>
    </div>
    <a class="btn btn-primary" href="#assignatory-form" data-form-target="assignatory-form"><i data-lucide="plus"></i> Add Assignatory</a>
</div>

<?php if ($message !== ""): ?><div class="notice notice-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ""): ?><div class="notice notice-error"><?= e($error) ?></div><?php endif; ?>

<div class="toolbar-card"><div class="search-field"><i data-lucide="search"></i><input type="search" placeholder="Search assignatories..."></div></div>

<div class="form-card announcement-form floating-form <?= $editAssignatory ? "is-open" : "" ?>" id="assignatory-form">
    <div class="floating-form-header">
        <h2><?= $editAssignatory ? "Edit assignatory" : "Add assignatory" ?></h2>
        <button class="floating-form-close" type="button" aria-label="Close form"><i data-lucide="x"></i></button>
    </div>
    <form method="post">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="assignatory_id" value="<?= (int)($editAssignatory["assignatory_id"] ?? 0) ?>">
        <label>User<select name="user_id"><option value="">No linked user account</option><?php if ($userOptions): while ($user = $userOptions->fetch_assoc()): ?><option value="<?= (int)$user["user_id"] ?>" <?= (int)($editAssignatory["user_id"] ?? 0) === (int)$user["user_id"] ? "selected" : "" ?>><?= e($user["full_name"] . " - " . $user["email"]) ?></option><?php endwhile; endif; ?></select></label>
        <label>Signatory name<input name="assignatory_name" value="<?= e($editAssignatory["assignatory_name"] ?? "") ?>" placeholder="Required when no user is selected"></label>
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
                            <form method="post">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="assignatory_id" value="<?= (int)$row["assignatory_id"] ?>">
                                <button class="table-action" type="submit"><?= $row["status"] === "active" ? "Deactivate" : "Activate" ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="6" class="empty-state">No assignatories found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require "_footer.php"; ?>
