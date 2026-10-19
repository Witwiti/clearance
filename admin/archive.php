<?php
$pageTitle = "Archive";
$activePage = "archive";
require_once "../config/auth.php";
require_role("admin");
require_once "../config/db.php";

$message = $_SESSION["flash_message"] ?? "";
unset($_SESSION["flash_message"]);
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    $cid = (int)($_POST["clearance_id"] ?? 0);
    if ($cid > 0 && $action === "restore") {
        $stmt = $conn->prepare("UPDATE clearance_requests SET is_archived=0, archived_at=NULL WHERE clearance_id=?");
        $stmt->bind_param("i", $cid); $stmt->execute(); $stmt->close();
        $conn->query("DELETE FROM clearance_archives WHERE clearance_id=" . $cid);
        $_SESSION["flash_message"] = "Clearance restored to active list.";
        header("Location: archive.php"); exit;
    } elseif ($cid > 0 && $action === "archive") {
        $info = $conn->prepare("SELECT student_id, school_year, semester, overall_status FROM clearance_requests WHERE clearance_id=? LIMIT 1");
        $info->bind_param("i", $cid); $info->execute();
        $ir = $info->get_result()->fetch_assoc(); $info->close();
        if ($ir) {
            $conn->query("INSERT IGNORE INTO clearance_archives (clearance_id, student_id, school_year, semester, overall_status, archived_by) VALUES (" . $cid . "," . (int)$ir["student_id"] . ",'" . $conn->real_escape_string($ir["school_year"]) . "','" . $conn->real_escape_string($ir["semester"]) . "','" . $conn->real_escape_string($ir["overall_status"]) . "'," . (int)$_SESSION["user_id"] . ")");
            $up = $conn->prepare("UPDATE clearance_requests SET is_archived=1, archived_at=NOW() WHERE clearance_id=?");
            $up->bind_param("i", $cid); $up->execute(); $up->close();
            $_SESSION["flash_message"] = "Clearance archived.";
        }
        header("Location: " . ($action === "archive" && isset($_GET["from"]) ? "clearance.php" : "archive.php")); exit;
    }
}

$statusFilter = $_GET["status"] ?? "all";
$allowed = ["all", "cleared", "not_cleared", "pending"];
if (!in_array($statusFilter, $allowed, true)) $statusFilter = "all";

$sql = "SELECT cr.clearance_id, s.student_number, CONCAT(s.first_name,' ',s.last_name) AS student_name, s.course,
               cr.school_year, cr.semester, cr.overall_status, cr.requested_at, cr.archived_at
        FROM clearance_requests cr INNER JOIN students s ON s.student_id=cr.student_id
        WHERE cr.is_archived=1";
if ($statusFilter !== "all") $sql .= " AND cr.overall_status='" . $conn->real_escape_string($statusFilter) . "'";
$sql .= " ORDER BY cr.archived_at DESC, cr.requested_at DESC";
$rows = $conn->query($sql);

$counts = ["total" => 0, "cleared" => 0, "not_cleared" => 0, "pending" => 0];
$c = $conn->query("SELECT COUNT(*) t, SUM(overall_status='cleared') cl, SUM(overall_status='not_cleared') nc, SUM(overall_status='pending') p FROM clearance_requests WHERE is_archived=1");
if ($c) { $r = $c->fetch_assoc(); $counts = ["total" => (int)($r["t"] ?? 0), "cleared" => (int)($r["cl"] ?? 0), "not_cleared" => (int)($r["nc"] ?? 0), "pending" => (int)($r["p"] ?? 0)]; $c->close(); }

require "_header.php";
?>
<div class="page-heading">
    <div><h1>Archive</h1><p>Finished and filed clearance records. <?= (int)$counts["total"] ?> archived (<?= (int)$counts["cleared"] ?> cleared).</p></div>
    <a class="btn btn-secondary" href="clearance.php"><i data-lucide="arrow-left"></i> Back to clearance</a>
</div>
<?php if ($message !== ""): ?><div class="notice notice-success"><?= e($message) ?></div><?php endif; ?>
<div class="filter-row">
    <div class="search-field"><i data-lucide="search"></i><input type="search" placeholder="Search archived records..."></div>
    <form method="get"><select name="status" onchange="this.form.submit()"><option value="all" <?= $statusFilter === "all" ? "selected" : "" ?>>All statuses</option><option value="cleared" <?= $statusFilter === "cleared" ? "selected" : "" ?>>Cleared</option><option value="not_cleared" <?= $statusFilter === "not_cleared" ? "selected" : "" ?>>Not cleared</option><option value="pending" <?= $statusFilter === "pending" ? "selected" : "" ?>>Pending</option></select></form>
</div>
<div class="table-card"><div class="table-wrapper"><table>
<thead><tr><th>Student</th><th>Term</th><th>Status</th><th>Archived</th><th>Action</th></tr></thead>
<tbody>
<?php if ($rows && $rows->num_rows): while ($row = $rows->fetch_assoc()): ?>
<tr>
    <td><strong><?= e($row["student_name"]) ?></strong><small><?= e($row["student_number"]) ?> · <?= e($row["course"] ?: "No course") ?></small></td>
    <td><?= e($row["school_year"]) ?><small><?= e($row["semester"]) ?></small></td>
    <td><span class="status status-<?= e($row["overall_status"]) ?>"><?= e(ucwords(str_replace("_", " ", $row["overall_status"]))) ?></span></td>
    <td><?= e($row["archived_at"] ? date("M d, Y", strtotime($row["archived_at"])) : date("M d, Y", strtotime($row["requested_at"]))) ?></td>
    <td class="action-group">
        <?php if ($row["overall_status"] === "cleared"): ?><a class="table-action" href="certificate.php?request=<?= (int)$row["clearance_id"] ?>" target="_blank">Certificate</a><?php endif; ?>
        <form method="post"><input type="hidden" name="action" value="restore"><input type="hidden" name="clearance_id" value="<?= (int)$row["clearance_id"] ?>"><button class="table-action" type="submit">Restore</button></form>
    </td>
</tr>
<?php endwhile; else: ?>
<tr><td colspan="5" class="empty-state">Archive is empty. Archive finished clearances from the Clearance page.</td></tr>
<?php endif; ?>
</tbody></table></div></div>
<?php require "_footer.php"; ?>
