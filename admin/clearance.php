<?php
$pageTitle = "Clearance";
$activePage = "clearance";
require_once "../config/auth.php";
require_role("admin");
require_once "../config/db.php";

$conn->query(
    "CREATE TABLE IF NOT EXISTS clearance_items (
        item_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        clearance_id INT UNSIGNED NOT NULL,
        office_id INT UNSIGNED NOT NULL,
        item_name VARCHAR(120) NOT NULL,
        notes VARCHAR(255) DEFAULT NULL,
        is_required TINYINT(1) NOT NULL DEFAULT 1,
        is_completed TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_clearance_item_request
            FOREIGN KEY (clearance_id) REFERENCES clearance_requests(clearance_id)
            ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_clearance_item_office
            FOREIGN KEY (office_id) REFERENCES offices(office_id)
            ON DELETE CASCADE ON UPDATE CASCADE
    )"
);

$message = $_SESSION["flash_message"] ?? "";
unset($_SESSION["flash_message"]);
$error = "";

$recalculateClearanceStatus = function (int $clearanceId): void {
    global $conn;

    $stmt = $conn->prepare("SELECT status FROM clearance_transactions WHERE clearance_id = ?");
    $stmt->bind_param("i", $clearanceId);
    $stmt->execute();
    $result = $stmt->get_result();

    $total = 0;
    $rejected = 0;
    $approved = 0;
    while ($row = $result->fetch_assoc()) {
        $total++;
        $status = (string)($row["status"] ?? "pending");
        if ($status === "rejected") {
            $rejected++;
        } elseif ($status === "approved") {
            $approved++;
        }
    }
    $stmt->close();

    $overallStatus = "pending";
    if ($total === 0) {
        $overallStatus = "pending";
    } elseif ($rejected > 0) {
        $overallStatus = "not_cleared";
    } elseif ($approved === $total) {
        $overallStatus = "cleared";
    }

    $updateStmt = $conn->prepare("UPDATE clearance_requests SET overall_status = ? WHERE clearance_id = ?");
    $updateStmt->bind_param("si", $overallStatus, $clearanceId);
    $updateStmt->execute();
    $updateStmt->close();
};

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    if ($action === "save_requirements") {
        $clearanceId = (int)($_POST["clearance_id"] ?? 0);
        if ($clearanceId > 0) {
            $removedOfficeIds = [];
            foreach (($_POST["removed_office_ids"] ?? []) as $value) {
                $officeId = (int)$value;
                if ($officeId > 0) {
                    $removedOfficeIds[$officeId] = true;
                }
            }

            // Keep publish-tracking across the rebuild: remember each item's source template.
            $sourceMap = [];
            $srcStmt = $conn->prepare("SELECT office_id, item_name, source_requirement_id FROM clearance_items WHERE clearance_id = ? AND source_requirement_id IS NOT NULL");
            if ($srcStmt) {
                $srcStmt->bind_param("i", $clearanceId);
                $srcStmt->execute();
                $srcRes = $srcStmt->get_result();
                while ($sr = $srcRes->fetch_assoc()) $sourceMap[(int)$sr["office_id"] . "|" . trim((string)$sr["item_name"])] = (int)$sr["source_requirement_id"];
                $srcStmt->close();
            }

            $conn->query("DELETE FROM clearance_items WHERE clearance_id = " . (int)$clearanceId);

            $itemNames = $_POST["item_name"] ?? [];
            $itemOffices = $_POST["item_office"] ?? [];
            $itemNotes = $_POST["item_notes"] ?? [];
            $itemRequired = $_POST["item_required"] ?? [];
            $itemCompleted = $_POST["item_completed"] ?? [];

            foreach ($itemNames as $index => $itemName) {
                $cleanName = trim((string)$itemName);
                if ($cleanName === "") continue;

                $officeId = (int)($itemOffices[$index] ?? 0);
                if ($officeId <= 0 || isset($removedOfficeIds[$officeId])) continue;

                $required = isset($itemRequired[$index]) ? 1 : 0;
                $completed = isset($itemCompleted[$index]) ? 1 : 0;
                $notes = trim((string)($itemNotes[$index] ?? ""));
                $srcId = $sourceMap[$officeId . "|" . $cleanName] ?? null;

                if ($srcId !== null) {
                    $stmt = $conn->prepare(
                        "INSERT INTO clearance_items (clearance_id, office_id, item_name, notes, is_required, is_completed, source_requirement_id) VALUES (?, ?, ?, ?, ?, ?, ?)"
                    );
                    $stmt->bind_param("iissiii", $clearanceId, $officeId, $cleanName, $notes, $required, $completed, $srcId);
                } else {
                    $stmt = $conn->prepare(
                        "INSERT INTO clearance_items (clearance_id, office_id, item_name, notes, is_required, is_completed) VALUES (?, ?, ?, ?, ?, ?)"
                    );
                    $stmt->bind_param("iissii", $clearanceId, $officeId, $cleanName, $notes, $required, $completed);
                }
                $stmt->execute();
                $stmt->close();
            }

            $recalculateClearanceStatus($clearanceId);
            // Notify the student that requirements were updated.
            $su = $conn->prepare("SELECT s.user_id FROM clearance_requests cr INNER JOIN students s ON s.student_id=cr.student_id WHERE cr.clearance_id=? LIMIT 1");
            if ($su) {
                $su->bind_param("i", $clearanceId);
                $su->execute();
                $sr = $su->get_result()->fetch_assoc();
                $su->close();
                if ($sr && (int)($sr["user_id"] ?? 0) > 0 && function_exists('notify_user')) {
                    notify_user($conn, (int)$sr["user_id"], 'Clearance requirements updated', 'An administrator updated the office requirements on your clearance request. Please review My Clearance.', 'requirements_update', $clearanceId);
                }
            }
            $_SESSION["flash_message"] = "Student clearance requirements saved.";
            header("Location: clearance.php");
            exit;
        }
    } elseif ($action === "publish_template") {
        // Official publish: public → all active requests (+ future auto-attach); custom → chosen students. Always notifies.
        $rid = (int)($_POST["requirement_id"] ?? 0);
        $scope = ($_POST["scope"] ?? "custom") === "public" ? "public" : "custom";
        $targetIds = [];
        foreach (($_POST["target_students"] ?? []) as $tid) {
            $tid = (int)$tid;
            if ($tid > 0) $targetIds[$tid] = true;
        }
        $targetIds = array_keys($targetIds);
        if ($rid <= 0) {
            $error = "Invalid requirement.";
        } elseif ($scope === "custom" && !$targetIds) {
            $error = "Choose at least one student for custom publish.";
        } elseif (!function_exists("publish_requirement_template")) {
            $error = "Publish helper missing.";
        } else {
            $res = publish_requirement_template($conn, $rid, $scope, $targetIds);
            if ($res === false) {
                $error = "Only active requirement types can be published.";
            } else {
                $_SESSION["flash_message"] = $res["requests"] > 0
                    ? "Published to {$res["requests"]} request(s) — {$res["published"]} item(s) added (duplicates skipped)" . ($res["removed_stale"] > 0 ? ", {$res["removed_stale"]} stale removed" : "") . ". {$res["notified"]} student(s) notified."
                    : "Scope saved. {$res["notified"]} student(s) notified (no matching active requests yet).";
                header("Location: clearance.php");
                exit;
            }
        }
    } elseif ($action === "unpublish_template") {
        $rid = (int)($_POST["requirement_id"] ?? 0);
        if ($rid > 0 && function_exists("unpublish_requirement_template")) {
            $res = unpublish_requirement_template($conn, $rid);
            $_SESSION["flash_message"] = "Unpublished — {$res["removed"]} item(s) removed from requests. {$res["notified"]} student(s) notified.";
            header("Location: clearance.php");
            exit;
        }
    } elseif ($action === "archive" && isset($_POST["clearance_id"])) {
        $clearanceId = (int)$_POST["clearance_id"];
        if ($clearanceId > 0) {
            $info = $conn->prepare("SELECT student_id, school_year, semester, overall_status FROM clearance_requests WHERE clearance_id=? LIMIT 1");
            $info->bind_param("i", $clearanceId);
            $info->execute();
            $ir = $info->get_result()->fetch_assoc();
            $info->close();
            if ($ir) {
                $conn->query("INSERT IGNORE INTO clearance_archives (clearance_id, student_id, school_year, semester, overall_status, archived_by) VALUES (" . (int)$clearanceId . "," . (int)$ir["student_id"] . ",'" . $conn->real_escape_string($ir["school_year"]) . "','" . $conn->real_escape_string($ir["semester"]) . "','" . $conn->real_escape_string($ir["overall_status"]) . "'," . (int)$_SESSION["user_id"] . ")");
                $up = $conn->prepare("UPDATE clearance_requests SET is_archived=1, archived_at=NOW() WHERE clearance_id=?");
                $up->bind_param("i", $clearanceId);
                $up->execute();
                $up->close();
                $_SESSION["flash_message"] = "Clearance archived.";
            }
            header("Location: clearance.php");
            exit;
        }
    }
}

