<?php
require_once "../config/auth.php";
require_role("office");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? "Office Portal") ?> | Student Clearance System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=20261021">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <div class="school-header">
            <img src="../assets/images/system_logo.jpg" class="school-logo" alt="Student Clearance System logo">
            <div class="school-info"><div class="school-name">Student Clearance Management System</div><div class="school-motto">Clearance made simple; progress made visible.</div></div>
        </div>
        <div class="sidebar-search" id="sidebarSearch"><i data-lucide="search"></i><input id="sidebarSearchInput" type="search" placeholder="Search" aria-label="Search office pages"><kbd>Ctrl K</kbd></div>
        <nav class="sidebar-menu">
            <a href="dashboard.php" class="menu-item <?= ($activePage ?? '') === 'dashboard' ? 'active' : '' ?>"><i data-lucide="layout-dashboard"></i><span>Dashboard</span></a>
            <?php
            $__isClubModerator = false;
            $__isDsaUser = false;
            $__hasDirectAssignment = false;
            if (isset($conn) && isset($_SESSION["user_id"])) {
                $__cm = $conn->prepare("SELECT club_id FROM institutional_clubs WHERE moderator_user_id = ? AND status = 'active' LIMIT 1");
                if ($__cm) {
                    $__cm->bind_param("i", $_SESSION["user_id"]);
                    $__cm->execute();
                    $__cmRes = $__cm->get_result();
                    $__isClubModerator = (bool)($__cmRes && $__cmRes->fetch_assoc());
                    $__cm->close();
                }
                // DSA check: assigned to DSA office via active assignatory (admin assigns in Assignatories).
                $__dsaOffice = 0;
                $__or = $conn->query("SELECT office_id FROM offices WHERE office_name LIKE '%Student Affairs%' OR office_name LIKE '%(DSA)%' LIMIT 1");
                if ($__or && ($__orow = $__or->fetch_assoc())) $__dsaOffice = (int)$__orow["office_id"];
                if ($__or) $__or->close();
                if ($__dsaOffice <= 0) $__dsaOffice = 1;
                $__dq = $conn->prepare("SELECT assignatory_id FROM office_assignatories WHERE office_id = ? AND user_id = ? AND status = 'active' LIMIT 1");
                if ($__dq) {
                    $__dq->bind_param("ii", $__dsaOffice, $_SESSION["user_id"]);
                    $__dq->execute();
                    $__dqRes = $__dq->get_result();
                    $__isDsaUser = (bool)($__dqRes && $__dqRes->fetch_assoc());
                    $__dq->close();
                }
                // Direct assignment: admin links office user via office_assignatories.user_id.
                $__da = $conn->prepare("SELECT assignatory_id FROM office_assignatories WHERE user_id = ? AND status = 'active' LIMIT 1");
                if ($__da) {
                    $__da->bind_param("i", $_SESSION["user_id"]);
                    $__da->execute();
                    $__daRes = $__da->get_result();
                    $__hasDirectAssignment = (bool)($__daRes && $__daRes->fetch_assoc());
                    $__da->close();
                }
            }
            ?>
            <?php if (is_dean_user() || $__isClubModerator || $__isDsaUser || $__hasDirectAssignment): ?><a href="reviews.php" class="menu-item <?= ($activePage ?? '') === 'reviews' ? 'active' : '' ?>"><i data-lucide="file-check-2"></i><span>Clearance reviews</span></a><?php endif; ?>
            <a href="notifications.php" class="menu-item <?= ($activePage ?? '') === 'notifications' ? 'active' : '' ?>"><i data-lucide="bell"></i><span>Notifications</span></a>
            <a href="announcements.php" class="menu-item <?= ($activePage ?? '') === 'announcements' ? 'active' : '' ?>"><i data-lucide="megaphone"></i><span>Announcements</span></a>
            <?php if (is_dean_user()): ?><a href="assignatories.php" class="menu-item <?= ($activePage ?? '') === 'assignatories' ? 'active' : '' ?>"><i data-lucide="users-round"></i><span>Department Assignatories</span></a><?php endif; ?>
            <?php if ($__isDsaUser): ?><a href="ssc_assignatories.php" class="menu-item <?= ($activePage ?? '') === 'ssc_assignatories' ? 'active' : '' ?>"><i data-lucide="users-round"></i><span>SSC Assignatories</span></a><?php endif; ?>
        </nav>
        <div class="sidebar-bottom"><div class="sidebar-version">scms.v1.0.2<br>Developed by <span class="version-team">BSCS STUDENT</span></div></div>
    </aside>
    <div class="main-area">
        <header class="topbar topbar-single-toggle"><div class="topbar-left"><button class="icon-button sidebar-toggle" id="sidebarToggle" data-sidebar-toggle type="button" aria-label="Collapse sidebar" aria-expanded="true"><i data-lucide="panel-left"></i></button><span class="topbar-divider" aria-hidden="true"></span><span class="topbar-title"><?= e($pageTitle ?? "Dashboard") ?></span></div><div class="top-actions"><?php $__unread = 0; if (isset($conn) && isset($_SESSION["user_id"])) { $__uid=(int)$_SESSION["user_id"]; $__tbl=$conn->query("SHOW TABLES LIKE 'notifications'"); if ($__tbl && $__tbl->num_rows) { $__stmt=$conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id=? AND is_read=0"); if ($__stmt) { $__stmt->bind_param("i",$__uid); $__stmt->execute(); $__r=$__stmt->get_result()->fetch_assoc(); $__unread=(int)($__r["c"]??0); $__stmt->close(); } } if ($__tbl) $__tbl->close(); } $__displayName = trim((string)($_SESSION["full_name"] ?? "")); if ($__displayName === "") $__displayName = "Office"; $__dropEmail = trim((string)($_SESSION["email"] ?? "")); $__nameParts = preg_split('/\s+/', $__displayName, -1, PREG_SPLIT_NO_EMPTY) ?: []; $__firstWord = $__nameParts[0] ?? ""; $__lastWord = count($__nameParts) > 1 ? $__nameParts[count($__nameParts) - 1] : ""; $__initials = strtoupper(mb_substr($__firstWord, 0, 1) . ($__lastWord !== "" ? mb_substr($__lastWord, 0, 1) : "")); if ($__initials === "") $__initials = "O"; ?><div class="notif-wrap"><button class="icon-button" id="notificationsButton" type="button" aria-label="View notifications" aria-haspopup="true" aria-expanded="false" style="position:relative;"><i data-lucide="bell"></i><span class="notification-badge" data-unread-badge><?= $__unread > 99 ? "99+" : (string)$__unread ?></span></button><div class="notif-dropdown" id="notifDropdown" hidden><div class="notif-head"><strong>Notifications</strong><span class="notif-head-actions"><button class="notif-mark-all" id="notifMarkAll" type="button" aria-label="Mark all as read"><i data-lucide="check-check"></i></button><small data-notif-count><?= (int)$__unread ?> unread</small></span></div><div class="notif-list" id="notifList"></div><a class="notif-foot" href="notifications.php">View all notifications</a></div></div><button class="icon-button" id="helpButton" data-help-message="Review requests assigned to your office and update each requirement with a decision and remarks." data-help-message-mobile="Use the bottom navigation bar and the More tab to review requests assigned to your office and update each requirement with a decision and remarks." aria-label="Help"><i data-lucide="circle-help"></i></button><div class="profile-wrap"><button class="topbar-profile-link" id="profileButton" type="button" aria-label="Account menu" aria-haspopup="true" aria-expanded="false"><span class="topbar-user-meta"><strong><?= e($__displayName) ?></strong><small>Office</small></span><?php if (!empty($_SESSION["avatar_path"])): ?><img src="<?= e("../" . $_SESSION["avatar_path"]) ?>" class="profile-avatar" alt="Profile picture"><?php else: ?><span class="profile-avatar avatar-initials" aria-hidden="true"><?= e($__initials) ?></span><?php endif; ?><i data-lucide="chevrons-up-down" class="topbar-chevron"></i></button><div class="profile-dropdown" id="profileDropdown" hidden><div class="profile-drop-head"><?php if (!empty($_SESSION["avatar_path"])): ?><img src="<?= e("../" . $_SESSION["avatar_path"]) ?>" class="profile-drop-avatar" alt="Profile picture"><?php else: ?><span class="profile-drop-avatar avatar-initials" aria-hidden="true"><?= e($__initials) ?></span><?php endif; ?><span class="profile-drop-id"><strong><?= e($__displayName) ?></strong><?php if ($__dropEmail !== ""): ?><small><?= e($__dropEmail) ?></small><?php endif; ?><small>Office</small></span></div><a class="profile-drop-item" href="profile.php"><i data-lucide="user-round"></i>Profile</a><a class="profile-drop-item" href="change_password.php"><i data-lucide="key-round"></i>Change password</a><button class="profile-drop-item profile-dark-item" id="profileDarkModeToggle" type="button" aria-pressed="false" aria-label="Toggle dark mode"><span data-dark-icon><i data-lucide="moon"></i></span>Dark Mode</button><a class="profile-drop-item profile-drop-logout" data-delete-confirm="If you continue, you will be logged out of your account." data-confirm-title="Log out?" data-confirm-action="Log out" data-confirm-kind="warning" data-confirm-icon="log-out" href="../admin/logout.php"><i data-lucide="log-out"></i>Logout</a></div></div></div></header>
        <main class="content">

        