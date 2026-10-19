<?php
// Mobile bottom navigation (replaces sidebar on <=800px).
// Usage in each role footer:
//   require_once __DIR__ . "/mobile_nav.php";
//   render_mobile_nav("admin", $activePage ?? "");
// Items use lucide icons; active state matches $activePage.

if (!function_exists("mobile_nav_items")) {
    function mobile_nav_items(string $role, $conn = null): array
    {
        if ($role === "student") {
            $primary = [
                ["key" => "dashboard", "href" => "dashboard.php", "icon" => "layout-dashboard", "label" => "Home"],
                ["key" => "clearance", "href" => "clearance.php", "icon" => "file-check-2", "label" => "Clearance"],
                ["key" => "notifications", "href" => "notifications.php", "icon" => "bell", "label" => "Alerts"],
                ["key" => "announcements", "href" => "announcements.php", "icon" => "megaphone", "label" => "Notices"],
            ];
            $more = [];
            return [$primary, $more];
        }

        if ($role === "office") {
            $canReview = false;
            $isDean = function_exists("is_dean_user") && is_dean_user();
            $isDsa = false;
            $uid = (int)($_SESSION["user_id"] ?? 0);
            if ($isDean) {
                $canReview = true;
            }
            if ($conn instanceof mysqli && $uid > 0) {
                try {
                    $cm = $conn->prepare("SELECT club_id FROM institutional_clubs WHERE moderator_user_id = ? AND status = 'active' LIMIT 1");
                    if ($cm) {
                        $cm->bind_param("i", $uid);
                        $cm->execute();
                        $r = $cm->get_result();
                        if ($r && $r->fetch_assoc()) $canReview = true;
                        $cm->close();
                    }
                } catch (Throwable $e) {}
                try {
                    $or = $conn->query("SELECT office_id FROM offices WHERE office_name LIKE '%Student Affairs%' OR office_name LIKE '%(DSA)%' LIMIT 1");
                    $dsaOffice = 0;
                    if ($or && ($orow = $or->fetch_assoc())) $dsaOffice = (int)$orow["office_id"];
                    if ($or) @$or->close();
                    if ($dsaOffice <= 0) $dsaOffice = 1;
                    $dq = $conn->prepare("SELECT assignatory_id FROM office_assignatories WHERE office_id = ? AND user_id = ? AND status = 'active' LIMIT 1");
                    if ($dq) {
                        $dq->bind_param("ii", $dsaOffice, $uid);
                        $dq->execute();
                        $dr = $dq->get_result();
                        if ($dr && $dr->fetch_assoc()) { $canReview = true; $isDsa = true; }
                        $dq->close();
                    }
                    $da = $conn->prepare("SELECT assignatory_id FROM office_assignatories WHERE user_id = ? AND status = 'active' LIMIT 1");
                    if ($da) {
                        $da->bind_param("i", $uid);
                        $da->execute();
                        $dar = $da->get_result();
                        if ($dar && $dar->fetch_assoc()) $canReview = true;
                        $da->close();
                    }
                } catch (Throwable $e) {}
            }
            $primary = [
                ["key" => "dashboard", "href" => "dashboard.php", "icon" => "layout-dashboard", "label" => "Home"],
            ];
            if ($canReview) {
                $primary[] = ["key" => "reviews", "href" => "reviews.php", "icon" => "file-check-2", "label" => "Reviews"];
            } else {
                $primary[] = ["key" => "notifications", "href" => "notifications.php", "icon" => "bell", "label" => "Alerts"];
            }
            $primary[] = ["key" => "notifications", "href" => "notifications.php", "icon" => "bell", "label" => "Alerts"];
            $primary[] = ["key" => "announcements", "href" => "announcements.php", "icon" => "megaphone", "label" => "Notices"];
            // De-dupe Alerts when Reviews exists (keep 4 slots max before More).
            $seen = [];
            $deduped = [];
            foreach ($primary as $it) {
                if (isset($seen[$it["key"]])) continue;
                $seen[$it["key"]] = true;
                $deduped[] = $it;
            }
            $primary = array_slice($deduped, 0, 4);
            $more = [];
            if ($canReview && !isset($seen["notifications"])) {
                // Notifications pushed out of primary when 4 slots filled; keep reachable via More.
                $more[] = ["key" => "notifications", "href" => "notifications.php", "icon" => "bell", "label" => "Notifications"];
            }
            if ($isDean) {
                $more[] = ["key" => "assignatories", "href" => "assignatories.php", "icon" => "users-round", "label" => "Assignatories"];
            }
            if ($isDsa) {
                $more[] = ["key" => "ssc_assignatories", "href" => "ssc_assignatories.php", "icon" => "users-round", "label" => "SSC"];
            }
            return [$primary, $more];
        }

        // Admin: 4 primaries + everything else under More.
        $primary = [
            ["key" => "dashboard", "href" => "dashboard.php", "icon" => "layout-dashboard", "label" => "Home"],
            ["key" => "students", "href" => "students.php", "icon" => "graduation-cap", "label" => "Students"],
            ["key" => "clearance", "href" => "clearance.php", "icon" => "file-check-2", "label" => "Clearance"],
            ["key" => "notifications", "href" => "notifications.php", "icon" => "bell", "label" => "Alerts"],
        ];
        $more = [
            ["key" => "offices", "href" => "offices.php", "icon" => "building-2", "label" => "Offices"],
            ["key" => "courses", "href" => "courses.php", "icon" => "book-open-text", "label" => "Courses"],
            ["key" => "assignatories", "href" => "assignatories.php", "icon" => "user-round-cog", "label" => "Assignatories"],
            ["key" => "institutional_clubs", "href" => "institutional_clubs.php", "icon" => "users-round", "label" => "Clubs"],
            ["key" => "requirement_types", "href" => "requirement_types.php", "icon" => "list-checks", "label" => "Requirements"],
            ["key" => "school_terms", "href" => "school_terms.php", "icon" => "calendar-days", "label" => "Terms"],
            ["key" => "reports", "href" => "reports.php", "icon" => "file-bar-chart", "label" => "Reports"],
            ["key" => "archive", "href" => "archive.php", "icon" => "archive", "label" => "Archive"],
            ["key" => "form_configuration", "href" => "form_configuration.php", "icon" => "file-cog", "label" => "Form Config"],
            ["key" => "users", "href" => "users.php", "icon" => "users-round", "label" => "Users"],
            ["key" => "announcements", "href" => "announcements.php", "icon" => "megaphone", "label" => "Announcements"],
            ["key" => "settings", "href" => "settings.php", "icon" => "settings-2", "label" => "Settings"],
        ];
        return [$primary, $more];
    }
}

