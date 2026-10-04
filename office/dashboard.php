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

$reviewRows = [];
if ($officeIds) {
    $placeholders = implode(",", array_fill(0, count($officeIds), "?"));
    $sql = "SELECT ct.clearance_id, ct.office_id, o.office_name, cr.school_year, cr.semester, s.student_number,
                CONCAT(s.first_name, ' ', s.last_name) AS student_name, s.course, ct.status, ct.remarks, ct.reviewed_at
            FROM clearance_transactions ct
            INNER JOIN clearance_requests cr ON cr.clearance_id = ct.clearance_id
            INNER JOIN students s ON s.student_id = cr.student_id
            INNER JOIN offices o ON o.office_id = ct.office_id
            WHERE ct.office_id IN (" . $placeholders . ")
            ORDER BY cr.requested_at DESC, s.student_number ASC";

    $stmt = $conn->prepare($sql);
    $types = str_repeat("i", count($officeIds));
    $stmt->bind_param($types, ...$officeIds);
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

<?php if ($officeIds): ?>
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
