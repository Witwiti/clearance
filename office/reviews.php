<?php
$pageTitle = "Clearance Reviews";
$activePage = "reviews";
require_once "../config/db.php";
require_once "../config/auth.php";
require_role("office");

if (!is_dean_user()) {
    require "_header.php";
    ?>
    <div class="page-heading"><div><h1>Clearance Reviews</h1><p>Only the College dean can approve or reject department clearance reviews.</p></div></div>
    <div class="empty-panel"><i data-lucide="shield-alert"></i><h3>Access restricted</h3><p>Your office account is a department signatory, but review decisions are managed by the College dean.</p></div>
    <?php require "_footer.php"; exit();
}

$message = $_SESSION["flash_message"] ?? "";
unset($_SESSION["flash_message"]);
$error = "";

$officeIds = [];
$deanOfficeStmt = $conn->prepare("SELECT DISTINCT office_id FROM office_assignatories WHERE status = 'active' AND assigned_by_user_id = ?");
$deanOfficeStmt->bind_param("i", $_SESSION["user_id"]);
$deanOfficeStmt->execute();
$deanOfficeResult = $deanOfficeStmt->get_result();
if ($deanOfficeResult) {
    while ($office = $deanOfficeResult->fetch_assoc()) {
        $officeIds[] = (int)($office["office_id"] ?? 0);
    }
}
$deanOfficeStmt->close();
$officeIds = array_values(array_unique($officeIds));

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    if ($action === "update_transaction") {
        $clearanceId = (int)($_POST["clearance_id"] ?? 0);
        $officeId = (int)($_POST["office_id"] ?? 0);
        $status = in_array($_POST["status"] ?? "", ["pending", "approved", "rejected"], true) ? $_POST["status"] : "pending";
        $remarks = trim((string)($_POST["remarks"] ?? ""));

        if ($clearanceId > 0 && $officeId > 0 && in_array($officeId, $officeIds, true)) {
            $stmt = $conn->prepare("UPDATE clearance_transactions SET status = ?, remarks = ?, reviewed_at = NOW() WHERE clearance_id = ? AND office_id = ?");
            $stmt->bind_param("ssii", $status, $remarks, $clearanceId, $officeId);
            if ($stmt->execute()) {
                $statusStmt = $conn->prepare("SELECT status FROM clearance_transactions WHERE clearance_id = ?");
                $statusStmt->bind_param("i", $clearanceId);
                $statusStmt->execute();
                $statusResult = $statusStmt->get_result();

                $total = 0;
                $rejected = 0;
                $approved = 0;
                while ($row = $statusResult->fetch_assoc()) {
                    $total++;
                    $transactionStatus = (string)($row["status"] ?? "pending");
                    if ($transactionStatus === "rejected") {
                        $rejected++;
                    } elseif ($transactionStatus === "approved") {
                        $approved++;
                    }
                }
                $statusStmt->close();

                $overallStatus = "pending";
                if ($total === 0) {
                    $overallStatus = "pending";
                } elseif ($rejected > 0) {
                    $overallStatus = "not_cleared";
                } elseif ($approved === $total) {
                    $overallStatus = "cleared";
                }

                $updateOverallStmt = $conn->prepare("UPDATE clearance_requests SET overall_status = ? WHERE clearance_id = ?");
                $updateOverallStmt->bind_param("si", $overallStatus, $clearanceId);
                $updateOverallStmt->execute();
                $updateOverallStmt->close();

                $_SESSION["flash_message"] = "Clearance update saved.";
                header("Location: reviews.php");
                exit;
            } else {
                $error = "Unable to update this clearance review.";
            }
            $stmt->close();
        } else {
            $error = "You are not assigned to this office.";
        }
    }
}