if (!function_exists("render_mobile_nav")) {
    function render_mobile_nav(string $role, string $activePage = "", $conn = null): void
    {
        if ($conn === null && isset($GLOBALS["conn"]) && $GLOBALS["conn"] instanceof mysqli) {
            $conn = $GLOBALS["conn"];
        }
        [$primary, $more] = mobile_nav_items($role, $conn);
        $moreKeys = [];
        foreach ($more as $m) $moreKeys[$m["key"]] = true;
        $moreActive = isset($moreKeys[$activePage]);
        ?>
        <nav class="bottom-nav" id="bottomNav" aria-label="Mobile navigation">
            <?php foreach ($primary as $item): ?>
                <a href="<?= e($item["href"]) ?>" class="bottom-nav-item <?= ($activePage === $item["key"]) ? "active" : "" ?>">
                    <i data-lucide="<?= e($item["icon"]) ?>"></i><span><?= e($item["label"]) ?></span>
                </a>
            <?php endforeach; ?>
            <?php if ($more): ?>
            <button type="button" class="bottom-nav-item bottom-nav-more <?= $moreActive ? "active" : "" ?>" id="moreNavButton" aria-haspopup="true" aria-expanded="false" aria-controls="moreSheet">
                <i data-lucide="ellipsis"></i><span>More</span>
            </button>
            <?php endif; ?>
        </nav>
        <?php if ($more): ?>
        <div class="more-sheet-backdrop" id="moreSheetBackdrop" hidden></div>
        <div class="more-sheet" id="moreSheet" role="dialog" aria-modal="true" aria-label="More pages" hidden>
            <div class="more-sheet-handle" aria-hidden="true"></div>
            <div class="more-sheet-head"><strong>More</strong><button type="button" class="icon-button" id="moreSheetClose" aria-label="Close more menu"><i data-lucide="x"></i></button></div>
            <div class="more-sheet-grid">
                <?php foreach ($more as $item): ?>
                    <a href="<?= e($item["href"]) ?>" class="more-sheet-item <?= ($activePage === $item["key"]) ? "active" : "" ?>">
                        <i data-lucide="<?= e($item["icon"]) ?>"></i><span><?= e($item["label"]) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php
    }
}
