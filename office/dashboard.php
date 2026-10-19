<?php
$pageTitle = "Office Dashboard";
$activePage = "dashboard";
require_once "../config/db.php";
require_once "../config/auth.php";
require_role("office");

$message = "";
$error = "";

$officeIds = [];
$currentOfficeUserId = (int)($_SESSION["user_id"] ?? 0);
$officeStmt = $conn->prepare("SELECT DISTINCT office_id FROM office_assignatories WHERE status = 'active' AND (user_id = ? OR assigned_by_user_id = ?)");
$officeStmt->bind_param("ii", $currentOfficeUserId, $currentOfficeUserId);
$officeStmt->execute();
$officeResult = $officeStmt->get_result();
while ($office = $officeResult->fetch_assoc()) {
    $officeIds[] = (int)($office["office_id"] ?? 0);
}
$officeStmt->close();

// Institutional clubs moderated by this user (admin-managed, no hardcoding).
$clubIds = [];
$clubStmt = $conn->prepare("SELECT club_id FROM institutional_clubs WHERE moderator_user_id = ? AND status = 'active'");
if ($clubStmt) {
    $clubStmt->bind_param("i", $currentOfficeUserId);
    $clubStmt->execute();
    $clubRes = $clubStmt->get_result();
    while ($cr = $clubRes->fetch_assoc()) $clubIds[] = (int)($cr["club_id"] ?? 0);
    $clubStmt->close();
}
$clubIds = array_values(array_unique(array_filter($clubIds)));

$reviewRows = [];
if ($officeIds || $clubIds) {
    $conds = [];
    $bindValues = [];
    $bindTypes = "";
    if ($officeIds) {
        $placeholders = implode(",", array_fill(0, count($officeIds), "?"));
        $conds[] = "ct.office_id IN (" . $placeholders . ")";
        $bindValues = array_merge($bindValues, $officeIds);
        $bindTypes .= str_repeat("i", count($officeIds));
    }
    if ($clubIds) {
        $placeholders = implode(",", array_fill(0, count($clubIds), "?"));
        $conds[] = "ct.club_id IN (" . $placeholders . ")";
        $bindValues = array_merge($bindValues, $clubIds);
        $bindTypes .= str_repeat("i", count($clubIds));
    }
    $sql = "SELECT ct.clearance_id, ct.office_id, ct.club_id,
                CASE WHEN ct.club_id IS NOT NULL THEN CONCAT('Institutional Club - ', c.club_name) ELSE o.office_name END AS office_name,
                cr.school_year, cr.semester, s.student_number,
                CONCAT(s.first_name, ' ', s.last_name) AS student_name, s.course, ct.status, ct.remarks, ct.reviewed_at
            FROM clearance_transactions ct
            INNER JOIN clearance_requests cr ON cr.clearance_id = ct.clearance_id
            INNER JOIN students s ON s.student_id = cr.student_id
            INNER JOIN offices o ON o.office_id = ct.office_id
            LEFT JOIN institutional_clubs c ON c.club_id = ct.club_id
            WHERE (" . implode(" OR ", $conds) . ") AND cr.is_archived=0
            ORDER BY cr.requested_at DESC, s.student_number ASC";

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

$assignedOfficeLabel = "Office assignment";
if ($officeIds) {
    $officeNameStmt = $conn->prepare("SELECT office_name FROM offices WHERE office_id = ? LIMIT 1");
    $officeNameStmt->bind_param("i", $officeIds[0]);
    $officeNameStmt->execute();
    $officeNameResult = $officeNameStmt->get_result()->fetch_assoc();
    if ($officeNameResult) {
        $assignedOfficeLabel = $officeNameResult["office_name"];
    }
    $officeNameStmt->close();
} elseif ($clubIds) {
    $clubNameStmt = $conn->prepare("SELECT club_name FROM institutional_clubs WHERE club_id = ? LIMIT 1");
    $clubNameStmt->bind_param("i", $clubIds[0]);
    $clubNameStmt->execute();
    $clubNameResult = $clubNameStmt->get_result()->fetch_assoc();
    if ($clubNameResult) {
        $assignedOfficeLabel = "Institutional Club - " . $clubNameResult["club_name"];
    }
    $clubNameStmt->close();
}

require "_header.php";
?>
<div class="page-heading">
    <div>
        <h1>Office Dashboard</h1>
        <p>Your office overview and current clearance workload.</p>
    </div>
    <a class="btn btn-primary" href="reviews.php"><i data-lucide="file-check-2"></i> Open reviews</a>
</div>

<?php if ($message !== ""): ?><div class="notice notice-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ""): ?><div class="notice notice-error"><?= e($error) ?></div><?php endif; ?>

<?php if ($officeIds || $clubIds): ?>
    <section class="section-block">
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon"><i data-lucide="clock-3"></i></div>
                <div>
                    <span class="stat-label">Pending reviews</span>
                    <strong><?= (int)$pendingCount ?></strong>
                    <small>Awaiting your decision</small>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i data-lucide="circle-check-big"></i></div>
                <div>
                    <span class="stat-label">Approved</span>
                    <strong><?= (int)$approvedCount ?></strong>
                    <small>Cleared this cycle</small>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i data-lucide="x-circle"></i></div>
                <div>
                    <span class="stat-label">Rejected</span>
                    <strong><?= (int)$rejectedCount ?></strong>
                    <small>Needs attention</small>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i data-lucide="building-2"></i></div>
                <div>
                    <span class="stat-label">Assigned office</span>
                    <strong><?= e($assignedOfficeLabel) ?></strong>
                    <small>Current review queue</small>
                </div>
            </div>
        </div>
    </section>
<?php else: ?>
    <div class="empty-panel">
        <i data-lucide="shield-alert"></i>
        <h3>No office assignment</h3>
        <p>Your office account is not assigned to any office yet. Please contact the administrator.</p>
    </div>
<?php endif; ?>

<?php require "_footer.php"; ?>
