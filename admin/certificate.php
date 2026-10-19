<?php
$pageTitle = "Clearance Certificate";
$activePage = "clearance";
require_once "../config/auth.php";
require_role("admin");
require_once "../config/db.php";

$requestId = (int)($_GET["request"] ?? 0);
if ($requestId <= 0) { header("Location: clearance.php"); exit; }

$stmt = $conn->prepare("SELECT cr.clearance_id, cr.school_year, cr.semester, cr.overall_status, cr.requested_at, s.student_number, s.first_name, s.middle_name, s.last_name, s.course, s.year_level FROM clearance_requests cr INNER JOIN students s ON s.student_id=cr.student_id WHERE cr.clearance_id=? LIMIT 1");
$stmt->bind_param("i", $requestId);
$stmt->execute();
$req = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$req) die("Clearance request not found.");
if (($req["overall_status"] ?? "") !== "cleared") die("Certificate is available only for fully cleared requests.");

$cfg = ["school_name" => "CHRIST THE KING COLLEGE DE MARANDING, INC.", "school_address" => "Maranding, Lala, Lanao del Norte, Philippines 9211", "school_contact" => "Tel. (063) 338-7039", "department" => "COLLEGE DEPARTMENT"];
$res = $conn->query("SELECT setting_key, setting_value FROM system_settings");
if ($res) { while ($s = $res->fetch_assoc()) { $k = (string)$s["setting_key"]; if (strpos($k, "clearance_") === 0) { $fk = substr($k, 10); if (array_key_exists($fk, $cfg) && trim((string)$s["setting_value"]) !== "") $cfg[$fk] = $s["setting_value"]; } } $res->close(); }

$offices = [];
$requestCourse = trim((string)($req["course"] ?? ""));
$stmt = $conn->prepare("SELECT CASE WHEN ct.club_id IS NOT NULL THEN CONCAT('Institutional Club - ', c.club_name) ELSE o.office_name END AS office_name, o.office_id, ct.status, ct.reviewed_at, ct.club_id, mu.full_name AS club_moderator FROM clearance_transactions ct INNER JOIN offices o ON o.office_id=ct.office_id LEFT JOIN institutional_clubs c ON c.club_id = ct.club_id LEFT JOIN users mu ON mu.user_id = c.moderator_user_id WHERE ct.clearance_id=? ORDER BY office_name");
$stmt->bind_param("i", $requestId);
$stmt->execute();
$r = $stmt->get_result();
$transactionRows = [];
while ($row = $r->fetch_assoc()) $transactionRows[] = $row;
$stmt->close();

$bestByOffice = [];
$dsaSscOfficeId = 0;
$dsaUserIds = [];
// Head/Dean seat ids: a dean-flagged linked user only heads their own course.
$headDeanOfficeIds = [];
$dsaRes = $conn->query("SELECT office_id, office_name FROM offices WHERE status = 'active'");
if ($dsaRes) {
    while ($do = $dsaRes->fetch_assoc()) {
        $dn = (string)($do["office_name"] ?? "");
        if (stripos($dn, "Head/Dean") !== false || stripos($dn, "Head / Dean") !== false) $headDeanOfficeIds[(int)$do["office_id"]] = true;
        if (stripos($dn, "SSC President") !== false) $dsaSscOfficeId = (int)$do["office_id"];
        if (stripos($dn, "Student Affairs") !== false || preg_match('/\(DSA\)/i', $dn)) {
            $du = $conn->query("SELECT DISTINCT user_id FROM office_assignatories WHERE office_id = " . (int)$do["office_id"] . " AND status = 'active' AND user_id IS NOT NULL");
            if ($du) { while ($dur = $du->fetch_assoc()) $dsaUserIds[(int)$dur["user_id"]] = true; $du->close(); }
        }
    }
    $dsaRes->close();
}
$assignatoryResult = $conn->query(
    "SELECT oa.assignatory_id, oa.office_id, oa.assignatory_name, oa.position, oa.assigned_by_user_id,
            u.full_name AS user_full_name, u.college_dean AS linked_is_dean, u.college_dean_course AS linked_dean_course, owner.college_dean_course AS owner_course
     FROM office_assignatories oa
     LEFT JOIN users u ON u.user_id = oa.user_id
     LEFT JOIN users owner ON owner.user_id = oa.assigned_by_user_id
     WHERE oa.status = 'active'
     ORDER BY oa.assignatory_id ASC"
);
if ($assignatoryResult) {
    while ($candidate = $assignatoryResult->fetch_assoc()) {
        $candidateOfficeId = (int)($candidate["office_id"] ?? 0);
        if ($candidateOfficeId <= 0) continue;
        $ownerCourse = trim((string)($candidate["owner_course"] ?? ""));
        $isDeptMatch = $requestCourse !== "" && $ownerCourse !== "" && strcasecmp($ownerCourse, $requestCourse) === 0;
        $isGlobal = $candidate["assigned_by_user_id"] === null || $ownerCourse === "";
        if (!$isDeptMatch && !$isGlobal) continue;
        // Head/Dean seat: a dean-flagged linked user only heads their own course.
        $linkedDeanCourse = ((int)($candidate["linked_is_dean"] ?? 0) === 1) ? trim((string)($candidate["linked_dean_course"] ?? "")) : "";
        if ($linkedDeanCourse !== "" && $requestCourse !== "" && isset($headDeanOfficeIds[$candidateOfficeId]) && strcasecmp($linkedDeanCourse, $requestCourse) !== 0) continue;
        $priority = $isDeptMatch ? 0 : 1;
        if ($dsaSscOfficeId > 0 && $candidateOfficeId === $dsaSscOfficeId && isset($dsaUserIds[(int)($candidate["assigned_by_user_id"] ?? 0)])) $priority = 0;
        if (!isset($bestByOffice[$candidateOfficeId]) || $priority < $bestByOffice[$candidateOfficeId]["_priority"]) {
            $candidate["_priority"] = $priority;
            $bestByOffice[$candidateOfficeId] = $candidate;
        }
    }
    $assignatoryResult->close();
}
foreach ($transactionRows as $row) {
    $officeId = (int)($row["office_id"] ?? 0);
    $best = $bestByOffice[$officeId] ?? null;
    $signatory = null;
    $position = null;
    if (!empty($row["club_id"])) {
        $signatory = $row["club_moderator"] ?: null;
        $position = "Club Moderator";
    } elseif ($best) {
        $signatory = trim((string)($best["assignatory_name"] ?? "")) !== "" ? $best["assignatory_name"] : ($best["user_full_name"] ?? null);
        if (trim((string)$signatory) === "") $signatory = null;
        $position = trim((string)($best["position"] ?? "")) !== "" ? $best["position"] : null;
    }
    $offices[] = [
        "office_name" => $row["office_name"],
        "status" => $row["status"],
        "reviewed_at" => $row["reviewed_at"],
        "signatory" => $signatory,
        "position" => $position,
    ];
}

