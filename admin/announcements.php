<?php
$pageTitle = "Announcements";
$activePage = "announcements";
require_once "../config/auth.php";
require_role("admin");
require_once "../config/db.php";

$message = $_SESSION["flash_message"] ?? "";
unset($_SESSION["flash_message"]);
$error = "";
$editAnnouncement = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    $announcementId = (int)($_POST["announcement_id"] ?? 0);

    if ($action === "save") {
        $title = trim($_POST["title"] ?? "");
        $content = trim($_POST["content"] ?? "");
        $status = ($_POST["status"] ?? "draft") === "published" ? "published" : "draft";

        if ($title === "" || $content === "") {
            $error = "Title and content are required.";
            $editAnnouncement = [
                "announcement_id" => $announcementId,
                "title" => $title,
                "content" => $content,
                "status" => $status
            ];
        } elseif ($announcementId > 0) {
            $stmt = $conn->prepare(
                "UPDATE announcements SET title = ?, content = ?, status = ? WHERE announcement_id = ?"
            );
            $stmt->bind_param("sssi", $title, $content, $status, $announcementId);
            if ($stmt->execute()) {
                $_SESSION["flash_message"] = "Announcement updated.";
                header("Location: announcements.php");
                exit;
            }
            $stmt->close();
        } else {
            $createdBy = (int)$_SESSION["user_id"];
            $stmt = $conn->prepare(
                "INSERT INTO announcements (title, content, status, created_by) VALUES (?, ?, ?, ?)"
            );
            $stmt->bind_param("sssi", $title, $content, $status, $createdBy);
            $stmt->execute();
            $stmt->close();
            $_SESSION["flash_message"] = "Announcement created.";
            header("Location: announcements.php");
            exit;
        }
    } elseif ($action === "delete" && $announcementId > 0) {
        $stmt = $conn->prepare("DELETE FROM announcements WHERE announcement_id = ?");
        $stmt->bind_param("i", $announcementId);
        $stmt->execute();
        $stmt->close();
        $message = "Announcement deleted.";
    } elseif ($action === "toggle" && $announcementId > 0) {
        $stmt = $conn->prepare(
            "UPDATE announcements
             SET status = IF(status = 'published', 'draft', 'published')
             WHERE announcement_id = ?"
        );
        $stmt->bind_param("i", $announcementId);
        $stmt->execute();
        $stmt->close();
        $message = "Announcement status updated.";
    }
}

if ($_SERVER["REQUEST_METHOD"] !== "POST" && isset($_GET["edit"])) {
    $announcementId = (int)$_GET["edit"];
    $stmt = $conn->prepare(
        "SELECT announcement_id, title, content, status
         FROM announcements WHERE announcement_id = ?"
    );
    $stmt->bind_param("i", $announcementId);
    $stmt->execute();
    $editAnnouncement = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

$announcements = $conn->query(
    "SELECT a.announcement_id, a.title, a.content, a.status, a.created_at,
            u.full_name AS author
     FROM announcements a
     LEFT JOIN users u ON u.user_id = a.created_by
     ORDER BY a.created_at DESC"
);

require "_header.php";
?>
<div class="page-heading">
    <div>
        <h1>Announcements</h1>
        <p>Create and manage announcements for students and offices.</p>
    </div>
    <a class="btn btn-primary" href="#announcement-form" data-form-target="announcement-form"><i data-lucide="plus"></i> New announcement</a>
</div>

<?php if ($message !== ""): ?><div class="notice notice-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ""): ?><div class="notice notice-error"><?= e($error) ?></div><?php endif; ?>

<div class="form-card announcement-form floating-form <?= $editAnnouncement ? "is-open" : "" ?>" id="announcement-form">
    <div class="floating-form-header">
        <h2><?= $editAnnouncement ? "Edit announcement" : "New announcement" ?></h2>
        <button class="floating-form-close" type="button" aria-label="Close form"><i data-lucide="x"></i></button>
    </div>
    <form method="post">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="announcement_id" value="<?= (int)($editAnnouncement["announcement_id"] ?? 0) ?>">
        <label>
            Title
            <input type="text" name="title" maxlength="180" required value="<?= e($editAnnouncement["title"] ?? "") ?>">
        </label>
        <label>
            Content
            <textarea name="content" rows="4" required><?= e($editAnnouncement["content"] ?? "") ?></textarea>
        </label>
        <label>
            Status
            <select name="status">
                <option value="draft" <?= ($editAnnouncement["status"] ?? "draft") === "draft" ? "selected" : "" ?>>Draft</option>
                <option value="published" <?= ($editAnnouncement["status"] ?? "draft") === "published" ? "selected" : "" ?>>Published</option>
            </select>
        </label>
        <button class="btn btn-primary" type="submit">
            <i data-lucide="save"></i><?= $editAnnouncement ? "Save changes" : "Create announcement" ?>
        </button>
    </form>
</div>

<div class="toolbar-card"><div class="search-field"><i data-lucide="search"></i><input type="search" placeholder="Search announcements..."></div></div>

<div class="table-card">
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Announcement</th><th>Author</th><th>Status</th><th>Created</th><th>Action</th></tr></thead>
            <tbody>
            <?php if ($announcements && $announcements->num_rows): ?>
                <?php while ($row = $announcements->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?= e($row["title"]) ?></strong><small><?= e($row["content"]) ?></small></td>
                        <td><?= e($row["author"] ?: "System") ?></td>
                        <td><span class="status status-<?= e($row["status"]) ?>"><?= e(ucfirst($row["status"])) ?></span></td>
                        <td><?= e(date("M d, Y", strtotime($row["created_at"]))) ?></td>
                        <td class="action-group">
                            <a class="table-action" href="announcements.php?edit=<?= (int)$row["announcement_id"] ?>">Edit</a>
                            <form method="post">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="announcement_id" value="<?= (int)$row["announcement_id"] ?>">
                                <button class="table-action" type="submit"><?= $row["status"] === "published" ? "Draft" : "Publish" ?></button>
                            </form>
                            <form method="post" data-delete-confirm="Delete this announcement? This cannot be undone.">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="announcement_id" value="<?= (int)$row["announcement_id"] ?>">
                                <button class="table-action table-action-danger" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="5" class="empty-state">No announcements yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require "_footer.php"; ?>
