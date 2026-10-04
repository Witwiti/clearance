<?php
$pageTitle = "Offices";
$activePage = "offices";
require_once "../config/auth.php";
require_role("admin");
require_once "../config/db.php";

$message = $_SESSION["flash_message"] ?? "";
unset($_SESSION["flash_message"]);
$error = "";
$editOffice = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    $officeId = (int)($_POST["office_id"] ?? 0);

    if ($action === "save") {
        $officeName = trim($_POST["office_name"] ?? "");
        $description = trim($_POST["description"] ?? "");
        $status = ($_POST["status"] ?? "active") === "inactive" ? "inactive" : "active";
        $editOffice = compact("officeId", "officeName", "description", "status");

        if ($officeName === "") {
            $error = "Office name is required.";
        } elseif ($officeId > 0) {
            $stmt = $conn->prepare("UPDATE offices SET office_name = ?, description = ?, status = ? WHERE office_id = ?");
            $stmt->bind_param("sssi", $officeName, $description, $status, $officeId);
            if ($stmt->execute()) {
                $_SESSION["flash_message"] = "Office updated.";
                header("Location: offices.php");
                exit;
            }
            else $error = "Unable to save the office.";
            $stmt->close();
        } else {
            $stmt = $conn->prepare("INSERT INTO offices (office_name, description, status) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $officeName, $description, $status);
            if ($stmt->execute()) {
                $_SESSION["flash_message"] = "Office added.";
                header("Location: offices.php");
                exit;
            }
            else $error = "Unable to add the office.";
            $stmt->close();
        }
        if ($message !== "" && $officeId <= 0) $editOffice = null;
    } elseif ($action === "toggle" && $officeId > 0) {
        $stmt = $conn->prepare("UPDATE offices SET status = IF(status = 'active', 'inactive', 'active') WHERE office_id = ?");
        $stmt->bind_param("i", $officeId);
        $stmt->execute();
        $stmt->close();
        $message = "Office status updated.";
    } elseif ($action === "delete" && $officeId > 0) {
        $stmt = $conn->prepare("DELETE FROM offices WHERE office_id = ?");
        $stmt->bind_param("i", $officeId);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $message = "Office deleted.";
        } else {
            $error = "Unable to delete the office.";
        }
        $stmt->close();
    }
}

if ($_SERVER["REQUEST_METHOD"] !== "POST" && isset($_GET["edit"])) {
    $officeId = (int)$_GET["edit"];
    $stmt = $conn->prepare("SELECT * FROM offices WHERE office_id = ?");
    $stmt->bind_param("i", $officeId);
    $stmt->execute();
    $editOffice = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

$offices = $conn->query(
    "SELECT office_id, office_name, description, status, created_at
     FROM offices ORDER BY office_name ASC"
);

require "_header.php";
?>
<div class="page-heading">
    <div>
        <h1>Offices</h1>
        <p>Manage the offices included in the clearance process.</p>
    </div>
    <a class="btn btn-primary" href="#office-form" data-form-target="office-form"><i data-lucide="plus"></i> Add Office</a>
</div>

<?php if ($message !== ""): ?><div class="notice notice-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ""): ?><div class="notice notice-error"><?= e($error) ?></div><?php endif; ?>

<div class="toolbar-card"><div class="search-field"><i data-lucide="search"></i><input type="search" placeholder="Search offices..."></div></div>

<div class="form-card announcement-form floating-form <?= $editOffice ? "is-open" : "" ?>" id="office-form">
    <div class="floating-form-header">
        <h2><?= $editOffice ? "Edit office" : "Add office" ?></h2>
        <button class="floating-form-close" type="button" aria-label="Close form"><i data-lucide="x"></i></button>
    </div>
    <form method="post">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="office_id" value="<?= (int)($editOffice["office_id"] ?? $editOffice["officeId"] ?? 0) ?>">
        <label>Office name<input name="office_name" required value="<?= e($editOffice["office_name"] ?? $editOffice["officeName"] ?? "") ?>"></label>
        <label>Description<textarea name="description" rows="3"><?= e($editOffice["description"] ?? "") ?></textarea></label>
        <label>Status<select name="status"><option value="active" <?= ($editOffice["status"] ?? "active") === "active" ? "selected" : "" ?>>Active</option><option value="inactive" <?= ($editOffice["status"] ?? "active") === "inactive" ? "selected" : "" ?>>Inactive</option></select></label>
        <button class="btn btn-primary" type="submit"><i data-lucide="save"></i><?= $editOffice ? "Save changes" : "Add office" ?></button>
    </form>
</div>

<div class="table-card">
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Office</th><th>Description</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php if ($offices && $offices->num_rows): ?>
                <?php while ($row = $offices->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?= e($row["office_name"]) ?></strong></td>
                        <td><?= e($row["description"] ?: "—") ?></td>
                        <td><span class="status status-<?= e($row["status"]) ?>"><?= e(ucfirst($row["status"])) ?></span></td>
                        <td class="action-group">
                            <a class="table-action" href="offices.php?edit=<?= (int)$row["office_id"] ?>#office-form">Edit</a>
                            <form method="post">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="office_id" value="<?= (int)$row["office_id"] ?>">
                                <button class="table-action" type="submit"><?= $row["status"] === "active" ? "Deactivate" : "Activate" ?></button>
                            </form>
                            <form method="post" data-delete-confirm="Delete <?= e($row["office_name"]) ?>? This will remove it from student clearance records.">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="office_id" value="<?= (int)$row["office_id"] ?>">
                                <button class="table-action" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="4" class="empty-state">No offices found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require "_footer.php"; ?>