$allClearanceIds = $conn->query("SELECT clearance_id FROM clearance_requests WHERE is_archived=0");
if ($allClearanceIds) {
    while ($request = $allClearanceIds->fetch_assoc()) {
        $recalculateClearanceStatus((int)($request["clearance_id"] ?? 0));
    }
}

$rows = $conn->query(
    "SELECT cr.clearance_id, s.student_number,
            CONCAT(s.first_name, ' ', s.last_name) AS student_name,
            cr.school_year, cr.semester, cr.overall_status, cr.requested_at
     FROM clearance_requests cr
     INNER JOIN students s ON s.student_id = cr.student_id
     WHERE cr.is_archived=0
     ORDER BY cr.requested_at DESC"
);

$firstClearanceId = 0;
if ($rows && $rows->num_rows) {
    $rows->data_seek(0);
    $firstRow = $rows->fetch_assoc();
    $firstClearanceId = (int)($firstRow["clearance_id"] ?? 0);
    $rows->data_seek(0);
}

$selectedReviewId = isset($_GET["review"]) ? (int)$_GET["review"] : 0;
if ($selectedReviewId <= 0 && $firstClearanceId > 0) {
    $selectedReviewId = $firstClearanceId;
}

$selectedRequest = null;
$selectedItems = [];
$officeOptions = $conn->query("SELECT office_id, office_name FROM offices ORDER BY office_name");

