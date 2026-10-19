<?php
$pageTitle = "Notifications";
$activePage = "notifications";
require_once "../config/db.php";
require_once "../config/auth.php";
require_role("office");
require_once "../config/notifications.php";

$userId = (int)$_SESSION["user_id"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    if ($action === "mark_read" && isset($_POST["notification_id"])) {
        $nid = (int)$_POST["notification_id"];
        $stmt = $conn->prepare("UPDATE notifications SET is_read=1 WHERE notification_id=? AND user_id=?");
        $stmt->bind_param("ii", $nid, $userId);
        $stmt->execute();
        $stmt->close();
        header("Location: notifications.php");
        exit;
    } elseif ($action === "mark_all_read") {
        $stmt = $conn->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->close();
        header("Location: notifications.php");
        exit;
    } elseif ($action === "delete" && isset($_POST["notification_id"])) {
        $nid = (int)$_POST["notification_id"];
        $stmt = $conn->prepare("DELETE FROM notifications WHERE notification_id=? AND user_id=?");
        $stmt->bind_param("ii", $nid, $userId);
        $stmt->execute();
        $stmt->close();
        header("Location: notifications.php");
        exit;
    }
}

$filter = $_GET["filter"] ?? "all";
$stmt = $conn->prepare(
    $filter === "unread"
    ? "SELECT * FROM notifications WHERE user_id=? AND is_read=0 ORDER BY created_at DESC LIMIT 100"
    : "SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 100"
);
$stmt->bind_param("i", $userId);
$stmt->execute();
$notes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$unread = get_unread_notification_count($conn, $userId);
require "_header.php";
?>
<div class="page-heading">
    <div>
        <h1>Notifications</h1>
        <p>Alerts when students submit requests for your office. <?= (int)$unread ?> unread.</p>
    </div>
    <form method="post"><input type="hidden" name="action" value="mark_all_read"><button class="btn btn-secondary" type="submit"><i data-lucide="check-check"></i> Mark all read</button></form>
</div>
<div class="filter-row">
    <a class="btn <?= $filter !== 'unread' ? 'btn-primary' : 'btn-secondary' ?>" href="notifications.php">All</a>
    <a class="btn <?= $filter === 'unread' ? 'btn-primary' : 'btn-secondary' ?>" href="notifications.php?filter=unread">Unread only</a>
</div>
<div class="table-card"><div class="table-wrapper"><table>
<thead><tr><th>Notification</th><th>Type</th><th>Date</th><th>Action</th></tr></thead>
<tbody>
<?php if ($notes): foreach ($notes as $n): ?>
<tr>
    <td><strong><?= e($n['title']) ?></strong><small><?= e($n['message']) ?></small></td>
    <td><span class="status status-active"><?= e($n['type']) ?></span></td>
    <td><?= e(date("M d, Y h:i A", strtotime($n['created_at']))) ?></td>
    <td class="action-group">
        <?php if (!$n['is_read']): ?>
        <form method="post"><input type="hidden" name="action" value="mark_read"><input type="hidden" name="notification_id" value="<?= (int)$n['notification_id'] ?>"><button class="table-action" type="submit">Mark read</button></form>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="4" class="empty-state">No notifications yet.</td></tr>
<?php endif; ?>
</tbody>
</table></div></div>
<?php require "_footer.php"; ?>