$officeFilter = (int)($_GET["office_filter"] ?? 0);
$deanContextStmt = $conn->prepare("SELECT college_dean_course FROM users WHERE user_id = ? LIMIT 1");
$deanContextStmt->bind_param("i", $_SESSION["user_id"]);
$deanContextStmt->execute();
$deanContext = $deanContextStmt->get_result()->fetch_assoc() ?: [];
$deanContextStmt->close();
$deanCourse = trim((string)($deanContext["college_dean_course"] ?? ""));
$officeOptions = [];

$reviewRows = [];
if ($officeIds) {
    $officeOptionStmt = $conn->prepare(
        "SELECT oa.office_id, o.office_name, oa.assignatory_name, oa.position
         FROM office_assignatories oa
         INNER JOIN offices o ON o.office_id = oa.office_id
         WHERE oa.assigned_by_user_id = ? AND oa.status = 'active'
         ORDER BY o.office_name, oa.assignatory_name"
    );
    $officeOptionStmt->bind_param("i", $_SESSION["user_id"]);
    $officeOptionStmt->execute();
    $officeOptionResult = $officeOptionStmt->get_result();
    while ($officeOption = $officeOptionResult->fetch_assoc()) {
        $officeOptions[] = $officeOption;
    }
    $officeOptionStmt->close();

    $placeholders = implode(",", array_fill(0, count($officeIds), "?"));
    $sql = "SELECT ct.clearance_id, ct.office_id, o.office_name, cr.school_year, cr.semester, s.student_number,
                CONCAT(s.first_name, ' ', s.last_name) AS student_name, u.avatar_path, s.course, ct.status, ct.remarks, ct.reviewed_at,
                cr.overall_status, cr.requested_at
            FROM clearance_transactions ct
            INNER JOIN clearance_requests cr ON cr.clearance_id = ct.clearance_id
            INNER JOIN students s ON s.student_id = cr.student_id
            INNER JOIN users u ON u.user_id = s.user_id
            INNER JOIN offices o ON o.office_id = ct.office_id
                WHERE ct.office_id IN (" . $placeholders . ")
                  AND s.course = ?";

            $bindValues = $officeIds;
            $bindValues[] = $deanCourse;
            $bindTypes = str_repeat("i", count($officeIds)) . "s";

    if ($officeFilter > 0 && in_array($officeFilter, $officeIds, true)) {
        $sql .= " AND ct.office_id = ?";
        $bindTypes .= "i";
        $bindValues[] = $officeFilter;
    }

    $sql .= " ORDER BY cr.requested_at DESC, s.student_number ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($bindTypes, ...$bindValues);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $reviewRows[] = $row;
    }
    $stmt->close();
}

$pendingCount = 0;
$approvedCount = 0;
$rejectedCount = 0;
foreach ($reviewRows as $row) {
    $status = (string)($row["status"] ?? "pending");
    if ($status === "approved") {
        $approvedCount++;
    } elseif ($status === "rejected") {
        $rejectedCount++;
    } else {
        $pendingCount++;
    }
}

require "_header.php";
?>
<div class="page-heading">
    <div>
        <h1>Clearance Reviews</h1>
        <p>Review student requests assigned to your office and update each requirement.</p>
    </div>
</div>

<?php if ($message !== ""): ?><div class="notice notice-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ""): ?><div class="notice notice-error"><?= e($error) ?></div><?php endif; ?>

<?php if (!$officeIds): ?>
    <div class="empty-panel">
        <i data-lucide="shield-alert"></i>
        <h3>No office assignment</h3>
        <p>Your office account is not assigned to any office yet. Please contact the administrator.</p>
    </div>
<?php elseif (!$reviewRows): ?>
    <div class="empty-panel">
        <i data-lucide="file-check-2"></i>
        <h3>No clearance requests yet</h3>
        <p>Students will appear here once they submit a clearance request for your office.</p>
    </div>
