<?php
$pageTitle = "Announcements";
$activePage = "announcements";
require_once "../config/db.php";
require_once "../config/auth.php";
require_role("office");
$message = $_SESSION["flash_message"] ?? "";
unset($_SESSION["flash_message"]);
$error = "";
$editAnnouncement = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
	$action = $_POST["action"] ?? "";
	$announcementId = (int)($_POST["announcement_id"] ?? 0);
	$officeUserId = (int)$_SESSION["user_id"];

	if ($action === "save") {
		$title = trim($_POST["title"] ?? "");
		$content = trim($_POST["content"] ?? "");
		$status = ($_POST["status"] ?? "draft") === "published" ? "published" : "draft";
		if ($title === "" || $content === "") {
			$error = "Title and content are required.";
			$editAnnouncement = ["announcement_id" => $announcementId, "title" => $title, "content" => $content, "status" => $status];
		} elseif ($announcementId > 0) {
			$stmt = $conn->prepare("UPDATE announcements SET title = ?, content = ?, status = ? WHERE announcement_id = ? AND created_by = ?");
			$stmt->bind_param("sssii", $title, $content, $status, $announcementId, $officeUserId);
			$updated = $stmt->execute();
			$stmt->close();
			if ($updated) {
				$_SESSION["flash_message"] = "Announcement updated.";
				header("Location: announcements.php");
				exit;
			}
			else $error = "You can only edit announcements created by your office account.";
		} else {
			$stmt = $conn->prepare("INSERT INTO announcements (title, content, status, created_by) VALUES (?, ?, ?, ?)");
			$stmt->bind_param("sssi", $title, $content, $status, $officeUserId);
			$created = $stmt->execute();
			$stmt->close();
			if ($created) {
				$_SESSION["flash_message"] = "Announcement created.";
				header("Location: announcements.php");
				exit;
			}
			else $error = "Unable to create the announcement.";
		}
	} elseif ($action === "toggle" && $announcementId > 0) {
		$stmt = $conn->prepare("UPDATE announcements SET status = IF(status = 'published', 'draft', 'published') WHERE announcement_id = ? AND created_by = ?");
		$stmt->bind_param("ii", $announcementId, $officeUserId);
		$stmt->execute();
		$message = $stmt->affected_rows ? "Announcement status updated." : "You can only update announcements created by your office account.";
		$stmt->close();
	} elseif ($action === "delete" && $announcementId > 0) {
		$stmt = $conn->prepare("DELETE FROM announcements WHERE announcement_id = ? AND created_by = ?");
		$stmt->bind_param("ii", $announcementId, $officeUserId);
		$stmt->execute();
		$message = $stmt->affected_rows ? "Announcement deleted." : "You can only delete announcements created by your office account.";
		$stmt->close();
	}
}

if ($_SERVER["REQUEST_METHOD"] !== "POST" && isset($_GET["edit"])) {
	$announcementId = (int)$_GET["edit"];
	$stmt = $conn->prepare("SELECT announcement_id, title, content, status FROM announcements WHERE announcement_id = ? AND created_by = ?");
	$stmt->bind_param("ii", $announcementId, $_SESSION["user_id"]);
	$stmt->execute();
	$editAnnouncement = $stmt->get_result()->fetch_assoc() ?: null;
	$stmt->close();
}

$announcements = $conn->query("SELECT a.announcement_id, a.title, a.content, a.status, a.created_at, a.created_by, u.full_name AS author FROM announcements a LEFT JOIN users u ON u.user_id = a.created_by WHERE a.status = 'published' OR a.created_by = " . (int)$_SESSION["user_id"] . " ORDER BY a.created_at DESC");
require "_header.php";
?>
<div class="page-heading"><div><h1>Announcements</h1><p>Share reminders about penalties, deadlines, and office clearance updates.</p></div><a class="btn btn-primary" href="#announcement-form" data-form-target="announcement-form"><i data-lucide="plus"></i> New announcement</a></div>
<?php if ($message !== ""): ?><div class="notice notice-success"><?= e($message) ?></div><?php endif; ?><?php if ($error !== ""): ?><div class="notice notice-error"><?= e($error) ?></div><?php endif; ?>
<div class="form-card announcement-form floating-form <?= $editAnnouncement ? "is-open" : "" ?>" id="announcement-form"><div class="floating-form-header"><h2><?= $editAnnouncement ? "Edit announcement" : "New announcement" ?></h2><button class="floating-form-close" type="button" aria-label="Close form"><i data-lucide="x"></i></button></div><form method="post"><input type="hidden" name="action" value="save"><input type="hidden" name="announcement_id" value="<?= (int)($editAnnouncement["announcement_id"] ?? 0) ?>"><label>Title<input type="text" name="title" maxlength="180" required value="<?= e($editAnnouncement["title"] ?? "") ?>" placeholder="Reminder: clear your library penalties"></label><label>Message<textarea name="content" rows="5" required><?= e($editAnnouncement["content"] ?? "") ?></textarea></label><label>Status<select name="status"><option value="draft" <?= ($editAnnouncement["status"] ?? "draft") === "draft" ? "selected" : "" ?>>Draft</option><option value="published" <?= ($editAnnouncement["status"] ?? "draft") === "published" ? "selected" : "" ?>>Publish now</option></select></label><button class="btn btn-primary" type="submit"><i data-lucide="send"></i><?= $editAnnouncement ? "Save changes" : "Create announcement" ?></button></form></div>
<div class="student-announcements student-announcements-page">
<?php if ($announcements && $announcements->num_rows): while ($announcement = $announcements->fetch_assoc()): ?><article class="announcement-item"><span><?= e(date("M d, Y", strtotime($announcement["created_at"]))) ?> · <?= e($announcement["author"] ?: "System") ?></span><h2><?= e($announcement["title"]) ?></h2><p><?= nl2br(e($announcement["content"])) ?></p><?php if ((int)$announcement["created_by"] === (int)$_SESSION["user_id"]): ?><div class="action-group"><a class="table-action" href="announcements.php?edit=<?= (int)$announcement["announcement_id"] ?>#announcement-form">Edit</a><form method="post"><input type="hidden" name="action" value="toggle"><input type="hidden" name="announcement_id" value="<?= (int)$announcement["announcement_id"] ?>"><button class="table-action" type="submit"><?= $announcement["status"] === "published" ? "Draft" : "Publish" ?></button></form><form method="post" data-delete-confirm="Delete this announcement? This cannot be undone."><input type="hidden" name="action" value="delete"><input type="hidden" name="announcement_id" value="<?= (int)$announcement["announcement_id"] ?>"><button class="table-action table-action-danger" type="submit">Delete</button></form></div><?php endif; ?></article><?php endwhile; else: ?><div class="empty-panel"><i data-lucide="megaphone"></i><h3>No announcements yet</h3><p>Create a reminder for students and offices.</p></div><?php endif; ?>
</div>
<?php require "_footer.php"; ?>
