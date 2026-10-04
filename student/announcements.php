<?php
$pageTitle = "Announcements";
$activePage = "announcements";
require_once "../config/db.php";
require_once "../config/auth.php";
require_role("student");

$announcements = $conn->query("SELECT title, content, created_at FROM announcements WHERE status = 'published' ORDER BY created_at DESC");
require "_header.php";
?>
<div class="page-heading"><div><h1>Announcements</h1><p>Stay informed about clearance schedules and important updates.</p></div></div>
<div class="student-announcements student-announcements-page">
<?php if ($announcements && $announcements->num_rows): while ($announcement = $announcements->fetch_assoc()): ?><article class="announcement-item"><span><?= e(date("M d, Y", strtotime($announcement["created_at"]))) ?></span><h2><?= e($announcement["title"]) ?></h2><p><?= nl2br(e($announcement["content"])) ?></p></article><?php endwhile; else: ?><div class="empty-panel"><i data-lucide="megaphone"></i><h3>No announcements yet</h3><p>Published updates will appear here.</p></div><?php endif; ?>
</div>
<?php require "_footer.php"; ?>