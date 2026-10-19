<?php
$pageTitle = "School Terms";
$activePage = "school_terms";
require_once "../config/auth.php";
require_role("admin");
require_once "../config/db.php";

$message = $_SESSION["flash_message"] ?? "";
unset($_SESSION["flash_message"]);
$error = "";
$editRow = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    $tid = (int)($_POST["term_id"] ?? 0);
    if ($action === "save") {
        $sy = trim($_POST["school_year"] ?? "");
        $sem = trim($_POST["semester"] ?? "");
        $allowedSem = ["1st Semester", "2nd Semester", "Summer"];
        if (!in_array($sem, $allowedSem, true)) $sem = "";
        if (!preg_match('/^\d{4}-\d{4}$/', $sy)) {
            $error = "School year must look like 2025-2026.";
            $editRow = ["term_id" => $tid, "school_year" => $sy, "semester" => $sem];
        } elseif ($sem === "") {
            $error = "Please choose a semester.";
            $editRow = ["term_id" => $tid, "school_year" => $sy, "semester" => $sem];
        } else {
            $label = $sem . " S.Y. " . $sy;
            if ($tid > 0) {
                $stmt = $conn->prepare("UPDATE school_terms SET school_year=?, semester=?, term_label=? WHERE term_id=?");
                $stmt->bind_param("sssi", $sy, $sem, $label, $tid);
                if ($stmt->execute()) {
                    // keep clearance_term setting in sync if this is the current term
                    $c = $conn->prepare("SELECT is_current FROM school_terms WHERE term_id=?");
                    $c->bind_param("i", $tid); $c->execute();
                    $cr = $c->get_result()->fetch_assoc(); $c->close();
                    if (!empty($cr["is_current"])) {
                        $u = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('clearance_term', ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
                        $u->bind_param("s", $label); $u->execute(); $u->close();
                    }
                    $_SESSION["flash_message"] = "School term updated."; header("Location: school_terms.php"); exit;
                }
                $error = "Unable to update (duplicate school year + semester?).";
                $stmt->close();
            } else {
                $stmt = $conn->prepare("INSERT INTO school_terms (school_year, semester, term_label, status) VALUES (?, ?, ?, 'active')");
                $stmt->bind_param("sss", $sy, $sem, $label);
                if ($stmt->execute()) { $_SESSION["flash_message"] = "School term added."; header("Location: school_terms.php"); exit; }
                $error = "Unable to add (duplicate school year + semester?).";
                $stmt->close();
            }
        }
    } elseif ($action === "set_current" && $tid > 0) {
        $conn->query("UPDATE school_terms SET is_current=0");
        $stmt = $conn->prepare("UPDATE school_terms SET is_current=1, status='active' WHERE term_id=?");
        $stmt->bind_param("i", $tid); $stmt->execute(); $stmt->close();
        $r = $conn->prepare("SELECT term_label FROM school_terms WHERE term_id=? LIMIT 1");
        $r->bind_param("i", $tid); $r->execute();
        $row = $r->get_result()->fetch_assoc(); $r->close();
        if ($row) {
            $u = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('clearance_term', ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
            $u->bind_param("s", $row["term_label"]); $u->execute(); $u->close();
        }
        $_SESSION["flash_message"] = "Current term updated. New requests now use this term.";
        header("Location: school_terms.php"); exit;
    } elseif ($action === "toggle" && $tid > 0) {
        $stmt = $conn->prepare("UPDATE school_terms SET status=IF(status='active','inactive','active') WHERE term_id=?");
        $stmt->bind_param("i", $tid); $stmt->execute(); $stmt->close();
        $_SESSION["flash_message"] = "Term status updated.";
        header("Location: school_terms.php"); exit;
    } elseif ($action === "delete" && $tid > 0) {
        $c = $conn->query("SELECT is_current FROM school_terms WHERE term_id=" . $tid);
        $isCur = $c ? (int)($c->fetch_assoc()["is_current"] ?? 0) : 0;
        if ($c) $c->close();
        if ($isCur) { $error = "Cannot delete the current term. Set another term as current first."; }
        else {
            $stmt = $conn->prepare("DELETE FROM school_terms WHERE term_id=?");
            $stmt->bind_param("i", $tid); $stmt->execute(); $stmt->close();
            $_SESSION["flash_message"] = "Term deleted.";
            header("Location: school_terms.php"); exit;
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] !== "POST" && isset($_GET["edit"])) {
    $tid = (int)$_GET["edit"];
    $stmt = $conn->prepare("SELECT * FROM school_terms WHERE term_id=?");
    $stmt->bind_param("i", $tid); $stmt->execute();
    $editRow = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

$terms = $conn->query("SELECT * FROM school_terms ORDER BY is_current DESC, school_year DESC, FIELD(semester,'1st Semester','2nd Semester','Summer')");
require "_header.php";
?>
<div class="page-heading">
    <div><h1>School Terms</h1><p>Semester + school year master data. The <strong>current</strong> term is used for every new clearance request.</p></div>
    <a class="btn btn-primary" href="#term-form" data-form-target="term-form"><i data-lucide="plus"></i> Add term</a>
</div>
<?php if ($message !== ""): ?><div class="notice notice-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ""): ?><div class="notice notice-error"><?= e($error) ?></div><?php endif; ?>

<div class="form-card announcement-form floating-form <?= $editRow ? "is-open" : "" ?>" id="term-form">
    <div class="floating-form-header"><h2><?= $editRow ? "Edit term" : "Add term" ?></h2><button class="floating-form-close" type="button" aria-label="Close form"><i data-lucide="x"></i></button></div>
    <form method="post">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="term_id" value="<?= (int)($editRow["term_id"] ?? 0) ?>">
        <label>School year (YYYY-YYYY)<input name="school_year" required pattern="\d{4}-\d{4}" placeholder="2025-2026" value="<?= e($editRow["school_year"] ?? "") ?>"></label>
        <label>Semester<select name="semester" required><option value="">Select semester</option><?php foreach (["1st Semester","2nd Semester","Summer"] as $s): ?><option value="<?= e($s) ?>" <?= ($editRow["semester"] ?? "") === $s ? "selected" : "" ?>><?= e($s) ?></option><?php endforeach; ?></select></label>
        <button class="btn btn-primary" type="submit"><i data-lucide="save"></i><?= $editRow ? "Save changes" : "Add term" ?></button>
    </form>
</div>

<div class="table-card"><div class="table-wrapper"><table>
<thead><tr><th>Term</th><th>School Year</th><th>Semester</th><th>Current</th><th>Status</th><th>Action</th></tr></thead>
<tbody>
<?php if ($terms && $terms->num_rows): while ($t = $terms->fetch_assoc()): ?>
<tr>
    <td><strong><?= e($t["term_label"]) ?></strong></td>
    <td><?= e($t["school_year"]) ?></td>
    <td><?= e($t["semester"]) ?></td>
    <td><?= !empty($t["is_current"]) ? '<span class="status status-cleared">Current</span>' : '<span class="muted">—</span>' ?></td>
    <td><span class="status status-<?= e($t["status"]) ?>"><?= e(ucfirst($t["status"])) ?></span></td>
    <td class="action-group">
        <?php if (empty($t["is_current"])): ?>
        <form method="post"><input type="hidden" name="action" value="set_current"><input type="hidden" name="term_id" value="<?= (int)$t["term_id"] ?>"><button class="table-action" type="submit">Set current</button></form>
        <?php endif; ?>
        <a class="table-action" href="school_terms.php?edit=<?= (int)$t["term_id"] ?>#term-form">Edit</a>
        <form method="post"><input type="hidden" name="action" value="toggle"><input type="hidden" name="term_id" value="<?= (int)$t["term_id"] ?>"><button class="table-action" type="submit"><?= $t["status"] === "active" ? "Deactivate" : "Activate" ?></button></form>
        <?php if (empty($t["is_current"])): ?>
        <form method="post" data-delete-confirm="Delete this term?"><input type="hidden" name="action" value="delete"><input type="hidden" name="term_id" value="<?= (int)$t["term_id"] ?>"><button class="table-action table-action-danger" type="submit">Delete</button></form>
        <?php endif; ?>
    </td>
</tr>
<?php endwhile; else: ?>
<tr><td colspan="6" class="empty-state">No school terms yet.</td></tr>
<?php endif; ?>
</tbody></table></div></div>
<?php require "_footer.php"; ?>
