<?php
require_once "../config/auth.php";
require_role("admin");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? "Admin") ?> | Student Clearance System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=20261031">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>
<div class="app-shell">

    <aside class="sidebar" id="sidebar">
        <div class="school-header">
            <img src="../assets/images/system_logo.jpg" class="school-logo" alt="Student Clearance System logo">
            <div class="school-info">
                <div class="school-name">Student Clearance Management System</div>
                <div class="school-motto">Clearance made simple; progress made visible.</div>
            </div>
        </div>

        <div class="sidebar-search" id="sidebarSearch">
            <i data-lucide="search"></i>
            <input id="sidebarSearchInput" type="search" placeholder="Search" aria-label="Search admin pages">
            <kbd>Ctrl K</kbd>
        </div>

        <nav class="sidebar-menu">
            <a href="dashboard.php" class="menu-item <?= ($activePage ?? '') === 'dashboard' ? 'active' : '' ?>">
                <i data-lucide="layout-dashboard"></i><span>Dashboard</span>
            </a>

            <div class="menu-section">Management</div>

            <a href="students.php" class="menu-item <?= ($activePage ?? '') === 'students' ? 'active' : '' ?>">
                <i data-lucide="graduation-cap"></i><span>Students</span>
            </a>

            <a href="offices.php" class="menu-item <?= ($activePage ?? '') === 'offices' ? 'active' : '' ?>">
                <i data-lucide="building-2"></i><span>Offices</span>
            </a>

            <a href="courses.php" class="menu-item <?= ($activePage ?? '') === 'courses' ? 'active' : '' ?>">
                <i data-lucide="book-open-text"></i><span>Courses</span>
            </a>

            <a href="assignatories.php" class="menu-item <?= ($activePage ?? '') === 'assignatories' ? 'active' : '' ?>">
                <i data-lucide="user-round-cog"></i><span>Assignatories</span>
            </a>

            <a href="institutional_clubs.php" class="menu-item <?= ($activePage ?? '') === 'institutional_clubs' ? 'active' : '' ?>">
                <i data-lucide="users-round"></i><span>Institutional Clubs</span>
            </a>

            <a href="clearance.php" class="menu-item <?= ($activePage ?? '') === 'clearance' ? 'active' : '' ?>">
                <i data-lucide="file-check-2"></i><span>Clearance</span>
            </a>

            <a href="requirement_types.php" class="menu-item <?= ($activePage ?? '') === 'requirement_types' ? 'active' : '' ?>">
                <i data-lucide="list-checks"></i><span>Requirement Types</span>
            </a>

            <a href="school_terms.php" class="menu-item <?= ($activePage ?? '') === 'school_terms' ? 'active' : '' ?>">
                <i data-lucide="calendar-days"></i><span>School Terms</span>
            </a>

            <a href="reports.php" class="menu-item <?= ($activePage ?? '') === 'reports' ? 'active' : '' ?>">
                <i data-lucide="file-bar-chart"></i><span>Reports</span>
            </a>

            <a href="archive.php" class="menu-item <?= ($activePage ?? '') === 'archive' ? 'active' : '' ?>">
                <i data-lucide="archive"></i><span>Archive</span>
            </a>

            <a href="notifications.php" class="menu-item <?= ($activePage ?? '') === 'notifications' ? 'active' : '' ?>">
                <i data-lucide="bell"></i><span>Notifications</span>
            </a>

            <a href="form_configuration.php" class="menu-item <?= ($activePage ?? '') === 'form_configuration' ? 'active' : '' ?>">
                <i data-lucide="file-cog"></i><span>Form Configuration</span>
            </a>

            <a href="users.php" class="menu-item <?= ($activePage ?? '') === 'users' ? 'active' : '' ?>">
                <i data-lucide="users-round"></i><span>Users</span>
            </a>

            <a href="announcements.php" class="menu-item <?= ($activePage ?? '') === 'announcements' ? 'active' : '' ?>">
                <i data-lucide="megaphone"></i><span>Announcements</span>
            </a>

            <div class="menu-section">System</div>

            <a href="settings.php" class="menu-item <?= ($activePage ?? '') === 'settings' ? 'active' : '' ?>">
                <i data-lucide="settings-2"></i><span>Settings</span>
            </a>
        </nav>

        <div class="sidebar-bottom">
            <div class="sidebar-version">scms.v1.0.2<br>Developed by <span class="version-team">BSCS STUDENT</span></div>
        </div>
    </aside>

    <div class="main-area">
        <header class="topbar topbar-single-toggle">
            <div class="topbar-left">
                <button class="icon-button sidebar-toggle" id="sidebarToggle" data-sidebar-toggle type="button" aria-label="Collapse sidebar" aria-expanded="true">
                    <i data-lucide="panel-left"></i>
                </button>
                <span class="topbar-divider" aria-hidden="true"></span>
                <span class="topbar-title"><?= e($pageTitle ?? "Dashboard") ?></span>
            </div>

            <div class="top-actions">
                    <?php
                    $__unread = 0;
                    if (isset($conn) && isset($_SESSION["user_id"])) {
                        $__uid = (int)$_SESSION["user_id"];
                        $__tbl = $conn->query("SHOW TABLES LIKE 'notifications'");
                        if ($__tbl && $__tbl->num_rows) {
                            $__stmt = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id=? AND is_read=0");
                            if ($__stmt) { $__stmt->bind_param("i", $__uid); $__stmt->execute(); $__r = $__stmt->get_result()->fetch_assoc(); $__unread = (int)($__r["c"] ?? 0); $__stmt->close(); }
                        }
                        if ($__tbl) $__tbl->close();
                    }
                    $__displayName = trim((string)($_SESSION["full_name"] ?? ""));
                    if ($__displayName === "") $__displayName = "Admin";
                    $__dropEmail = trim((string)($_SESSION["email"] ?? ""));
                    $__nameParts = preg_split('/\s+/', $__displayName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    $__firstWord = $__nameParts[0] ?? "";
                    $__lastWord = count($__nameParts) > 1 ? $__nameParts[count($__nameParts) - 1] : "";
                    $__initials = strtoupper(mb_substr($__firstWord, 0, 1) . ($__lastWord !== "" ? mb_substr($__lastWord, 0, 1) : ""));
                    if ($__initials === "") $__initials = "A";
                    ?>
                    <div class="notif-wrap">
                        <button class="icon-button" id="notificationsButton" type="button" aria-label="Notifications" aria-haspopup="true" aria-expanded="false" style="position:relative;">
                            <i data-lucide="bell"></i>
                            <span class="notification-badge" data-unread-badge><?= $__unread > 99 ? "99+" : (string)$__unread ?></span>
                        </button>
                        <div class="notif-dropdown" id="notifDropdown" hidden>
                            <div class="notif-head"><strong>Notifications</strong><span class="notif-head-actions"><button class="notif-mark-all" id="notifMarkAll" type="button" aria-label="Mark all as read"><i data-lucide="check-check"></i></button><small data-notif-count><?= (int)$__unread ?> unread</small></span></div>
                            <div class="notif-list" id="notifList"></div>
                            <a class="notif-foot" href="notifications.php">View all notifications</a>
                        </div>
                    </div>
                    <button class="icon-button" id="helpButton" aria-label="Help" data-help-message="Use the sidebar to manage students, offices, users, announcements, and clearance requests." data-help-message-mobile="Use the bottom navigation bar and the More tab to manage students, offices, users, announcements, and clearance requests.">
                    <i data-lucide="circle-help"></i>
                </button>
                    <div class="profile-wrap">
                        <button class="topbar-profile-link" id="profileButton" type="button" aria-label="Account menu" aria-haspopup="true" aria-expanded="false">
                            <span class="topbar-user-meta"><strong><?= e($__displayName) ?></strong><small>Admin</small></span>
                            <?php if (!empty($_SESSION["avatar_path"])): ?><img src="<?= e("../" . $_SESSION["avatar_path"]) ?>" class="profile-avatar" alt="Profile picture"><?php else: ?><span class="profile-avatar avatar-initials" aria-hidden="true"><?= e($__initials) ?></span><?php endif; ?>
                            <i data-lucide="chevrons-up-down" class="topbar-chevron"></i>
                        </button>
                        <div class="profile-dropdown" id="profileDropdown" hidden>
                            <div class="profile-drop-head">
                                <?php if (!empty($_SESSION["avatar_path"])): ?><img src="<?= e("../" . $_SESSION["avatar_path"]) ?>" class="profile-drop-avatar" alt="Profile picture"><?php else: ?><span class="profile-drop-avatar avatar-initials" aria-hidden="true"><?= e($__initials) ?></span><?php endif; ?>
                                <span class="profile-drop-id"><strong><?= e($__displayName) ?></strong><?php if ($__dropEmail !== ""): ?><small><?= e($__dropEmail) ?></small><?php endif; ?><small>Admin</small></span>
                            </div>
                            <a class="profile-drop-item" href="profile.php"><i data-lucide="user-round"></i>Profile</a>
                            <a class="profile-drop-item" href="change_password.php"><i data-lucide="key-round"></i>Change password</a>
                            <button class="profile-drop-item profile-dark-item" id="profileDarkModeToggle" type="button" aria-pressed="false" aria-label="Toggle dark mode"><span data-dark-icon><i data-lucide="moon"></i></span>Dark Mode</button>
                            <a class="profile-drop-item profile-drop-logout" data-delete-confirm="If you continue, you will be logged out of your account." data-confirm-title="Log out?" data-confirm-action="Log out" data-confirm-kind="warning" data-confirm-icon="log-out" href="logout.php"><i data-lucide="log-out"></i>Logout</a>
                        </div>
                    </div>
            </div>
        </header>

        <main class="content">