$certNo = "CKCM-CLR-" . date("Y") . "-" . str_pad((string)$requestId, 5, "0", STR_PAD_LEFT);
$fullName = trim(($req["first_name"] ?? "") . " " . (!empty($req["middle_name"]) ? $req["middle_name"] . " " : "") . ($req["last_name"] ?? ""));
require "_header.php";
?>
<div class="page-heading no-print">
    <div><h1>Clearance Certificate</h1><p><?= e($fullName) ?> · <?= e($req["student_number"]) ?> · <?= e($req["semester"]) ?> <?= e($req["school_year"]) ?></p></div>
    <div style="display:flex;gap:8px;">
        <a class="btn btn-secondary" href="clearance.php?review=<?= (int)$requestId ?>">Back</a>
        <button class="btn btn-primary" data-print-confirm data-delete-confirm="Print the certificate now?" data-confirm-title="Print certificate?" data-confirm-action="Print" data-confirm-kind="warning" data-confirm-icon="printer"><i data-lucide="printer"></i> Download / Print (PDF)</button>
    </div>
</div>
<div class="certificate-paper"><div class="certificate-border">
    <div class="certificate-header">
        <img src="../assets/images/ckcm%20transparent.png" alt="School logo" onerror="this.style.display='none'">
        <h3><?= e($cfg["school_name"]) ?></h3>
        <p><?= e($cfg["school_address"]) ?> · <?= e($cfg["school_contact"]) ?></p>
        <p class="certificate-dept"><?= e($cfg["department"]) ?></p>
    </div>
    <h1 class="certificate-title">CERTIFICATE OF CLEARANCE</h1>
    <p class="certificate-no">Certificate No. <?= e($certNo) ?></p>
    <p class="certificate-body">This is to certify that <strong><?= e($fullName) ?></strong>, <strong><?= e($req["student_number"]) ?></strong>, <strong><?= e($req["course"] ?? "") ?></strong> (<?= e($req["year_level"] ?? "") ?>), has been <strong>CLEARED</strong> of all obligations for <strong><?= e($req["semester"]) ?>, <?= e($req["school_year"]) ?></strong>.</p>
    <table class="certificate-table"><thead><tr><th>Office</th><th>Signed By</th><th>Status</th></tr></thead>
    <tbody><?php foreach ($offices as $o): ?><tr><td><?= e($o["office_name"]) ?></td><td><?= e($o["signatory"] ? $o["signatory"] . ($o["position"] ? " — " . $o["position"] : "") : "—") ?></td><td><?= e(ucfirst($o["status"])) ?></td></tr><?php endforeach; ?></tbody></table>
    <p class="certificate-meta">Issued on <?= e(date("F d, Y")) ?> · Request #<?= (int)$requestId ?></p>
    <div class="certificate-signatures"><div><span>_________________________</span><small>Registrar</small></div><div><span>_________________________</span><small>Dean / Authorized Signatory</small></div><div><span>_________________________</span><small>Date</small></div></div>
</div></div>
<style>
.certificate-paper { background:#fff; border-radius:12px; padding:24px; }
.certificate-border { border:3px double #b45309; border-radius:8px; padding:32px 28px; text-align:center; color:#111; }
.certificate-header img { height:64px; }
.certificate-header h3 { margin:8px 0 2px; font-size:18px; }
.certificate-header p { margin:0; font-size:12px; color:#444; }
.certificate-dept { font-weight:700; margin-top:6px !important; }
.certificate-title { margin:18px 0 4px; font-size:26px; letter-spacing:2px; color:#92400e; }
.certificate-no { font-size:12px; color:#666; }
.certificate-body { font-size:14px; line-height:1.7; max-width:640px; margin:0 auto 16px; }
.certificate-table { width:100%; max-width:640px; margin:0 auto 16px; border-collapse:collapse; font-size:12px; text-align:left; }
.certificate-table th, .certificate-table td { border:1px solid #ddd; padding:6px 8px; }
.certificate-table th { background:#fef3c7; }
.certificate-meta { font-size:11px; color:#666; }
.certificate-signatures { display:flex; gap:24px; justify-content:center; margin-top:28px; font-size:12px; }
.certificate-signatures small { display:block; color:#666; }
@media print { .sidebar, .topbar, .page-heading { display:none !important; } .content { padding:0; } }
</style>
<?php require "_footer.php"; ?>