// Publish panel data: active templates with scope + live publish state, plus active students for custom targeting.
$publishTemplates = [];
$ptRes = $conn->query(
    "SELECT rt.requirement_id, rt.requirement_name, rt.description, rt.audience, o.office_name,
            (SELECT COUNT(*) FROM requirement_targets t WHERE t.requirement_id = rt.requirement_id) AS target_count,
            (SELECT COUNT(*) FROM clearance_items ci WHERE ci.source_requirement_id = rt.requirement_id) AS published_count
     FROM requirement_types rt LEFT JOIN offices o ON o.office_id = rt.office_id
     WHERE rt.status = 'active' ORDER BY o.office_name, rt.requirement_name"
);
if ($ptRes) {
    while ($pt = $ptRes->fetch_assoc()) $publishTemplates[] = $pt;
    $ptRes->close();
}
$publishTargets = [];
$ptgRes = $conn->query("SELECT requirement_id, student_id FROM requirement_targets");
if ($ptgRes) {
    while ($ptg = $ptgRes->fetch_assoc()) $publishTargets[(int)$ptg["requirement_id"]][] = (int)$ptg["student_id"];
    $ptgRes->close();
}
$publishStudents = [];
$psRes = $conn->query("SELECT student_id, student_number, CONCAT(first_name, ' ', last_name) AS full_name FROM students WHERE status = 'active' ORDER BY student_number ASC");
if ($psRes) {
    while ($ps = $psRes->fetch_assoc()) $publishStudents[] = $ps;
    $psRes->close();
}

if ($selectedReviewId > 0) {
    $stmt = $conn->prepare(
        "SELECT cr.clearance_id, s.student_number, CONCAT(s.first_name, ' ', s.last_name) AS student_name,
                s.course, s.year_level, cr.school_year, cr.semester, cr.overall_status
         FROM clearance_requests cr
         INNER JOIN students s ON s.student_id = cr.student_id
         WHERE cr.clearance_id = ?"
    );
    $stmt->bind_param("i", $selectedReviewId);
    $stmt->execute();
    $selectedRequest = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($selectedRequest) {
        $stmt = $conn->prepare(
            "SELECT ci.item_id, ci.office_id, o.office_name, ci.item_name, ci.notes, ci.is_required, ci.is_completed
             FROM clearance_items ci
             LEFT JOIN offices o ON o.office_id = ci.office_id
             WHERE ci.clearance_id = ?
             ORDER BY o.office_name, ci.item_name"
        );
        $stmt->bind_param("i", $selectedReviewId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $selectedItems[] = $row;
        }
        $stmt->close();
    }
}

