<?php
require_once "../config/auth.php";
require_role("student");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? "Student Portal") ?> | Student Clearance System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <div class="school-header">
            <img src="../assets/images/system_logo.jpg" class="school-logo" alt="Student Clearance System logo">
            <div class="school-info"><div class="school-name">Student Clearance Management System</div><div class="school-motto">Clearance made simple; progress made visible.</div></div>
            <button class="school-switcher" id="schoolSwitcher" type="button" aria-label="Open organization menu" aria-expanded="false"><i data-lucide="chevrons-up-down" class="school-chevron"></i></button>
            <div class="school-menu" id="schoolMenu">
                <div class="school-menu-title">Organizations</div>
                <a class="organization-option" href="https://edurie.com/ckcm" target="_blank" rel="noopener noreferrer" aria-current="true">
                    <img src="../assets/images/ckcm_logo.jpg" alt="Christ The King College De Maranding, Inc. logo">
                    <span><strong>Christ The King College De Maranding, Inc.</strong><small>Student Clearance System</small></span>
                    <i data-lucide="check"></i>
                </a>
            </div>
        </div>
        <div class="sidebar-search" id="sidebarSearch"><i data-lucide="search"></i><input id="sidebarSearchInput" type="search" placeholder="Search" aria-label="Search student pages"><kbd>Ctrl K</kbd></div>
        <nav class="sidebar-menu">
            <a href="dashboard.php" class="menu-item <?= ($activePage ?? '') === 'dashboard' ? 'active' : '' ?>"><i data-lucide="layout-dashboard"></i><span>Dashboard</span></a>
            <a href="clearance.php" class="menu-item <?= ($activePage ?? '') === 'clearance' ? 'active' : '' ?>"><i data-lucide="file-check-2"></i><span>My Clearance</span></a>
            <a href="announcements.php" class="menu-item <?= ($activePage ?? '') === 'announcements' ? 'active' : '' ?>"><i data-lucide="megaphone"></i><span>Announcements</span></a>
            <div class="menu-section">Account</div>
            <a href="profile.php" class="menu-item <?= ($activePage ?? '') === 'profile' ? 'active' : '' ?>"><i data-lucide="circle-user-round"></i><span>My Profile</span></a>
        </nav>
        <div class="sidebar-bottom">
            <div class="menu-item dark-mode-item">
                <i data-lucide="moon"></i>
                <span>Dark Mode</span>
                <label class="switch">
                    <input type="checkbox" id="darkModeToggle">
                    <span class="slider"></span>
                </label>
            </div>
            <a href="../admin/logout.php" class="menu-item logout-item">
                <i data-lucide="log-out"></i><span>Logout</span>
            </a>
        </div>
    </aside>
    <div class="main-area"><header class="topbar"><button class="icon-button mobile-menu" id="mobileMenu" aria-label="Open menu"><i data-lucide="menu"></i></button><div class="topbar-school"><button class="icon-button sidebar-toggle" id="sidebarToggle" data-sidebar-toggle type="button" aria-label="Collapse sidebar" aria-expanded="true"><i data-lucide="panel-left"></i></button><i data-lucide="graduation-cap"></i><span>Student Portal</span></div><div class="philippine-time" id="philippineTime" aria-label="Philippine time"><i data-lucide="clock-3"></i><span>PHT --:--:--</span></div><div class="top-actions"><button class="icon-button" id="notificationsButton" aria-label="Notifications"><i data-lucide="bell"></i></button><a href="profile.php" class="profile-avatar-link" aria-label="My profile"><img src="<?= e(!empty($_SESSION["avatar_path"]) ? "../" . $_SESSION["avatar_path"] : "../assets/images/system_logo.jpg") ?>" class="profile-avatar" alt="Profile picture"></a></div></header><main class="content">