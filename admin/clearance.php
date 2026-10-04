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

                $stmt = $conn->prepare(
                    "INSERT INTO clearance_items (clearance_id, office_id, item_name, notes, is_required, is_completed) VALUES (?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param("iissii", $clearanceId, $officeId, $cleanName, $notes, $required, $completed);
                $stmt->execute();
                $stmt->close();
            }

            $recalculateClearanceStatus($clearanceId);
            $_SESSION["flash_message"] = "Student clearance requirements saved.";
            header("Location: clearance.php?review=" . (int)$clearanceId);
            exit;
        }
    }
}

$allClearanceIds = $conn->query("SELECT clearance_id FROM clearance_requests");
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
        <p>Monitor all student clearance requests.</p>
    </div>
    <button
        type="button"
        id="addOfficeButtonTop"
        class="btn btn-primary"
        onclick="window.openClearanceModal && window.openClearanceModal();">
        <i data-lucide="plus"></i> Add office
    </button>
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
                        <td><a class="table-action" href="clearance.php?review=<?= (int)$row["clearance_id"] ?>#clearance-review-form" <?= (int)$row["clearance_id"] === $selectedReviewId ? 'data-form-target="clearance-review-form"' : "" ?>>Edit</a></td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="6" class="empty-state">No clearance requests found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

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

    <p class="clearance-template-note">Fill in the office requirements that should appear in the student's clearance form.</p>

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