require "_header.php";
?>
<div class="page-heading">
    <div>
        <h1>Clearance</h1>
        <p>Monitor all student clearance requests. Active only — finished records live in <a href="archive.php">Archive</a>.</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a class="btn btn-secondary" href="archive.php"><i data-lucide="archive"></i> Archive</a>
        <a class="btn btn-secondary" href="reports.php"><i data-lucide="file-bar-chart"></i> Reports</a>
        <button
            type="button"
            id="addOfficeButtonTop"
            class="btn btn-primary"
            onclick="window.openClearanceModal && window.openClearanceModal();">
            <i data-lucide="plus"></i> Add office
        </button>
    </div>
</div>

<?php if ($message !== ""): ?><div class="notice notice-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ""): ?><div class="notice notice-error"><?= e($error) ?></div><?php endif; ?>

<div class="filter-row">
    <div class="search-field">
        <i data-lucide="search"></i>
        <input type="search" placeholder="Search student or student number...">
    </div>
    <select><option>All Statuses</option><option>Pending</option><option>Cleared</option><option>Not Cleared</option></select>
</div>

<div class="table-card">
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Student</th><th>School Year</th><th>Semester</th><th>Overall Status</th><th>Requested</th><th>Action</th></tr></thead>
            <tbody>
            <?php if ($rows && $rows->num_rows): ?>
                <?php while ($row = $rows->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?= e($row["student_name"]) ?></strong><small><?= e($row["student_number"]) ?></small></td>
                        <td><?= e($row["school_year"]) ?></td>
                        <td><?= e($row["semester"]) ?></td>
                        <td><span class="status status-<?= e($row["overall_status"]) ?>"><?= e(ucwords(str_replace("_"," ",$row["overall_status"]))) ?></span></td>
                        <td><?= e(date("M d, Y", strtotime($row["requested_at"]))) ?></td>
                        <td class="action-group">
                            <a class="table-action" href="clearance.php?review=<?= (int)$row["clearance_id"] ?>#clearance-review-form" <?= (int)$row["clearance_id"] === $selectedReviewId ? 'data-form-target="clearance-review-form"' : "" ?>>Edit</a>
                            <?php if (($row["overall_status"] ?? "") === "cleared"): ?>
                                <a class="table-action" href="certificate.php?request=<?= (int)$row["clearance_id"] ?>" target="_blank">Certificate</a>
                            <?php endif; ?>
                            <form method="post" style="display:inline;" data-delete-confirm="Archive this clearance? It will move to Archive.">
                                <input type="hidden" name="action" value="archive">
                                <input type="hidden" name="clearance_id" value="<?= (int)$row["clearance_id"] ?>">
                                <button class="table-action" type="submit">Archive</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="6" class="empty-state">No active clearance requests. <a href="archive.php">View archive</a>.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="page-heading" style="margin-top:18px;">
    <div>
        <h2>Publish requirements</h2>
        <p>Officially push a requirement to requests: public goes to all active requests (and auto-attaches to new ones), custom goes to chosen students only.</p>
    </div>
    <a class="btn btn-primary" href="#publish-form" data-form-target="publish-form"><i data-lucide="send"></i> Publish</a>
</div>

<div class="form-card announcement-form floating-form" id="publish-form">
    <div class="floating-form-header">
        <h2>Publish requirement</h2>
        <button class="floating-form-close" type="button" aria-label="Close form"><i data-lucide="x"></i></button>
    </div>
    <form method="post">
        <input type="hidden" name="action" value="publish_template">
        <label>Requirement
            <select name="requirement_id" id="publishRequirement" required>
                <option value="">Select requirement</option>
                <?php foreach ($publishTemplates as $pt): ?>
                    <option value="<?= (int)$pt["requirement_id"] ?>" data-audience="<?= e($pt["audience"] ?? "custom") ?>" data-targets="<?= e(implode(",", $publishTargets[(int)$pt["requirement_id"]] ?? [])) ?>"><?= e(($pt["office_name"] ?: "—") . " — " . $pt["requirement_name"]) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="checkbox-field"><input type="radio" name="scope" value="public" id="scopePublic"> All students</label>
        <label class="checkbox-field"><input type="radio" name="scope" value="custom" id="scopeCustom" checked> Chosen students</label>
        <label>Custom students (Ctrl/Cmd+click for multiple)
            <select name="target_students[]" multiple size="8">
                <?php foreach ($publishStudents as $ps): ?>
                    <option value="<?= (int)$ps["student_id"] ?>"><?= e($ps["student_number"] . " — " . $ps["full_name"]) ?></option>
                <?php endforeach; ?>
            </select>
            <small class="field-help">Only used for chosen-students scope.</small>
        </label>
        <button class="btn btn-primary" type="submit"><i data-lucide="send"></i>Publish</button>
    </form>
</div>

<div class="table-card" id="publish-requirements">
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Requirement</th><th>Scope</th><th>State</th><th>Action</th></tr></thead>
            <tbody>
            <?php if ($publishTemplates): ?>
                <?php foreach ($publishTemplates as $pt): $ptId = (int)$pt["requirement_id"]; $ptPublished = (int)($pt["published_count"] ?? 0) > 0; $ptTargets = $publishTargets[$ptId] ?? []; ?>
                    <tr>
                        <td><strong><?= e($pt["requirement_name"]) ?></strong><small><?= e(($pt["office_name"] ?: "—") . ($pt["description"] ? " · " . $pt["description"] : "")) ?></small></td>
                        <td><?= ($pt["audience"] ?? "custom") === "public" ? "Public" : "Custom" . ($ptTargets ? " (" . count($ptTargets) . ")" : "") ?></td>
                        <td>
                            <?php if ($ptPublished): ?>
                                <span class="status status-approved">Published</span>
                            <?php else: ?>
                                <span class="status status-pending">Not published</span>
                            <?php endif; ?>
                        </td>
                        <td class="action-group">
                            <?php if ($ptPublished): ?>
                                <form method="post" data-delete-confirm="Unpublish <?= e($pt["requirement_name"]) ?>? Removes its items from all requests." data-confirm-title="Unpublish requirement?" data-confirm-action="Unpublish">
                                    <input type="hidden" name="action" value="unpublish_template">
                                    <input type="hidden" name="requirement_id" value="<?= $ptId ?>">
                                    <button class="table-action" type="submit">Unpublish</button>
                                </form>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="4" class="empty-state">No active requirement types. Add them in <a href="requirement_types.php">Requirement Types</a>.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    const reqSel = document.getElementById("publishRequirement");
    const scopePublic = document.getElementById("scopePublic");
    const scopeCustom = document.getElementById("scopeCustom");
    if (!reqSel || !scopePublic || !scopeCustom) return;
    const syncScope = () => {
        const opt = reqSel.options[reqSel.selectedIndex];
        if (!opt || !opt.value) return;
        const isPublic = (opt.dataset.audience || "custom") === "public";
        scopePublic.checked = isPublic;
        scopeCustom.checked = !isPublic;
        const targets = (opt.dataset.targets || "").split(",").filter(Boolean);
        const studentSel = reqSel.closest("form")?.querySelector('select[name="target_students[]"]');
        if (studentSel) {
            [...studentSel.options].forEach((o) => { o.selected = targets.includes(o.value); });
        }
    };
    reqSel.addEventListener("change", syncScope);
})();
</script>

