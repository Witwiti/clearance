<?php
$pageTitle = "Reports";
$activePage = "reports";
require_once "../config/auth.php";
require_role("admin");
require_once "../config/db.php";

$syFilter = trim($_GET["school_year"] ?? "");
$semFilter = trim($_GET["semester"] ?? "");
$statusFilter = trim($_GET["status"] ?? "");
$courseFilter = trim($_GET["course"] ?? "");
$dateFrom = trim($_GET["date_from"] ?? "");
$dateTo = trim($_GET["date_to"] ?? "");
$includeArchived = isset($_GET["include_archived"]) && $_GET["include_archived"] === "1";

$flash = $_SESSION["flash_message"] ?? "";
unset($_SESSION["flash_message"]);

// Clean duplicates: repeat submissions from the unrestricted window.
// Keeps the newest request per student + term, archives older PENDING rows
// (decided cleared/not_cleared history is never touched).
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "dedup") {
    $archived = 0;
    $dupes = $conn->query("SELECT student_id, school_year, semester FROM clearance_requests WHERE is_archived=0 GROUP BY student_id, school_year, semester HAVING COUNT(*) > 1");
    if ($dupes) {
        while ($g = $dupes->fetch_assoc()) {
            $sid = (int)$g["student_id"];
            $sy = (string)$g["school_year"];
            $sem = (string)$g["semester"];
            $list = $conn->prepare("SELECT clearance_id, overall_status FROM clearance_requests WHERE student_id=? AND school_year=? AND semester=? AND is_archived=0 ORDER BY requested_at DESC, clearance_id DESC");
            if (!$list) continue;
            $list->bind_param("iss", $sid, $sy, $sem);
            $list->execute();
            $res = $list->get_result();
            $first = true;
            while ($row = $res->fetch_assoc()) {
                $cid = (int)$row["clearance_id"];
                if ($first) { $first = false; continue; } // keep newest
                if (($row["overall_status"] ?? "") !== "pending") continue; // keep decided history
                $conn->query("INSERT IGNORE INTO clearance_archives (clearance_id, student_id, school_year, semester, overall_status, archived_by) VALUES (" . $cid . "," . $sid . ",'" . $conn->real_escape_string($sy) . "','" . $conn->real_escape_string($sem) . "','pending'," . (int)$_SESSION["user_id"] . ")");
                $up = $conn->prepare("UPDATE clearance_requests SET is_archived=1, archived_at=NOW() WHERE clearance_id=?");
                if ($up) { $up->bind_param("i", $cid); $up->execute(); $up->close(); }
                $archived++;
            }
            $list->close();
        }
        $dupes->close();
    }
    $_SESSION["flash_message"] = $archived > 0 ? "Archived " . $archived . " older duplicate request(s). The newest request per student and term was kept." : "No duplicate requests found.";
    header("Location: reports.php");
    exit;
}

// Extra rows beyond one per student + term (pending duplicates).
$dupeExtra = 0;
$dc = $conn->query("SELECT SUM(c-1) AS x FROM (SELECT COUNT(*) c FROM clearance_requests WHERE is_archived=0 GROUP BY student_id, school_year, semester HAVING c > 1) t");
if ($dc) { $dupeExtra = (int)($dc->fetch_assoc()["x"] ?? 0); $dc->close(); }

