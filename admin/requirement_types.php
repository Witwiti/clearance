<?php
$pageTitle = "Requirement Types";
$activePage = "requirement_types";
require_once "../config/auth.php";
require_role("admin");
require_once "../config/db.php";

$message = $_SESSION["flash_message"] ?? "";
unset($_SESSION["flash_message"]);
$error = "";
$editRow = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    $rid = (int)($_POST["requirement_id"] ?? 0);
    if ($action === "save") {
        $officeId = (int)($_POST["office_id"] ?? 0);
        $name = trim($_POST["requirement_name"] ?? "");
        $desc = trim($_POST["description"] ?? "");
        $isReq = isset($_POST["is_required"]) ? 1 : 0;
        $status = ($_POST["status"] ?? "active") === "inactive" ? "inactive" : "active";
        if ($officeId <= 0 || $name === "") {
            $error = "Office and requirement name are required.";
            $editRow = ["requirement_id" => $rid, "office_id" => $officeId, "requirement_name" => $name, "description" => $desc, "is_required" => $isReq, "status" => $status];
        } elseif ($rid > 0) {
            $stmt = $conn->prepare("UPDATE requirement_types SET office_id=?, requirement_name=?, description=?, is_required=?, status=? WHERE requirement_id=?");
            $stmt->bind_param("issisi", $officeId, $name, $desc, $isReq, $status, $rid);
            if ($stmt->execute()) { $_SESSION["flash_message"] = "Requirement type updated."; header("Location: requirement_types.php"); exit; }
            $error = "Unable to update requirement type.";
            $stmt->close();
        } else {
            $stmt = $conn->prepare("INSERT INTO requirement_types (office_id, requirement_name, description, is_required, status) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("issis", $officeId, $name, $desc, $isReq, $status);
            if ($stmt->execute()) { $_SESSION["flash_message"] = "Requirement type added."; header("Location: requirement_types.php"); exit; }
            $error = "Unable to add requirement type.";
            $stmt->close();
        }
    } elseif ($action === "toggle" && $rid > 0) {
        $stmt = $conn->prepare("UPDATE requirement_types SET status=IF(status='active','inactive','active') WHERE requirement_id=?");
        $stmt->bind_param("i", $rid);
        $stmt->execute(); $stmt->close();
        $_SESSION["flash_message"] = "Requirement type status updated.";
        header("Location: requirement_types.php"); exit;
    } elseif ($action === "delete" && $rid > 0) {
        $stmt = $conn->prepare("DELETE FROM requirement_types WHERE requirement_id=?");
        $stmt->bind_param("i", $rid);
        $stmt->execute(); $stmt->close();
        $_SESSION["flash_message"] = "Requirement type deleted.";
        header("Location: requirement_types.php"); exit;
    } elseif ($action === "publish_template") {
        // Publish: public → all active requests (+ future auto-attach); custom → chosen students. Always notifies.
        $scope = ($_POST["scope"] ?? "custom") === "public" ? "public" : "custom";
        $targetIds = [];
        foreach (($_POST["target_students"] ?? []) as $tid) {
            $tid = (int)$tid;
            if ($tid > 0) $targetIds[$tid] = true;
        }
        $targetIds = array_keys($targetIds);
        if ($rid <= 0) {
            $error = "Invalid requirement.";
        } elseif ($scope === "custom" && !$targetIds) {
            $error = "Choose at least one student for custom publish.";
        } elseif (!function_exists("publish_requirement_template")) {
            $error = "Publish helper missing.";
        } else {
            $res = publish_requirement_template($conn, $rid, $scope, $targetIds);
            if ($res === false) {
                $error = "Only active requirement types can be published.";
            } else {
                $_SESSION["flash_message"] = $res["requests"] > 0
                    ? "Published to {$res["requests"]} request(s) — {$res["published"]} item(s) added (duplicates skipped)" . ($res["removed_stale"] > 0 ? ", {$res["removed_stale"]} stale removed" : "") . ". {$res["notified"]} student(s) notified."
                    : "Scope saved. {$res["notified"]} student(s) notified (no matching active requests yet).";
                header("Location: requirement_types.php"); exit;
            }
        }
    } elseif ($action === "unpublish_template") {
        if ($rid > 0 && function_exists("unpublish_requirement_template")) {
            $res = unpublish_requirement_template($conn, $rid);
            $_SESSION["flash_message"] = "Unpublished — {$res["removed"]} item(s) removed from requests. {$res["notified"]} student(s) notified.";
            header("Location: requirement_types.php"); exit;
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] !== "POST" && isset($_GET["edit"])) {
    $rid = (int)$_GET["edit"];
    $stmt = $conn->prepare("SELECT * FROM requirement_types WHERE requirement_id=?");
    $stmt->bind_param("i", $rid);
    $stmt->execute();
    $editRow = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

$offices = $conn->query("SELECT office_id, office_name FROM offices ORDER BY office_name");
$officeMap = [];
if ($offices) { while ($o = $offices->fetch_assoc()) $officeMap[(int)$o["office_id"]] = $o["office_name"]; $offices->data_seek(0); }

$filterOffice = (int)($_GET["office"] ?? 0);
if ($filterOffice > 0) {
    $stmt = $conn->prepare("SELECT rt.*, o.office_name, (SELECT COUNT(*) FROM requirement_targets t WHERE t.requirement_id = rt.requirement_id) AS target_count, (SELECT COUNT(*) FROM clearance_items ci WHERE ci.source_requirement_id = rt.requirement_id) AS published_count FROM requirement_types rt LEFT JOIN offices o ON o.office_id=rt.office_id WHERE rt.office_id=? ORDER BY o.office_name, rt.requirement_name");
    $stmt->bind_param("i", $filterOffice);
    $stmt->execute();
    $rows = $stmt->get_result();
    $stmt->close();
} else {
    $rows = $conn->query("SELECT rt.*, o.office_name, (SELECT COUNT(*) FROM requirement_targets t WHERE t.requirement_id = rt.requirement_id) AS target_count, (SELECT COUNT(*) FROM clearance_items ci WHERE ci.source_requirement_id = rt.requirement_id) AS published_count FROM requirement_types rt LEFT JOIN offices o ON o.office_id=rt.office_id ORDER BY o.office_name, rt.requirement_name");
}

// Publish panel data (public = all students, custom = chosen students). Publishing / unpublishing notifies students.
$publishTargets = [];
$ptgRes = $conn->query("SELECT requirement_id, student_id FROM requirement_targets");
if ($ptgRes) {
    while ($ptg = $ptgRes->fetch_assoc()) $publishTargets[(int)$ptg["requirement_id"]][] = (int)$ptg["student_id"];
    $ptgRes->close();
}
$publishStudents = [];
$psRes = $conn->query("SELECT student_id, student_number, CONCAT(first_name, ' ', last_name) AS full_name FROM students WHERE status = 'active' ORDER BY student_number ASC");
if ($psRes) {
    while ($ps = $psRes->fetch_assoc()) $publishStudents[] = $ps;
    $psRes->close();
}
$publishOptions = [];
$poRes = $conn->query("SELECT rt.requirement_id, rt.requirement_name, rt.audience, o.office_name FROM requirement_types rt LEFT JOIN offices o ON o.office_id = rt.office_id WHERE rt.status = 'active' ORDER BY o.office_name, rt.requirement_name");
if ($poRes) {
    while ($po = $poRes->fetch_assoc()) $publishOptions[] = $po;
    $poRes->close();
}

require "_header.php";
?>
<div class="page-heading">
    <div><h1>Requirement Types</h1><p>Master list of reusable office requirements. Publish them from the Clearance page.</p></div>
    <a class="btn btn-primary" href="#reqtype-form" data-form-target="reqtype-form"><i data-lucide="plus"></i> Add requirement type</a>
</div>
<?php if ($message !== ""): ?><div class="notice notice-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ""): ?><div class="notice notice-error"><?= e($error) ?></div><?php endif; ?>

<div class="toolbar-card filter-row">
    <div class="search-field"><i data-lucide="search"></i><input type="search" placeholder="Search requirement types..."></div>
    <form method="get"><select name="office" onchange="this.form.submit()"><option value="0">All offices</option><?php foreach ($officeMap as $oid => $oname): ?><option value="<?= (int)$oid ?>" <?= $filterOffice === (int)$oid ? "selected" : "" ?>><?= e($oname) ?></option><?php endforeach; ?></select></form>
</div>

<div class="form-card announcement-form floating-form <?= $editRow ? "is-open" : "" ?>" id="reqtype-form">
    <div class="floating-form-header"><h2><?= $editRow ? "Edit requirement type" : "Add requirement type" ?></h2><button class="floating-form-close" type="button" aria-label="Close form"><i data-lucide="x"></i></button></div>
    <form method="post">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="requirement_id" value="<?= (int)($editRow["requirement_id"] ?? 0) ?>">
        <label>Office<select name="office_id" required><option value="">Select office</option><?php if ($offices): $offices->data_seek(0); while ($o = $offices->fetch_assoc()): ?><option value="<?= (int)$o["office_id"] ?>" <?= (int)($editRow["office_id"] ?? 0) === (int)$o["office_id"] ? "selected" : "" ?>><?= e($o["office_name"]) ?></option><?php endwhile; endif; ?></select></label>
        <label>Requirement name<input name="requirement_name" required value="<?= e($editRow["requirement_name"] ?? "") ?>" placeholder="e.g. Library clearance slip"></label>
        <label>Description<textarea name="description" rows="3" placeholder="Optional details"><?= e($editRow["description"] ?? "") ?></textarea></label>
        <label class="checkbox-field"><input type="checkbox" name="is_required" value="1" <?= empty($editRow) || !empty($editRow["is_required"]) ? "checked" : "" ?>> Required by default</label>
        <label>Status<select name="status"><option value="active" <?= ($editRow["status"] ?? "active") === "active" ? "selected" : "" ?>>Active</option><option value="inactive" <?= ($editRow["status"] ?? "") === "inactive" ? "selected" : "" ?>>Inactive</option></select></label>
        <button class="btn btn-primary" type="submit"><i data-lucide="save"></i><?= $editRow ? "Save changes" : "Add requirement type" ?></button>
    </form>
</div>

<div class="form-card announcement-form floating-form" id="publish-form">
    <div class="floating-form-header">
        <h2>Publish / unpublish requirement</h2>
        <button class="floating-form-close" type="button" aria-label="Close form"><i data-lucide="x"></i></button>
    </div>
    <form method="post">
        <input type="hidden" name="action" value="publish_template">
        <label>Requirement
            <select name="requirement_id" id="publishRequirement" required>
                <option value="">Select requirement</option>
                <?php foreach ($publishOptions as $pt): ?>
                    <option value="<?= (int)$pt["requirement_id"] ?>" data-audience="<?= e($pt["audience"] ?? "custom") ?>" data-targets="<?= e(implode(",", $publishTargets[(int)$pt["requirement_id"]] ?? [])) ?>"><?= e(($pt["office_name"] ?: "—") . " — " . $pt["requirement_name"]) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="checkbox-field"><input type="radio" name="scope" value="public" id="scopePublic"> Public — all students</label>
        <label class="checkbox-field"><input type="radio" name="scope" value="custom" id="scopeCustom" checked> Custom — chosen students</label>
        <label>Custom students (Ctrl/Cmd+click for multiple)
            <select name="target_students[]" multiple size="8">
                <?php foreach ($publishStudents as $ps): ?>
                    <option value="<?= (int)$ps["student_id"] ?>"><?= e($ps["student_number"] . " — " . $ps["full_name"]) ?></option>
                <?php endforeach; ?>
            </select>
            <small class="field-help">Only used for custom scope. Publishing notifies the affected students; unpublishing notifies them too.</small>
        </label>
        <button class="btn btn-primary" type="submit"><i data-lucide="send"></i>Publish</button>
    </form>
</div>

<script>
(function () {
    const reqSel = document.getElementById("publishRequirement");
    const scopePublic = document.getElementById("scopePublic");
    const scopeCustom = document.getElementById("scopeCustom");
    if (!reqSel || !scopePublic || !scopeCustom) return;
    const syncScope = () => {
        const opt = reqSel.options[reqSel.selectedIndex];
        if (!opt || !opt.value) return;
        const isPublic = (opt.dataset.audience || "custom") === "public";
        scopePublic.checked = isPublic;
        scopeCustom.checked = !isPublic;
        const targets = (opt.dataset.targets || "").split(",").filter(Boolean);
        const studentSel = reqSel.closest("form")?.querySelector('select[name="target_students[]"]');
        if (studentSel) {
            [...studentSel.options].forEach((o) => { o.selected = targets.includes(o.value); });
        }
    };
    reqSel.addEventListener("change", syncScope);
})();
</script>

<div class="table-card"><div class="table-wrapper"><table>
<thead><tr><th>Office</th><th>Requirement</th><th>Required</th><th>Status</th><th>Scope</th><th>Publish state</th><th>Action</th></tr></thead>
<tbody>
<?php if ($rows && $rows->num_rows): while ($r = $rows->fetch_assoc()): $rId = (int)$r["requirement_id"]; $rPublished = (int)($r["published_count"] ?? 0) > 0; $rTargets = $publishTargets[$rId] ?? []; ?>
<tr>
    <td><strong><?= e($r["office_name"] ?: "—") ?></strong></td>
    <td><?= e($r["requirement_name"]) ?><small><?= e($r["description"] ?: "No description") ?></small></td>
    <td><?= !empty($r["is_required"]) ? "Yes" : "No" ?></td>
    <td><span class="status status-<?= e($r["status"]) ?>"><?= e(ucfirst($r["status"])) ?></span></td>
    <td><?= ($r["audience"] ?? "custom") === "public" ? "Public" : "Custom" . ($rTargets ? " (" . count($rTargets) . ")" : "") ?></td>
    <td><?php if ($rPublished): ?><span class="status status-approved">Published</span><?php else: ?><span class="status status-pending">Not published</span><?php endif; ?></td>
    <td class="action-group">
        <a class="table-action" href="requirement_types.php?edit=<?= (int)$r["requirement_id"] ?>#reqtype-form">Edit</a>
        <?php if ($rPublished): ?>
        <form method="post" data-delete-confirm="Unpublish <?= e($r["requirement_name"]) ?>? Removes its items from all requests and notifies students." data-confirm-title="Unpublish requirement?" data-confirm-action="Unpublish">
            <input type="hidden" name="action" value="unpublish_template">
            <input type="hidden" name="requirement_id" value="<?= $rId ?>">
            <button class="table-action" type="submit">Unpublish</button>
        </form>
        <?php endif; ?>
        <form method="post"><input type="hidden" name="action" value="toggle"><input type="hidden" name="requirement_id" value="<?= (int)$r["requirement_id"] ?>"><button class="table-action" type="submit"><?= $r["status"] === "active" ? "Deactivate" : "Activate" ?></button></form>
        <form method="post" data-delete-confirm="Delete this requirement type?"><input type="hidden" name="action" value="delete"><input type="hidden" name="requirement_id" value="<?= (int)$r["requirement_id"] ?>"><button class="table-action table-action-danger" type="submit">Delete</button></form>
    </td>
</tr>
<?php endwhile; else: ?>
<tr><td colspan="7" class="empty-state">No requirement types yet. Add the standard requirements per office once, then publish them (Public or Custom).</td></tr>
<?php endif; ?>
</tbody></table></div></div>
<?php require "_footer.php"; ?>