<div class="form-card announcement-form floating-form clearance-template-editor <?= isset($_GET["review"]) && $selectedRequest ? "is-open" : "" ?>" id="clearance-review-form">
    <div class="floating-form-header clearance-template-header">
        <h2>Student Clearance Content</h2>
        <button class="floating-form-close" type="button" aria-label="Close form"><i data-lucide="x"></i></button>
    </div>

    <?php if ($selectedRequest): ?>
        <div class="student-identity clearance-template-identity">
            <div class="student-identity-mark"><i data-lucide="user-round"></i></div>
            <div>
                <strong><?= e($selectedRequest["student_name"]) ?></strong>
                <span><?= e($selectedRequest["student_number"]) ?> · <?= e($selectedRequest["course"] ?: "No course") ?> · <?= e($selectedRequest["year_level"] ?: "N/A") ?></span>
            </div>
        </div>
    <?php endif; ?>

    <p class="clearance-template-note">Per-student manual items (one-off). To push office requirements to students, use “Publish requirements” above with Public / Custom scope — publishing or unpublishing automatically sends a notification to the affected students.</p>

    <form method="post" class="clearance-template-form">
        <input type="hidden" name="action" value="save_requirements">
        <input type="hidden" name="clearance_id" value="<?= (int)($selectedRequest["clearance_id"] ?? 0) ?>">

        <div id="requirement-items" class="clearance-template-items">
            <?php if ($selectedItems): ?>
                <?php foreach ($selectedItems as $index => $item): ?>
                    <div class="requirement-row clearance-template-row" data-index="<?= (int)$index ?>">
                        <div class="clearance-template-grid">
                            <label class="clearance-template-label">Office
                                <select name="item_office[<?= (int)$index ?>]">
                                    <option value="">Select office</option>
                                    <?php if ($officeOptions): $officeOptions->data_seek(0); while ($office = $officeOptions->fetch_assoc()): ?>
                                        <option value="<?= (int)$office["office_id"] ?>" <?= (int)($item["office_id"] ?? 0) === (int)$office["office_id"] ? "selected" : "" ?>><?= e($office["office_name"]) ?></option>
                                    <?php endwhile; endif; ?>
                                </select>
                            </label>
                            <label class="clearance-template-label">Requirement / Item
                                <input name="item_name[<?= (int)$index ?>]" value="<?= e($item["item_name"]) ?>" placeholder="e.g. Good Moral Certificate">
                            </label>
                        </div>
                        <label class="clearance-template-label">Notes
                            <textarea name="item_notes[<?= (int)$index ?>]" rows="2" placeholder="Optional note or details for this requirement"><?= e($item["notes"] ?: "") ?></textarea>
                        </label>
                        <div style="display:flex; gap:18px; flex-wrap:wrap;">
                            <label style="display:flex; align-items:center; gap:8px; font-size:12px; font-weight:600;"><input type="checkbox" name="item_required[<?= (int)$index ?>]" value="1" <?= !empty($item["is_required"]) ? "checked" : "" ?>> Required</label>
                            <label style="display:flex; align-items:center; gap:8px; font-size:12px; font-weight:600;"><input type="checkbox" name="item_completed[<?= (int)$index ?>]" value="1" <?= !empty($item["is_completed"]) ? "checked" : "" ?>> Completed</label>
                        </div>
                        <div style="display:flex; justify-content:flex-end;">
                            <button type="button" class="table-action table-action-danger delete-item-button">Delete item</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="requirement-row clearance-template-row" data-index="0">
                    <div class="clearance-template-grid">
                        <label class="clearance-template-label">Office
                            <select name="item_office[0]">
                                <option value="">Select office</option>
                                <?php if ($officeOptions): $officeOptions->data_seek(0); while ($office = $officeOptions->fetch_assoc()): ?>
                                    <option value="<?= (int)$office["office_id"] ?>"><?= e($office["office_name"]) ?></option>
                                <?php endwhile; endif; ?>
                            </select>
                        </label>
                        <label class="clearance-template-label">Requirement / Item
                            <input name="item_name[0]" value="" placeholder="e.g. Good Moral Certificate">
                        </label>
                    </div>
                    <label class="clearance-template-label">Notes
                        <textarea name="item_notes[0]" rows="2" placeholder="Optional note or details for this requirement"></textarea>
                    </label>
                    <div style="display:flex; gap:18px; flex-wrap:wrap;">
                        <label style="display:flex; align-items:center; gap:8px; font-size:12px; font-weight:600;"><input type="checkbox" name="item_required[0]" value="1" checked> Required</label>
                        <label style="display:flex; align-items:center; gap:8px; font-size:12px; font-weight:600;"><input type="checkbox" name="item_completed[0]" value="1"> Completed</label>
                    </div>
                    <div style="display:flex; justify-content:flex-end;">
                        <button type="button" class="table-action table-action-danger delete-item-button">Delete item</button>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="clearance-template-actions">
            <button type="button" class="btn btn-secondary" id="addRequirementButton" onclick="window.addClearanceRow && window.addClearanceRow();">Add item</button>
            <button class="btn btn-primary" type="submit">Save clearance content</button>
        </div>
    </form>
    <?php if ($selectedRequest): ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;">
        <?php if (($selectedRequest["overall_status"] ?? "") === "cleared"): ?>
            <a class="btn btn-secondary" href="certificate.php?request=<?= (int)$selectedRequest["clearance_id"] ?>" target="_blank"><i data-lucide="award"></i> View certificate</a>
        <?php endif; ?>
        <a class="btn btn-secondary" href="requirement_types.php"><i data-lucide="settings-2"></i> Manage templates</a>
    </div>
    <p class="muted" style="margin-top:8px;font-size:12px;">Tip: publish office requirements from the “Publish requirements” section above (Public = all students, Custom = chosen students). Publishing / unpublishing automatically notifies the affected students.</p>
    <?php endif; ?>