// Validate date inputs (YYYY-MM-DD). Invalid values are ignored instead of breaking the query.
$isValidDate = function (string $d): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
    [$y, $m, $day] = array_map('intval', explode('-', $d));
    return checkdate($m, $day, $y);
};
if ($dateFrom !== "" && !$isValidDate($dateFrom)) $dateFrom = "";
if ($dateTo !== "" && !$isValidDate($dateTo)) $dateTo = "";
if ($dateFrom !== "" && $dateTo !== "" && $dateFrom > $dateTo) {
    // Swap so From is never after To.
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$where = [];
$params = [];
$types = "";
if ($syFilter !== "") { $where[] = "cr.school_year=?"; $params[] = $syFilter; $types .= "s"; }
if ($semFilter !== "") { $where[] = "cr.semester=?"; $params[] = $semFilter; $types .= "s"; }
if ($statusFilter !== "" && in_array($statusFilter, ["pending","cleared","not_cleared"], true)) { $where[] = "cr.overall_status=?"; $params[] = $statusFilter; $types .= "s"; }
if ($courseFilter !== "") { $where[] = "s.course=?"; $params[] = $courseFilter; $types .= "s"; }
if ($dateFrom !== "") { $where[] = "DATE(cr.requested_at)>=?"; $params[] = $dateFrom; $types .= "s"; }
if ($dateTo !== "") { $where[] = "DATE(cr.requested_at)<=?"; $params[] = $dateTo; $types .= "s"; }
if (!$includeArchived) $where[] = "cr.is_archived=0";
$whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

// Summary counts (respect filters except status).
$summaryWhere = [];
$summaryParams = [];
$summaryTypes = "";
foreach (["school_year" => $syFilter, "semester" => $semFilter, "course" => $courseFilter] as $k => $v) { /* rebuilt below */ }
$sw = [];
$sp = [];
$st = "";
if ($syFilter !== "") { $sw[] = "cr.school_year=?"; $sp[] = $syFilter; $st .= "s"; }
if ($semFilter !== "") { $sw[] = "cr.semester=?"; $sp[] = $semFilter; $st .= "s"; }
if ($courseFilter !== "") { $sw[] = "s.course=?"; $sp[] = $courseFilter; $st .= "s"; }
if ($dateFrom !== "") { $sw[] = "DATE(cr.requested_at)>=?"; $sp[] = $dateFrom; $st .= "s"; }
if ($dateTo !== "") { $sw[] = "DATE(cr.requested_at)<=?"; $sp[] = $dateTo; $st .= "s"; }
if (!$includeArchived) $sw[] = "cr.is_archived=0";
$swSql = $sw ? "WHERE " . implode(" AND ", $sw) : "";

$summary = ["total" => 0, "pending" => 0, "cleared" => 0, "not_cleared" => 0];
$stmt = $conn->prepare("SELECT COUNT(*) t, SUM(cr.overall_status='pending') p, SUM(cr.overall_status='cleared') c, SUM(cr.overall_status='not_cleared') n FROM clearance_requests cr INNER JOIN students s ON s.student_id=cr.student_id $swSql");
if ($stmt) {
    if ($sp) $stmt->bind_param($st, ...$sp);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $summary = ["total" => (int)($r["t"] ?? 0), "pending" => (int)($r["p"] ?? 0), "cleared" => (int)($r["c"] ?? 0), "not_cleared" => (int)($r["n"] ?? 0)];
    $stmt->close();
}

// Detail rows.
$sql = "SELECT cr.clearance_id, s.student_number, CONCAT(s.first_name,' ',s.last_name) AS student_name, s.course, s.year_level,
               cr.school_year, cr.semester, cr.overall_status, cr.requested_at,
               (SELECT COUNT(*) FROM clearance_transactions t WHERE t.clearance_id=cr.clearance_id) AS offices_total,
               (SELECT COUNT(*) FROM clearance_transactions t WHERE t.clearance_id=cr.clearance_id AND t.status='approved') AS offices_approved
        FROM clearance_requests cr INNER JOIN students s ON s.student_id=cr.student_id $whereSql ORDER BY cr.requested_at DESC LIMIT 500";
$stmt = $conn->prepare($sql);
if ($stmt) {
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else { $rows = []; }

// CSV / Excel export.
if (isset($_GET["export"]) && $_GET["export"] === "csv") {
    $fname = "clearance_report_" . date("Ymd_His") . ".csv";
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=" . $fname);
    $out = fopen("php://output", "w");
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM for Excel
    fputcsv($out, ["Student Number", "Student Name", "Course", "Year Level", "School Year", "Semester", "Overall Status", "Offices Approved", "Offices Total", "Requested"]);
    foreach ($rows as $r) {
        fputcsv($out, [$r["student_number"], $r["student_name"], $r["course"], $r["year_level"], $r["school_year"], $r["semester"], $r["overall_status"], $r["offices_approved"], $r["offices_total"], $r["requested_at"]]);
    }
    fclose($out);
    exit;
}

$syOpts = $conn->query("SELECT DISTINCT school_year FROM clearance_requests ORDER BY school_year DESC");
$semOpts = $conn->query("SELECT DISTINCT semester FROM clearance_requests ORDER BY semester");
$courseOpts = $conn->query("SELECT DISTINCT course FROM students WHERE course IS NOT NULL AND course<>'' ORDER BY course");

function qs(array $over = []): string {
    $q = array_merge($_GET, $over);
    return http_build_query($q);
}

function qs_without(array $keys): string {
    $q = $_GET;
    foreach ($keys as $k) unset($q[$k]);
    return http_build_query($q);
}

require "_header.php";
?>
<div class="page-heading">
    <div><h1>Reports</h1><p>Generate clearance summaries — counts plus detailed rows. Export to Excel (CSV) or print to PDF.</p></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a class="btn btn-secondary" href="reports.php?<?= e(qs(["export" => "csv"])) ?>"><i data-lucide="file-spreadsheet"></i> Export Excel (CSV)</a>
        <button class="btn btn-primary" data-print-confirm data-delete-confirm="Print the report now?" data-confirm-title="Print report?" data-confirm-action="Print" data-confirm-kind="warning" data-confirm-icon="printer"><i data-lucide="printer"></i> Print / PDF</button>
        <?php if ($dupeExtra > 0): ?>
        <form method="post" style="display:inline;" data-delete-confirm="Archive <?= (int)$dupeExtra ?> older duplicate request(s)? The newest request per student and term is kept." data-confirm-title="Clean duplicates?" data-confirm-action="Clean">
            <input type="hidden" name="action" value="dedup">
            <button class="btn btn-secondary" type="submit"><i data-lucide="layers"></i> Clean duplicates (<?= (int)$dupeExtra ?>)</button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php if ($flash !== ""): ?><div class="notice notice-success"><?= e($flash) ?></div><?php endif; ?>

<div class="stats-grid" style="margin-bottom:16px;">
    <div class="stat-card"><div class="stat-icon"><i data-lucide="files"></i></div><div><span class="stat-label">Total requests</span><strong><?= (int)$summary["total"] ?></strong><small>Matching filters</small></div></div>
    <div class="stat-card"><div class="stat-icon"><i data-lucide="clock-3"></i></div><div><span class="stat-label">Pending</span><strong><?= (int)$summary["pending"] ?></strong><small>Need attention</small></div></div>
    <div class="stat-card"><div class="stat-icon"><i data-lucide="badge-check"></i></div><div><span class="stat-label">Cleared</span><strong><?= (int)$summary["cleared"] ?></strong><small>Completed</small></div></div>
    <div class="stat-card"><div class="stat-icon"><i data-lucide="circle-alert"></i></div><div><span class="stat-label">Not cleared</span><strong><?= (int)$summary["not_cleared"] ?></strong><small>With issues</small></div></div>
</div>

<?php
$activeFilters = [];
if ($syFilter !== "") $activeFilters["school_year"] = "S.Y. " . $syFilter;
if ($semFilter !== "") $activeFilters["semester"] = $semFilter;
if ($statusFilter !== "") $activeFilters["status"] = ucwords(str_replace("_", " ", $statusFilter));
if ($courseFilter !== "") $activeFilters["course"] = $courseFilter;
if ($dateFrom !== "" || $dateTo !== "") $activeFilters["date"] = ($dateFrom !== "" ? date("M d, Y", strtotime($dateFrom)) : "Start") . " → " . ($dateTo !== "" ? date("M d, Y", strtotime($dateTo)) : "Today");
if ($includeArchived) $activeFilters["archived"] = "Incl. archived";
?>
<?php if ($activeFilters): ?>
<div class="active-filters">
    <span class="active-filters-label"><i data-lucide="filter"></i> <?= count($rows ?? []) ?> result(s):</span>
    <?php foreach ($activeFilters as $key => $label): ?>
        <a class="filter-pill" href="reports.php?<?= e(qs_without(array_merge([$key === "date" ? "date_from" : $key, $key === "date" ? "date_to" : $key], $key === "archived" ? ["include_archived"] : []))) ?>" title="Remove filter"><span><?= e($label) ?></span><i data-lucide="x"></i></a>
    <?php endforeach; ?>
    <a class="filter-pill filter-pill-clear" href="reports.php">Clear all</a>
</div>
<?php endif; ?>

<form method="get" class="toolbar-card report-filters" data-server-filter id="reportFilters">
    <div class="report-filter-group">
        <span class="report-filter-heading"><i data-lucide="sliders-horizontal"></i> Filters</span>
        <label class="report-control">
            <span>School year</span>
            <span class="report-select-wrap">
                <select name="school_year" onchange="this.form.submit()"><option value="">All school years</option><?php if ($syOpts): while ($o = $syOpts->fetch_assoc()): $v = $o["school_year"]; ?><option value="<?= e($v) ?>" <?= $syFilter === $v ? "selected" : "" ?>><?= e($v) ?></option><?php endwhile; endif; ?></select>
                <i data-lucide="chevron-down"></i>
            </span>
        </label>
        <label class="report-control">
            <span>Semester</span>
            <span class="report-select-wrap">
                <select name="semester" onchange="this.form.submit()"><option value="">All semesters</option><?php if ($semOpts): while ($o = $semOpts->fetch_assoc()): $v = $o["semester"]; ?><option value="<?= e($v) ?>" <?= $semFilter === $v ? "selected" : "" ?>><?= e($v) ?></option><?php endwhile; endif; ?></select>
                <i data-lucide="chevron-down"></i>
            </span>
        </label>
        <label class="report-control">
            <span>Status</span>
            <span class="report-select-wrap">
                <select name="status" onchange="this.form.submit()"><option value="">All statuses</option><option value="pending" <?= $statusFilter === "pending" ? "selected" : "" ?>>Pending</option><option value="cleared" <?= $statusFilter === "cleared" ? "selected" : "" ?>>Cleared</option><option value="not_cleared" <?= $statusFilter === "not_cleared" ? "selected" : "" ?>>Not cleared</option></select>
                <i data-lucide="chevron-down"></i>
            </span>
        </label>
        <label class="report-control">
            <span>Course</span>
            <span class="report-select-wrap">
                <select name="course" onchange="this.form.submit()"><option value="">All courses</option><?php if ($courseOpts): while ($o = $courseOpts->fetch_assoc()): $v = $o["course"]; ?><option value="<?= e($v) ?>" <?= $courseFilter === $v ? "selected" : "" ?>><?= e($v) ?></option><?php endwhile; endif; ?></select>
                <i data-lucide="chevron-down"></i>
            </span>
        </label>
    </div>

    <div class="report-filter-group report-date-group">
        <span class="report-filter-heading"><i data-lucide="calendar-days"></i> Date requested</span>
        <label class="report-control report-date">
            <span>From</span>
            <span class="report-date-wrap">
                <i data-lucide="calendar"></i>
                <input type="date" name="date_from" id="dateFrom" value="<?= e($dateFrom) ?>" max="<?= e(date('Y-m-d')) ?>">
            </span>
        </label>
        <span class="report-date-sep">→</span>
        <label class="report-control report-date">
            <span>To</span>
            <span class="report-date-wrap">
                <i data-lucide="calendar"></i>
                <input type="date" name="date_to" id="dateTo" value="<?= e($dateTo) ?>" max="<?= e(date('Y-m-d')) ?>">
            </span>
        </label>
        <div class="report-presets">
            <button type="button" class="chip" data-preset="today">Today</button>
            <button type="button" class="chip" data-preset="week">Last 7 days</button>
            <button type="button" class="chip" data-preset="month">This month</button>
            <button type="button" class="chip" data-preset="clear">Clear</button>
        </div>
        <label class="report-check"><input type="checkbox" name="include_archived" value="1" <?= $includeArchived ? "checked" : "" ?> onchange="this.form.submit()"> Include archived</label>
    </div>

    <div class="report-actions">
        <button class="btn btn-primary" type="submit"><i data-lucide="search"></i> Apply filters</button>
        <a class="btn btn-secondary" href="reports.php">Reset</a>
    </div>
</form>

<div class="table-card"><div class="table-wrapper"><table>
<thead><tr><th>Student</th><th>Term</th><th>Status</th><th>Progress</th><th>Requested</th></tr></thead>
<tbody>
<?php if ($rows): foreach ($rows as $r): ?>
<tr>
    <td><strong><?= e($r["student_name"]) ?></strong><small><?= e($r["student_number"]) ?> · <?= e($r["course"] ?: "No course") ?></small></td>
    <td><?= e($r["school_year"]) ?><small><?= e($r["semester"]) ?></small></td>
    <td><span class="status status-<?= e($r["overall_status"]) ?>"><?= e(ucwords(str_replace("_", " ", $r["overall_status"]))) ?></span></td>
    <td><?= (int)$r["offices_approved"] ?>/<?= (int)$r["offices_total"] ?> offices</td>
    <td><?= e(date("M d, Y", strtotime($r["requested_at"]))) ?></td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="5" class="empty-state">No records match these filters.</td></tr>
<?php endif; ?>
</tbody></table></div></div>
<style>
.report-filters { display:grid; gap:14px; }
.report-filter-group { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; }
.report-filter-heading { width:100%; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--muted, #71717a); display:flex; align-items:center; gap:6px; }
.report-filter-heading svg { width:14px; height:14px; }
.report-control { display:grid; gap:4px; font-size:12px; font-weight:600; min-width:150px; flex:1; }
.report-control > span:first-child { color:var(--muted, #52525b); font-weight:600; }
.report-select-wrap, .report-date-wrap { position:relative; display:flex; align-items:center; }
.report-select-wrap select { width:100%; height:38px; padding:0 30px 0 12px; border:1px solid var(--border); border-radius:9px; background:var(--surface); color:var(--text); font:inherit; appearance:none; }
.report-select-wrap svg { position:absolute; right:10px; width:15px; height:15px; pointer-events:none; opacity:.6; }
.report-date-wrap input[type="date"] { width:100%; height:38px; padding:0 10px 0 32px; border:1px solid var(--border); border-radius:9px; background:var(--surface); color:var(--text); font:inherit; color-scheme:light; }
body.dark .report-date-wrap input[type="date"] { color-scheme:dark; }
.report-date-wrap > svg { position:absolute; left:10px; width:15px; height:15px; opacity:.55; pointer-events:none; }
.report-date { min-width:170px; }
.report-date-sep { align-self:flex-end; padding-bottom:10px; color:var(--muted, #71717a); font-weight:700; }
.report-date-group { border-top:1px dashed var(--border); padding-top:12px; }
.report-presets { display:flex; gap:6px; flex-wrap:wrap; align-items:flex-end; padding-bottom:2px; }
.chip { border:1px solid var(--border); background:var(--surface); color:var(--text); border-radius:20px; padding:6px 12px; font-size:12px; font-weight:600; cursor:pointer; }
.chip:hover { border-color:var(--orange); color:var(--orange); }
.report-check { display:flex; align-items:center; gap:7px; font-size:12px; font-weight:600; padding-bottom:10px; white-space:nowrap; }
.report-actions { display:flex; gap:8px; flex-wrap:wrap; }
.active-filters { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:12px; }
.active-filters-label { font-size:12px; font-weight:700; display:flex; align-items:center; gap:6px; }
.active-filters-label svg { width:14px; height:14px; }
.filter-pill { display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:600; border:1px solid var(--border); border-radius:20px; padding:5px 6px 5px 12px; text-decoration:none; color:var(--text); background:var(--surface); }
.filter-pill svg { width:13px; height:13px; }
.filter-pill:hover { border-color:var(--red, #ef4444); color:var(--red, #ef4444); }
.filter-pill-clear { padding:5px 12px; }
@media print { .sidebar, .topbar, .page-heading .btn, .toolbar-card, .stats-grid, .active-filters, .report-filters { display:none !important; } .content { padding:0; } }
</style>
<script>
(function () {
    const from = document.getElementById('dateFrom');
    const to = document.getElementById('dateTo');
    if (!from || !to) return;
    const fmt = (d) => d.toISOString().slice(0, 10);
    document.querySelectorAll('#reportFilters [data-preset]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const now = new Date();
            const p = btn.dataset.preset;
            if (p === 'today') { from.value = fmt(now); to.value = fmt(now); }
            else if (p === 'week') { const s = new Date(now); s.setDate(s.getDate() - 6); from.value = fmt(s); to.value = fmt(now); }
            else if (p === 'month') { const s = new Date(now.getFullYear(), now.getMonth(), 1); from.value = fmt(s); to.value = fmt(now); }
            else if (p === 'clear') { from.value = ''; to.value = ''; }
        });
    });
    // Guard: From can never be after To.
    document.getElementById('reportFilters').addEventListener('submit', (e) => {
        if (from.value && to.value && from.value > to.value) {
            e.preventDefault();
            const tmp = from.value; from.value = to.value; to.value = tmp;
            e.target.submit();
        }
    });
})();
</script>
<?php require "_footer.php"; ?>