<?php else: ?>
    <div class="toolbar-card filter-row review-toolbar">
        <div class="search-field review-search-field">
            <i data-lucide="search"></i>
            <input type="search" placeholder="Search students..." aria-label="Search clearance reviews">
        </div>
        <form method="get" class="course-filter-form">
            <label class="review-filter-control course-filter-label">
                <span>Signatory</span>
                <span class="review-select-wrap">
                    <select name="office_filter" onchange="this.form.submit()">
                        <option value="">All Signatories</option>
                        <?php foreach ($officeOptions as $officeOption): ?>
                            <option value="<?= (int)$officeOption["office_id"] ?>" <?= $officeFilter === (int)$officeOption["office_id"] ? "selected" : "" ?>><?= e($officeOption["office_name"] . " - " . ($officeOption["assignatory_name"] ?: "Unnamed signatory") . ($officeOption["position"] ? " (" . $officeOption["position"] . ")" : "")) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <i data-lucide="chevron-down" aria-hidden="true"></i>
                </span>
            </label>
        </form>
        <label class="review-filter-control review-status-filter">
            <span>Status</span>
            <span class="review-select-wrap">
                <select data-filter="status" aria-label="Filter reviews by status">
                    <option value="all_statuses">All Statuses</option>
                    <option value="approved">Cleared</option>
                    <option value="pending">Pending</option>
                    <option value="rejected">Rejected</option>
                </select>
                <i data-lucide="chevron-down" aria-hidden="true"></i>
            </span>
        </label>
    </div>

    <div class="table-card review-table-card" id="reviews">
        <div class="table-wrapper">
            <table class="review-table">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Office</th>
                        <th>School Year</th>
                        <th>Semester</th>
                        <th>Status</th>
                        <th>Updated</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reviewRows as $row): ?>
                        <tr>
                            <td>
                                <div class="student-review-cell">
                                    <div class="student-review-avatar">
                                        <?php if (!empty($row["avatar_path"])): ?>
                                            <img src="../<?= e($row["avatar_path"]) ?>" alt="<?= e($row["student_name"]) ?> profile picture">
                                        <?php else: ?>
                                            <i data-lucide="user-round"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <strong><?= e($row["student_name"]) ?></strong>
                                        <small><?= e($row["student_number"]) ?> · <?= e($row["course"] ?: "No course") ?></small>
                                    </div>
                                </div>
                            </td>
                            <td><?= e($row["office_name"]) ?></td>
                            <td><?= e($row["school_year"]) ?></td>
                            <td><?= e($row["semester"]) ?></td>
                            <td><span class="status status-<?= e($row["status"] === "approved" ? "approved" : ($row["status"] === "rejected" ? "rejected" : "pending")) ?>"><?= e(ucfirst($row["status"] ?: "pending")) ?></span></td>
                            <td><?= e($row["reviewed_at"] ? date("M d, Y", strtotime($row["reviewed_at"])) : "Waiting") ?></td>
                            <td>
                                <form method="post" class="review-action-form">
                                    <input type="hidden" name="action" value="update_transaction">
                                    <input type="hidden" name="clearance_id" value="<?= (int)$row["clearance_id"] ?>">
                                    <input type="hidden" name="office_id" value="<?= (int)$row["office_id"] ?>">
                                    <div class="review-controls">
                                        <select name="status" aria-label="Review status">
                                            <option value="pending" <?= ($row["status"] ?? "pending") === "pending" ? "selected" : "" ?>>Pending</option>
                                            <option value="approved" <?= ($row["status"] ?? "pending") === "approved" ? "selected" : "" ?>>Approved</option>
                                            <option value="rejected" <?= ($row["status"] ?? "pending") === "rejected" ? "selected" : "" ?>>Rejected</option>
                                        </select>
                                        <textarea name="remarks" rows="2" placeholder="Add approval remarks..."><?= e($row["remarks"] ?? "") ?></textarea>
                                        <button class="btn btn-primary" type="submit"><i data-lucide="save"></i>Save changes</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require "_footer.php"; ?>