</div>

<script>
(function () {
    const container = document.getElementById('requirement-items');

    if (!container) return;

    function addClearanceRow() {
        const rows = container.querySelectorAll('.requirement-row');
        let newIndex = 0;
        rows.forEach(function (r) {
            const v = Number(r.dataset.index || 0);
            if (v >= newIndex) newIndex = v + 1;
        });
        const row = document.createElement('div');
        row.className = 'requirement-row';
        row.dataset.index = String(newIndex);
        row.style.display = 'grid';
        row.style.gap = '8px';
        row.style.padding = '12px';
        row.style.border = '1px solid var(--border)';
        row.style.borderRadius = '10px';
        row.style.background = 'var(--surface)';
        row.innerHTML = `
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                <label>Office
                    <select name="item_office[${newIndex}]">
                        <option value="">Select office</option>
                        ${officeOptionsHtml}
                    </select>
                </label>
                <label>Requirement name<input name="item_name[${newIndex}]" value="" placeholder="e.g. Good Moral Certificate"></label>
            </div>
            <label>Notes<textarea name="item_notes[${newIndex}]" rows="2" placeholder="Optional note or compliance detail"></textarea></label>
            <div style="display:flex; gap:18px; flex-wrap:wrap;">
                <label style="display:flex; align-items:center; gap:8px; font-size:12px; text-transform:none; letter-spacing:normal; font-weight:600; color:var(--text);"><input type="checkbox" name="item_required[${newIndex}]" value="1" checked> Required</label>
                <label style="display:flex; align-items:center; gap:8px; font-size:12px; text-transform:none; letter-spacing:normal; font-weight:600; color:var(--text);"><input type="checkbox" name="item_completed[${newIndex}]" value="1"> Completed</label>
            </div>
            <div style="display:flex; justify-content:flex-end;">
                <button type="button" class="table-action table-action-danger delete-item-button">Delete item</button>
            </div>
        `;
        container.appendChild(row);
        bindSingleRowEvents(row);
    }

    function openClearanceModal() {
        const form = document.getElementById('clearance-review-form');
        if (form) {
            form.classList.add('is-open');
            document.body.classList.add('modal-open');
            const firstField = form.querySelector('input:not([type="hidden"]), textarea, select');
            if (firstField) firstField.focus();
        }
    }

    window.addClearanceRow = addClearanceRow;
    window.openClearanceModal = openClearanceModal;

    const officeOptionsHtml = `<?php
        $optionsHtml = "";
        if ($officeOptions) {
            $officeOptions->data_seek(0);
            while ($office = $officeOptions->fetch_assoc()) {
                $optionsHtml .= '<option value="' . (int)$office['office_id'] . '">' . e($office['office_name']) . '</option>';
            }
        }
        echo str_replace("\n", "", $optionsHtml);
    ?>`;

    function addRemovedOffice(hiddenName, officeId) {
        const existing = document.querySelector('input[name="' + hiddenName + '[]"][value="' + officeId + '"]');
        if (existing) return;

        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = hiddenName + '[]';
        hidden.value = String(officeId);
        document.querySelector('.clearance-template-form').appendChild(hidden);
    }

    function bindSingleRowEvents(row) {
        const deleteButton = row.querySelector('.delete-item-button');
        if (deleteButton) {
            deleteButton.addEventListener('click', function () {
                const officeCell = row.querySelector('select[name^="item_office["]');
                const selectedOffice = officeCell ? officeCell.value : "";

                if (selectedOffice) {
                    addRemovedOffice('removed_office_ids', selectedOffice);
                }

                row.remove();
            });
        }
    }

    container.querySelectorAll('.requirement-row').forEach(function (row) {
        bindSingleRowEvents(row);
    });
})();
</script>

<?php require "_footer.php"; ?>
