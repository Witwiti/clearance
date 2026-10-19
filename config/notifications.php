<?php
// Shared notification + master-data helpers.
// Requires: $conn (mysqli) and e() from config/auth.php.
// Tables are auto-created here so existing installs self-heal on first hit.

if (!function_exists('ensure_extended_schema')) {
    function ensure_extended_schema(mysqli $conn): void
    {
        // 1. Notifications table (Data Flow Diagram / ERD requirement).
        $conn->query(
            "CREATE TABLE IF NOT EXISTS notifications (
                notification_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                title VARCHAR(150) NOT NULL,
                message TEXT NOT NULL,
                type VARCHAR(30) NOT NULL DEFAULT 'general',
                related_id INT UNSIGNED DEFAULT NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_notifications_user (user_id, is_read, created_at),
                CONSTRAINT fk_notifications_user FOREIGN KEY (user_id)
                    REFERENCES users(user_id) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // 2. Requirement Types master list (reusable per-office requirements).
        $conn->query(
            "CREATE TABLE IF NOT EXISTS requirement_types (
                requirement_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                office_id INT UNSIGNED NOT NULL,
                requirement_name VARCHAR(120) NOT NULL,
                description VARCHAR(255) DEFAULT NULL,
                is_required TINYINT(1) NOT NULL DEFAULT 1,
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_reqtype_office (office_id, status),
                CONSTRAINT fk_reqtype_office FOREIGN KEY (office_id)
                    REFERENCES offices(office_id) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // 3. School terms master data (semester + school year reference).
        $conn->query(
            "CREATE TABLE IF NOT EXISTS school_terms (
                term_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                school_year VARCHAR(20) NOT NULL,
                semester VARCHAR(30) NOT NULL,
                term_label VARCHAR(100) NOT NULL,
                is_current TINYINT(1) NOT NULL DEFAULT 0,
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_school_terms (school_year, semester)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Seed school_terms from the legacy free-text clearance_term once.
        $check = $conn->query("SELECT COUNT(*) AS c FROM school_terms");
        if ($check) {
            $count = (int)($check->fetch_assoc()['c'] ?? 0);
            $check->close();
            if ($count === 0) {
                $legacyTerm = '';
                $res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='clearance_term' LIMIT 1");
                if ($res && ($row = $res->fetch_assoc())) {
                    $legacyTerm = trim((string)$row['setting_value']);
                }
                if ($res) $res->close();
                $sem = '';
                $sy = '';
                if (preg_match('/^(1st Semester|2nd Semester|Summer)/i', $legacyTerm, $m)) $sem = $m[1];
                if (preg_match('/(\d{4}\s*-\s*\d{4})/', $legacyTerm, $m2)) $sy = preg_replace('/\s+/', '', $m2[1]);
                if ($sem === '') $sem = '2nd Semester';
                if ($sy === '') $sy = '2025-2026';
                $label = $sem . ' S.Y. ' . $sy;
                if ($legacyTerm !== '') $label = $legacyTerm;
                $stmt = $conn->prepare("INSERT IGNORE INTO school_terms (school_year, semester, term_label, is_current, status) VALUES (?, ?, ?, 1, 'active')");
                if ($stmt) {
                    $stmt->bind_param('sss', $sy, $sem, $label);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }

        // 4. Archive flag on clearance_requests.
        $col = $conn->query("SHOW COLUMNS FROM clearance_requests LIKE 'is_archived'");
        if ($col && $col->num_rows === 0) {
            $conn->query("ALTER TABLE clearance_requests ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER overall_status");
        }
        if ($col) $col->close();
        $col2 = $conn->query("SHOW COLUMNS FROM clearance_requests LIKE 'archived_at'");
        if ($col2 && $col2->num_rows === 0) {
            $conn->query("ALTER TABLE clearance_requests ADD COLUMN archived_at DATETIME DEFAULT NULL AFTER is_archived");
        }
        if ($col2) $col2->close();

        // Reverted: students may submit multiple clearance requests per term.
        // Drop the one-per-term unique key if a previous version created it.
        try {
            $idx = $conn->query("SHOW INDEX FROM clearance_requests WHERE Key_name = 'uq_student_term'");
            $hasUq = $idx && $idx->num_rows > 0;
            if ($idx) $idx->close();
            if ($hasUq) {
                $conn->query("ALTER TABLE clearance_requests DROP INDEX uq_student_term");
            }
        } catch (mysqli_sql_exception $e) {}

        // 5. Archive log table (finished clearances store).
        $conn->query(
            "CREATE TABLE IF NOT EXISTS clearance_archives (
                archive_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                clearance_id INT UNSIGNED NOT NULL UNIQUE,
                student_id INT UNSIGNED NOT NULL,
                school_year VARCHAR(20) NOT NULL,
                semester VARCHAR(30) NOT NULL,
                overall_status VARCHAR(20) NOT NULL DEFAULT 'cleared',
                archived_by INT UNSIGNED DEFAULT NULL,
                archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_archive_request FOREIGN KEY (clearance_id)
                    REFERENCES clearance_requests(clearance_id) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // 6. Institutional clubs (admin-managed, no hardcoding).
        // Each club has one moderator (office user). Students pick one club
        // at request time; only that club's moderator sees the approval.
        $conn->query(
            "CREATE TABLE IF NOT EXISTS institutional_clubs (
                club_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                club_name VARCHAR(120) NOT NULL UNIQUE,
                moderator_user_id INT UNSIGNED DEFAULT NULL,
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_club_moderator (moderator_user_id),
                INDEX idx_club_status (status),
                CONSTRAINT fk_club_moderator FOREIGN KEY (moderator_user_id)
                    REFERENCES users(user_id) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // 7. Link chosen club on requests + transactions.
        $col = $conn->query("SHOW COLUMNS FROM clearance_requests LIKE 'institutional_club_id'");
        if ($col && $col->num_rows === 0) {
            $conn->query("ALTER TABLE clearance_requests ADD COLUMN institutional_club_id INT UNSIGNED DEFAULT NULL AFTER semester");
        }
        if ($col) $col->close();
        $col = $conn->query("SHOW COLUMNS FROM clearance_transactions LIKE 'club_id'");
        if ($col && $col->num_rows === 0) {
            $conn->query("ALTER TABLE clearance_transactions ADD COLUMN club_id INT UNSIGNED DEFAULT NULL AFTER office_id");
        }
        if ($col) $col->close();
        // FKs (best-effort, ignore if already exists).
        try { $conn->query("ALTER TABLE clearance_requests ADD CONSTRAINT fk_request_club FOREIGN KEY (institutional_club_id) REFERENCES institutional_clubs(club_id) ON DELETE SET NULL ON UPDATE CASCADE"); } catch (mysqli_sql_exception $e) {}
        try { $conn->query("ALTER TABLE clearance_transactions ADD CONSTRAINT fk_transaction_club FOREIGN KEY (club_id) REFERENCES institutional_clubs(club_id) ON DELETE CASCADE ON UPDATE CASCADE"); } catch (mysqli_sql_exception $e) {}

        // 8. Generic Institutional Club office (single row; per-student club stored via club_id).
        $res = $conn->query("SELECT office_id FROM offices WHERE office_name = 'Institutional Club' LIMIT 1");
        $hasClubOffice = $res && $res->num_rows > 0;
        if ($res) $res->close();
        if (!$hasClubOffice) {
            $stmt = $conn->prepare("INSERT INTO offices (office_name, description, scope, status) VALUES ('Institutional Club', 'Institutional club clearance (student picks one club)', 'general', 'active')");
            if ($stmt) { $stmt->execute(); $stmt->close(); }
        }

        // 9. Requirement publishing: public (all students) vs custom (chosen students).
        $col = $conn->query("SHOW COLUMNS FROM requirement_types LIKE 'audience'");
        if ($col && $col->num_rows === 0) {
            $conn->query("ALTER TABLE requirement_types ADD COLUMN audience ENUM('custom','public') NOT NULL DEFAULT 'custom' AFTER is_required");
        }
        if ($col) $col->close();
        $conn->query(
            "CREATE TABLE IF NOT EXISTS requirement_targets (
                requirement_id INT UNSIGNED NOT NULL,
                student_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (requirement_id, student_id),
                INDEX idx_target_student (student_id),
                CONSTRAINT fk_target_requirement FOREIGN KEY (requirement_id)
                    REFERENCES requirement_types(requirement_id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_target_student FOREIGN KEY (student_id)
                    REFERENCES students(student_id) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        // Track which template each per-request item was published from (precise unpublish + duplicate-skip).
        $col = $conn->query("SHOW COLUMNS FROM clearance_items LIKE 'source_requirement_id'");
        if ($col && $col->num_rows === 0) {
            $conn->query("ALTER TABLE clearance_items ADD COLUMN source_requirement_id INT UNSIGNED DEFAULT NULL AFTER is_completed");
        }
        if ($col) $col->close();
        try { $conn->query("ALTER TABLE clearance_items ADD CONSTRAINT fk_item_source_requirement FOREIGN KEY (source_requirement_id) REFERENCES requirement_types(requirement_id) ON DELETE SET NULL ON UPDATE CASCADE"); } catch (mysqli_sql_exception $e) {}
    }
}

if (!function_exists('notify_user')) {
    function notify_user(mysqli $conn, int $userId, string $title, string $message, string $type = 'general', ?int $relatedId = null): bool
    {
        if ($userId <= 0 || trim($title) === '' || trim($message) === '') return false;
        // Ensure table exists (cheap IF NOT EXISTS).
        $conn->query(
            "CREATE TABLE IF NOT EXISTS notifications (
                notification_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                title VARCHAR(150) NOT NULL,
                message TEXT NOT NULL,
                type VARCHAR(30) NOT NULL DEFAULT 'general',
                related_id INT UNSIGNED DEFAULT NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_notifications_user (user_id, is_read, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, related_id) VALUES (?, ?, ?, ?, ?)");
        if (!$stmt) return false;
        $rel = $relatedId;
        // related_id defaults NULL; use 4-column insert when null
        if ($rel === null) {
            $stmt->close();
            $stmt2 = $conn->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)");
            if (!$stmt2) return false;
            $stmt2->bind_param('isss', $userId, $title, $message, $type);
            $ok = $stmt2->execute();
            $stmt2->close();
            return $ok;
        }
        $stmt->bind_param('isssi', $userId, $title, $message, $type, $rel);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('notify_role')) {
    // Notify every active user with a given role. Returns number notified.
    function notify_role(mysqli $conn, string $role, string $title, string $message, string $type = 'general', ?int $relatedId = null): int
    {
        $role = in_array($role, ['admin', 'student', 'office'], true) ? $role : 'admin';
        $res = $conn->query("SELECT user_id FROM users WHERE role='" . $conn->real_escape_string($role) . "' AND status='active'");
        if (!$res) return 0;
        $n = 0;
        while ($row = $res->fetch_assoc()) {
            if (notify_user($conn, (int)$row['user_id'], $title, $message, $type, $relatedId)) $n++;
        }
        $res->close();
        return $n;
    }
}

if (!function_exists('get_unread_notification_count')) {
    function get_unread_notification_count(mysqli $conn, int $userId): int
    {
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id=? AND is_read=0");
        if (!$stmt) return 0;
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['c'] ?? 0);
    }
}

if (!function_exists('apply_requirement_templates_to_request')) {
    // Copies applicable active templates into one request's clearance_items.
    // Public templates apply to everyone; custom templates only to mapped students.
    // Skips exact duplicates (same office + item name). Returns rows added, or false on error.
    // Pass $onlyRequirementId to apply a single template (publish-one flow).
    function apply_requirement_templates_to_request(mysqli $conn, int $clearanceId, int $studentId, ?int $onlyRequirementId = null): int|false
    {
        if ($clearanceId <= 0 || $studentId <= 0) return false;
        $tpl = $conn->query(
            "SELECT requirement_id, office_id, requirement_name AS item_name, description AS notes, is_required
             FROM requirement_types WHERE status = 'active'" .
            ($onlyRequirementId !== null ? " AND requirement_id = " . (int)$onlyRequirementId : "") .
            " ORDER BY office_id, requirement_name"
        );
        if (!$tpl) return false;
        $ins = $conn->prepare(
            "INSERT INTO clearance_items (clearance_id, office_id, item_name, notes, is_required, is_completed, source_requirement_id)
             SELECT ?, ?, ?, ?, ?, 0, ?
             WHERE NOT EXISTS (SELECT 1 FROM clearance_items WHERE clearance_id = ? AND office_id = ? AND item_name = ?)
             AND (
                 EXISTS (SELECT 1 FROM requirement_types rt WHERE rt.requirement_id = ? AND rt.status = 'active'
                         AND (rt.audience = 'public' OR EXISTS (SELECT 1 FROM requirement_targets t WHERE t.requirement_id = rt.requirement_id AND t.student_id = ?)))
             )"
        );
        if (!$ins) { $tpl->close(); return false; }
        $added = 0;
        $adopt = $conn->prepare(
            "UPDATE clearance_items SET source_requirement_id = ?
             WHERE clearance_id = ? AND office_id = ? AND item_name = ? AND source_requirement_id IS NULL"
        );
        while ($t = $tpl->fetch_assoc()) {
            $rid = (int)$t["requirement_id"];
            $oid = (int)$t["office_id"];
            $nm = trim((string)$t["item_name"]);
            $nt = trim((string)($t["notes"] ?? ""));
            $rq = (int)($t["is_required"] ?? 1);
            if ($nm === "" || $oid <= 0) continue;
            // Adopt legacy/manual duplicates (NULL source) so unpublish can track them.
            if ($adopt) {
                $adopt->bind_param("iiis", $rid, $clearanceId, $oid, $nm);
                $adopt->execute();
                if ($adopt->affected_rows > 0) $added += $adopt->affected_rows;
            }
            $ins->bind_param("iissiiiiisi", $clearanceId, $oid, $nm, $nt, $rq, $rid, $clearanceId, $oid, $nm, $rid, $studentId);
            if ($ins->execute() && $ins->affected_rows > 0) $added++;
        }
        $ins->close();
        if ($adopt) $adopt->close();
        $tpl->close();
        return $added;
    }
}

if (!function_exists('publish_requirement_template')) {
    // Central publish: sets audience + targets, pushes items to matching active
    // requests, cleans stale custom items, and ALWAYS notifies affected students
    // (even on duplicates or when no active request exists yet).
    // Returns ['requests'=>int,'published'=>int,'notified'=>int,'removed_stale'=>int] or false on error.
    function publish_requirement_template(mysqli $conn, int $requirementId, string $scope, array $targetStudentIds = []): array|false
    {
        $rid = $requirementId;
        $scope = $scope === 'public' ? 'public' : 'custom';
        $targetIds = [];
        foreach ($targetStudentIds as $tid) { $tid = (int)$tid; if ($tid > 0) $targetIds[$tid] = true; }
        $targetIds = array_keys($targetIds);
        if ($rid <= 0) return false;

        $tpl = $conn->prepare("SELECT requirement_id FROM requirement_types WHERE requirement_id = ? AND status = 'active' LIMIT 1");
        if (!$tpl) return false;
        $tpl->bind_param("i", $rid);
        $tpl->execute();
        $tplExists = (bool)$tpl->get_result()->fetch_assoc();
        $tpl->close();
        if (!$tplExists) return false;

        $up = $conn->prepare("UPDATE requirement_types SET audience = ? WHERE requirement_id = ?");
        if (!$up) return false;
        $up->bind_param("si", $scope, $rid);
        $up->execute();
        $up->close();

        $conn->query("DELETE FROM requirement_targets WHERE requirement_id = " . (int)$rid);
        if ($scope === "custom" && $targetIds) {
            $ins = $conn->prepare("INSERT IGNORE INTO requirement_targets (requirement_id, student_id) VALUES (?, ?)");
            if ($ins) {
                foreach ($targetIds as $tid) { $ins->bind_param("ii", $rid, $tid); $ins->execute(); }
                $ins->close();
            }
        }

        // Push to matching active requests.
        $reqRes = $scope === "public"
            ? $conn->query("SELECT cr.clearance_id, cr.student_id, s.user_id FROM clearance_requests cr LEFT JOIN students s ON s.student_id = cr.student_id WHERE cr.is_archived = 0")
            : $conn->query("SELECT cr.clearance_id, cr.student_id, s.user_id FROM clearance_requests cr INNER JOIN requirement_targets t ON t.student_id = cr.student_id AND t.requirement_id = " . (int)$rid . " LEFT JOIN students s ON s.student_id = cr.student_id WHERE cr.is_archived = 0");
        $published = 0;
        $requests = 0;
        $matchedUsers = [];
        if ($reqRes) {
            while ($rr = $reqRes->fetch_assoc()) {
                $requests++;
                if ((int)($rr["user_id"] ?? 0) > 0) $matchedUsers[(int)$rr["user_id"]] = true;
                if (function_exists("apply_requirement_templates_to_request")) {
                    $n = apply_requirement_templates_to_request($conn, (int)$rr["clearance_id"], (int)$rr["student_id"], $rid);
                    if ($n !== false && $n > 0) $published += $n;
                }
            }
            $reqRes->close();
        }

        // Custom scope: remove stale items from requests no longer targeted.
        $removedStale = 0;
        if ($scope === "custom") {
            $inList = $targetIds ? implode(",", array_map("intval", $targetIds)) : "0";
            $staleRes = $conn->query(
                "SELECT DISTINCT s.user_id FROM clearance_items ci " .
                "INNER JOIN clearance_requests cr ON cr.clearance_id = ci.clearance_id " .
                "INNER JOIN students s ON s.student_id = cr.student_id " .
                "WHERE ci.source_requirement_id = " . (int)$rid . " AND cr.is_archived = 0 AND cr.student_id NOT IN ($inList) AND s.user_id IS NOT NULL"
            );
            $staleUsers = [];
            if ($staleRes) {
                while ($sr = $staleRes->fetch_assoc()) $staleUsers[(int)$sr["user_id"]] = true;
                $staleRes->close();
            }
            $conn->query(
                "DELETE ci FROM clearance_items ci " .
                "INNER JOIN clearance_requests cr ON cr.clearance_id = ci.clearance_id " .
                "WHERE ci.source_requirement_id = " . (int)$rid . " AND cr.is_archived = 0 AND cr.student_id NOT IN ($inList)"
            );
            $removedStale = (int)$conn->affected_rows;
            // Those users lose the item — they are NOT in $matchedUsers; notify removal below via unpublish-style message.
            // Merge into a separate list handled by caller message; simplest: keep them notified as update too.
            foreach ($staleUsers as $uid => $_) { /* handled via $staleNotify below */ }
            // Store stale users for notification as removals (static cache via $GLOBALS).
            $GLOBALS["_publish_stale_users"] = array_keys($staleUsers);
        } else {
            $GLOBALS["_publish_stale_users"] = [];
        }

        // Resolve display name.
        $nmStmt = $conn->prepare("SELECT rt.requirement_name, o.office_name FROM requirement_types rt LEFT JOIN offices o ON o.office_id = rt.office_id WHERE rt.requirement_id = ? LIMIT 1");
        $nmRow = [];
        if ($nmStmt) {
            $nmStmt->bind_param("i", $rid);
            $nmStmt->execute();
            $nmRow = $nmStmt->get_result()->fetch_assoc() ?: [];
            $nmStmt->close();
        }
        $nmSafe = trim((string)($nmRow["requirement_name"] ?? "New requirement"));
        if ($nmSafe === "") $nmSafe = "New requirement";
        $nmText = $nmSafe;
        if (!empty($nmRow["office_name"])) $nmText .= " (" . $nmRow["office_name"] . ")";
        $scopeLabel = $scope === "public" ? "all students" : "selected students";

        $notified = 0;
        if (function_exists("notify_user")) {
            // 1) All matched request owners — ALWAYS, even when $published == 0 (duplicate re-publish).
            foreach (array_keys($matchedUsers) as $uid) {
                if ($published > 0) {
                    $ok = notify_user($conn, $uid, "New requirement: " . $nmSafe, "The administrator published a new requirement for your clearance: $nmText. Check My requirements on your dashboard.", "requirements_update", null);
                } else {
                    $ok = notify_user($conn, $uid, "Requirement update: " . $nmSafe, "The administrator published the requirement '$nmText' to $scopeLabel. It is already on your clearance — check My requirements on your dashboard.", "requirements_update", null);
                }
                if ($ok) $notified++;
            }
            // 2) Custom targets with NO active request yet — they would otherwise never hear about it.
            if ($requests === 0 && $scope === "custom" && $targetIds) {
                $inList = implode(",", array_map("intval", $targetIds));
                $tRes = $conn->query("SELECT DISTINCT user_id FROM students WHERE student_id IN ($inList) AND user_id IS NOT NULL");
                if ($tRes) {
                    while ($tr = $tRes->fetch_assoc()) {
                        $uid = (int)($tr["user_id"] ?? 0);
                        if ($uid > 0 && !isset($matchedUsers[$uid])) {
                            $ok = notify_user($conn, $uid, "New requirement: " . $nmSafe, "The administrator published a new requirement for you: $nmText. It will appear on your clearance once you submit your request.", "requirements_update", null);
                            if ($ok) $notified++;
                        }
                    }
                    $tRes->close();
                }
            }
            // 3) Public with zero requests — notify all active students so future requesters know.
            if ($requests === 0 && $scope === "public") {
                $tRes = $conn->query("SELECT DISTINCT user_id FROM students WHERE status = 'active' AND user_id IS NOT NULL");
                if ($tRes) {
                    while ($tr = $tRes->fetch_assoc()) {
                        $uid = (int)($tr["user_id"] ?? 0);
                        if ($uid > 0) {
                            $ok = notify_user($conn, $uid, "New requirement: " . $nmSafe, "The administrator published a new requirement for $scopeLabel: $nmText. It will appear on your clearance.", "requirements_update", null);
                            if ($ok) $notified++;
                        }
                    }
                    $tRes->close();
                }
            }
            // 4) Stale custom users who just lost the item.
            foreach (($GLOBALS["_publish_stale_users"] ?? []) as $uid) {
                $uid = (int)$uid;
                if ($uid > 0) {
                    if (notify_user($conn, $uid, "Requirement removed: " . $nmSafe, "The administrator changed the requirement '$nmText' to selected students only, and it was removed from your clearance.", "requirements_update", null)) $notified++;
                }
            }
        }

        return ["requests" => $requests, "published" => $published, "notified" => $notified, "removed_stale" => $removedStale];
    }
}

if (!function_exists('unpublish_requirement_template')) {
    // Central unpublish: removes all pushed items and notifies every affected student.
    // Returns ['removed'=>int,'notified'=>int].
    function unpublish_requirement_template(mysqli $conn, int $requirementId): array
    {
        $rid = (int)$requirementId;
        $notifyUsers = [];
        if ($rid > 0) {
            $uRes = $conn->query("SELECT DISTINCT s.user_id FROM clearance_items ci INNER JOIN clearance_requests cr ON cr.clearance_id = ci.clearance_id INNER JOIN students s ON s.student_id = cr.student_id WHERE ci.source_requirement_id = " . $rid . " AND s.user_id IS NOT NULL");
            if ($uRes) {
                while ($ur = $uRes->fetch_assoc()) { $uid = (int)($ur["user_id"] ?? 0); if ($uid > 0) $notifyUsers[$uid] = true; }
                $uRes->close();
            }
        }
        $nmStmt = $conn->prepare("SELECT requirement_name FROM requirement_types WHERE requirement_id = ? LIMIT 1");
        $nmRow = [];
        if ($nmStmt) {
            $nmStmt->bind_param("i", $rid);
            $nmStmt->execute();
            $nmRow = $nmStmt->get_result()->fetch_assoc() ?: [];
            $nmStmt->close();
        }
        $nmText = trim((string)($nmRow["requirement_name"] ?? "Requirement"));
        if ($nmText === "") $nmText = "Requirement";
        $removed = 0;
        if ($rid > 0) {
            $stmt = $conn->prepare("DELETE FROM clearance_items WHERE source_requirement_id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $rid);
                $stmt->execute();
                $removed = (int)$stmt->affected_rows;
                $stmt->close();
            }
        }
        $notified = 0;
        if ($removed > 0 && $notifyUsers && function_exists("notify_user")) {
            foreach (array_keys($notifyUsers) as $uid) {
                if (notify_user($conn, $uid, "Requirement removed: " . $nmText, "The administrator removed the requirement '$nmText' from your clearance.", "requirements_update", null)) $notified++;
            }
        }
        return ["removed" => $removed, "notified" => $notified];
    }
}

if (!function_exists('get_current_term')) {
    // Returns ['school_year'=>..., 'semester'=>..., 'label'=>...] from school_terms.is_current, else parses clearance_term.
    function get_current_term(mysqli $conn): array
    {
        $fallback = ['school_year' => '', 'semester' => '', 'label' => '2nd Semester S.Y. 2025 - 2026'];
        $res = $conn->query("SHOW TABLES LIKE 'school_terms'");
        $hasTerms = $res && $res->num_rows > 0;
        if ($res) $res->close();
        if ($hasTerms) {
            $r = $conn->query("SELECT school_year, semester, term_label FROM school_terms WHERE is_current=1 AND status='active' LIMIT 1");
            if ($r && ($row = $r->fetch_assoc())) {
                $r->close();
                return [
                    'school_year' => trim((string)$row['school_year']),
                    'semester' => trim((string)$row['semester']),
                    'label' => trim((string)$row['term_label']),
                ];
            }
            if ($r) $r->close();
        }
        // Fallback: legacy free-text setting.
        $t = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='clearance_term' LIMIT 1");
        $term = $fallback['label'];
        if ($t && ($row = $t->fetch_assoc()) && trim((string)$row['setting_value']) !== '') {
            $term = trim((string)$row['setting_value']);
        }
        if ($t) $t->close();
        $sem = '';
        $sy = '';
        if (preg_match('/^(1st Semester|2nd Semester|Summer)/i', $term, $m)) $sem = $m[1];
        if (preg_match('/(\d{4}\s*-\s*\d{4})/', $term, $m2)) $sy = preg_replace('/\s+/', '', $m2[1]);
        return ['school_year' => $sy, 'semester' => $sem, 'label' => $term];
    }
}
