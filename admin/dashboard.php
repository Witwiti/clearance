<?php
$pageTitle = "Dashboard";
$activePage = "dashboard";
require_once "../config/db.php";

$studentCount = 0;
$officeCount = 0;
$assignatoryCount = 0;
$pendingCount = 0;
$clearedCount = 0;
$notClearedCount = 0;

$result = $conn->query("SELECT COUNT(*) AS total FROM students WHERE status='active'");
if ($result) $studentCount = (int)$result->fetch_assoc()["total"];

$result = $conn->query("SELECT COUNT(*) AS total FROM offices WHERE status='active'");
if ($result) $officeCount = (int)$result->fetch_assoc()["total"];

$result = $conn->query("SELECT COUNT(*) AS total FROM office_assignatories WHERE status='active'");
if ($result) $assignatoryCount = (int)$result->fetch_assoc()["total"];

$result = $conn->query("SELECT
    SUM(overall_status='pending') AS pending_total,
    SUM(overall_status='cleared') AS cleared_total,
    SUM(overall_status='not_cleared') AS not_cleared_total
    FROM clearance_requests");
if ($result) {
    $stats = $result->fetch_assoc();
    $pendingCount = (int)($stats["pending_total"] ?? 0);
    $clearedCount = (int)($stats["cleared_total"] ?? 0);
    $notClearedCount = (int)($stats["not_cleared_total"] ?? 0);
}

$recent = $conn->query(
    "SELECT cr.clearance_id, s.student_number,
            CONCAT(s.first_name, ' ', s.last_name) AS student_name,
            cr.overall_status, cr.school_year, cr.semester, cr.requested_at
     FROM clearance_requests cr
     INNER JOIN students s ON s.student_id = cr.student_id
     ORDER BY cr.requested_at DESC
     LIMIT 8"
);

require "_header.php";
?>

<div class="page-heading">
    <div>
        <h1>Dashboard</h1>
        <p>Welcome back, <?= e($_SESSION["full_name"]) ?></p>
    </div>
</div>

<section class="section-block">
    <div class="section-title-row">
        <div>
            <h2>Clearance Overview</h2>
            <p>Quick overview of the school's clearance activity.</p>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon"><i data-lucide="graduation-cap"></i></div>
            <div>
                <span class="stat-label">Students</span>
                <strong><?= $studentCount ?></strong>
                <small>Active students</small>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon"><i data-lucide="clock-3"></i></div>
            <div>
                <span class="stat-label">Pending</span>
                <strong><?= $pendingCount ?></strong>
                <small>Need attention</small>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon"><i data-lucide="badge-check"></i></div>
            <div>
                <span class="stat-label">Cleared</span>
                <strong><?= $clearedCount ?></strong>
                <small>Completed</small>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon"><i data-lucide="circle-alert"></i></div>
            <div>
                <span class="stat-label">Not Cleared</span>
                <strong><?= $notClearedCount ?></strong>
                <small>With issues</small>
            </div>
        </div>
    </div>
</section>

<section class="section-block">
    <div class="section-title-row">
        <div>
            <h2>System Summary</h2>
            <p>Current administrative records.</p>
        </div>
    </div>

    <div class="mini-stats">
        <div class="mini-card">
            <i data-lucide="building-2"></i>
            <div><strong><?= $officeCount ?></strong><span>Active Offices</span></div>
        </div>
        <div class="mini-card">
            <i data-lucide="user-round-cog"></i>
            <div><strong><?= $assignatoryCount ?></strong><span>Assignatories</span></div>
        </div>
    </div>
</section>

<section class="section-block">
    <div class="section-title-row">
        <div>
            <h2>Recent Clearance Requests</h2>
            <p>Latest clearance activity.</p>
        </div>
        <a class="text-link" href="clearance.php">View all <span>→</span></a>
    </div>

    <div class="table-card">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>School Year</th>
                        <th>Semester</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($recent && $recent->num_rows > 0): ?>
                    <?php while ($row = $recent->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <strong><?= e($row["student_name"]) ?></strong>
                                <small><?= e($row["student_number"]) ?></small>
                            </td>
                            <td><?= e($row["school_year"]) ?></td>
                            <td><?= e($row["semester"]) ?></td>
                            <td>
                                <span class="status status-<?= e($row["overall_status"]) ?>">
                                    <?= e(ucwords(str_replace("_", " ", $row["overall_status"]))) ?>
                                </span>
                            </td>
                            <td><?= e(date("M d, Y", strtotime($row["requested_at"]))) ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="5" class="empty-state">No clearance requests yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?php require "_footer.php"; ?>
