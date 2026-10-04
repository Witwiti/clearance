<?php
$pageTitle = "Student Overview";
$activePage = "dashboard";
require_once "../config/db.php";
require_once "../config/auth.php";
require_role("student");

$message = "";
$error = "";
$studentStmt = $conn->prepare("SELECT * FROM students WHERE user_id = ? LIMIT 1");
$studentStmt->bind_param("i", $_SESSION["user_id"]);
$studentStmt->execute();
$student = $studentStmt->get_result()->fetch_assoc();
$studentStmt->close();
$isFirstYearStudent = strcasecmp(trim((string)($student["year_level"] ?? "")), "1st Year") === 0;

if (!$student) $error = "Your student profile has not been set up yet. Please contact the administrator.";

$configuredTerm = "2nd Semester S.Y. 2025 - 2026";
$configurationTable = $conn->query("SHOW TABLES LIKE 'system_settings'");
if ($configurationTable && $configurationTable->num_rows) {
	$termStmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'clearance_term' LIMIT 1");
	$termStmt->execute();
	$term = $termStmt->get_result()->fetch_assoc();
	$termStmt->close();
	if (!empty($term["setting_value"])) $configuredTerm = $term["setting_value"];
}
$configuredSemester = "";
$configuredSchoolYear = "";
if (preg_match('/^(1st Semester|2nd Semester|Summer)/i', $configuredTerm, $termMatch)) $configuredSemester = $termMatch[1];
if (preg_match('/(\d{4}\s*-\s*\d{4})/', $configuredTerm, $yearMatch)) $configuredSchoolYear = preg_replace('/\s+/', '', $yearMatch[1]);

if ($_SERVER["REQUEST_METHOD"] === "POST" && $student) {
	$schoolYear = $configuredSchoolYear;
	$semester = $configuredSemester;
	$allowRequests = true;
	$table = $conn->query("SHOW TABLES LIKE 'system_settings'");
	if ($table && $table->num_rows) {
		$settingStmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'allow_clearance_requests' LIMIT 1");
		$settingStmt->execute();
		$setting = $settingStmt->get_result()->fetch_assoc();
		$settingStmt->close();
		$allowRequests = ($setting["setting_value"] ?? "1") === "1";
	}

	if (!$allowRequests) $error = "New clearance requests are currently disabled.";
	elseif ($schoolYear === "" || $semester === "") $error = "The configured clearance term is incomplete. Please contact the administrator.";
	else {
		$duplicateStmt = $conn->prepare("SELECT clearance_id FROM clearance_requests WHERE student_id = ? AND school_year = ? AND semester = ? LIMIT 1");
		$duplicateStmt->bind_param("iss", $student["student_id"], $schoolYear, $semester);
		$duplicateStmt->execute();
		$duplicate = $duplicateStmt->get_result()->fetch_assoc();
		$duplicateStmt->close();

		if ($duplicate) $error = "You already have a clearance request for this school year and semester.";
		else {
			$conn->begin_transaction();
			$requestStmt = $conn->prepare("INSERT INTO clearance_requests (student_id, school_year, semester) VALUES (?, ?, ?)");
			$requestStmt->bind_param("iss", $student["student_id"], $schoolYear, $semester);
			$requestCreated = $requestStmt->execute();
			$clearanceId = $requestStmt->insert_id;
			$requestStmt->close();
			$transactionCreated = true;
			$requestOfficeIds = [];
			$offices = $conn->query("SELECT office_id, office_name FROM offices WHERE status = 'active'");
			if ($offices) {
				while ($office = $offices->fetch_assoc()) {
					$officeKey = preg_replace('/[^a-z]/i', '', (string)$office["office_name"]);
					if (!$isFirstYearStudent && strcasecmp($officeKey, "nstpnsrc") === 0) continue;
					$requestOfficeIds[(int)$office["office_id"]] = true;
				}
			}
			if ($requestCreated && $offices) {
				$transactionStmt = $conn->prepare("INSERT INTO clearance_transactions (clearance_id, office_id) VALUES (?, ?)");
				foreach (array_keys($requestOfficeIds) as $requestOfficeId) { $transactionStmt->bind_param("ii", $clearanceId, $requestOfficeId); if (!$transactionStmt->execute()) $transactionCreated = false; }
				$transactionStmt->close();
			}
			if ($requestCreated && $transactionCreated) { $conn->commit(); $message = "Clearance request submitted successfully."; }
			else { $conn->rollback(); $error = "Unable to submit your clearance request."; }
		}
	}
}

$requests = [];
if ($student) {
	$requestStmt = $conn->prepare("SELECT clearance_id, school_year, semester, overall_status, requested_at FROM clearance_requests WHERE student_id = ? ORDER BY requested_at DESC");
	$requestStmt->bind_param("i", $student["student_id"]);
	$requestStmt->execute();
	$result = $requestStmt->get_result();
	while ($row = $result->fetch_assoc()) $requests[] = $row;
	$requestStmt->close();
}
$activeRequest = $requests[0] ?? null;
$transactions = [];
if ($activeRequest) {
	$transactionStmt = $conn->prepare("SELECT o.office_name, o.description, ct.status, ct.remarks, ct.reviewed_at FROM clearance_transactions ct INNER JOIN offices o ON o.office_id = ct.office_id WHERE ct.clearance_id = ? ORDER BY o.office_name");
	$transactionStmt->bind_param("i", $activeRequest["clearance_id"]);
	$transactionStmt->execute();
	$result = $transactionStmt->get_result();
	while ($row = $result->fetch_assoc()) {
		$officeKey = preg_replace('/[^a-z]/i', '', (string)$row["office_name"]);
		if (!$isFirstYearStudent && strcasecmp($officeKey, "nstpnsrc") === 0) continue;
		$transactions[] = $row;
	}
	$transactionStmt->close();
}
$announcements = $conn->query("SELECT title, content, created_at FROM announcements WHERE status = 'published' ORDER BY created_at DESC LIMIT 3");
$approvedCount = count(array_filter($transactions, fn($row) => $row["status"] === "approved"));
$progress = count($transactions) ? (int)round(($approvedCount / count($transactions)) * 100) : 0;

require "_header.php";
?>
<div class="page-heading"><div><h1>Welcome, <?= e($student["first_name"] ?? $_SESSION["full_name"]) ?></h1><p>Track your clearance journey and stay on top of every requirement.</p></div></div>
<?php if ($message !== ""): ?><div class="notice notice-success"><?= e($message) ?></div><?php endif; ?><?php if ($error !== ""): ?><div class="notice notice-error"><?= e($error) ?></div><?php endif; ?>
<section class="section-block"><div class="student-identity"><div class="student-identity-mark"><i data-lucide="graduation-cap"></i></div><div><strong><?= e(($student["first_name"] ?? "Student") . " " . ($student["last_name"] ?? "")) ?></strong><span><?= e($student["student_number"] ?? "No student number") ?> · <?= e($student["course"] ?? "Course not set") ?></span></div><span class="status status-<?= e($student["status"] ?? "inactive") ?>"><?= e(ucfirst($student["status"] ?? "inactive")) ?></span></div></section>
<section class="stats-grid"><div class="stat-card"><div class="stat-icon"><i data-lucide="file-check-2"></i></div><div><span class="stat-label">Current status</span><strong><?= e($activeRequest ? ucwords(str_replace("_", " ", $activeRequest["overall_status"])) : "No request") ?></strong><small>Latest clearance request</small></div></div><div class="stat-card"><div class="stat-icon"><i data-lucide="circle-check"></i></div><div><span class="stat-label">Office progress</span><strong><?= $progress ?>%</strong><small><?= $approvedCount ?> of <?= count($transactions) ?> offices approved</small></div></div><div class="stat-card"><div class="stat-icon"><i data-lucide="history"></i></div><div><span class="stat-label">Requests</span><strong><?= count($requests) ?></strong><small>Total submitted requests</small></div></div></section>
<div class="form-card announcement-form floating-form" id="request-form"><div class="floating-form-header"><h2>New clearance request</h2><button class="floating-form-close" type="button" aria-label="Close form"><i data-lucide="x"></i></button></div><form method="post"><div class="configured-term-display"><span>Clearance term</span><strong><?= e($configuredTerm) ?></strong><small>This term is configured by the administrator.</small></div><button class="btn btn-primary" type="submit"><i data-lucide="send"></i>Submit request</button></form></div>
<section class="section-block" id="clearance-progress"><div class="section-title-row"><div><h2>Clearance progress</h2><p><?= $activeRequest ? e($activeRequest["school_year"] . " · " . $activeRequest["semester"]) : "Submit a request to begin your clearance." ?></p></div></div><div class="table-card"><div class="table-wrapper"><table><thead><tr><th>Office</th><th>Requirement status</th><th>Remarks</th><th>Updated</th></tr></thead><tbody><?php if ($transactions): foreach ($transactions as $transaction): $transactionStatus = $transaction["status"] === "approved" ? "approved" : ($transaction["status"] === "rejected" ? "rejected" : "pending"); ?><tr><td><strong><?= e($transaction["office_name"]) ?></strong><small><?= e($transaction["description"] ?: "Clearance review") ?></small></td><td><span class="status status-<?= e($transactionStatus) ?>"><?= e(ucfirst($transaction["status"])) ?></span></td><td><?= e($transaction["remarks"] ?: "No remarks yet") ?></td><td><?= e($transaction["reviewed_at"] ? date("M d, Y", strtotime($transaction["reviewed_at"])) : "Waiting for review") ?></td></tr><?php endforeach; else: ?><tr><td colspan="4" class="empty-state">No office requirements yet.</td></tr><?php endif; ?></tbody></table></div></div></section>
<section class="section-block" id="announcements"><div class="section-title-row"><div><h2>Latest announcements</h2><p>Updates from the clearance office.</p></div></div><div class="student-announcements"><?php if ($announcements && $announcements->num_rows): while ($announcement = $announcements->fetch_assoc()): ?><article class="announcement-item"><span><?= e(date("M d, Y", strtotime($announcement["created_at"]))) ?></span><h3><?= e($announcement["title"]) ?></h3><p><?= e($announcement["content"]) ?></p></article><?php endwhile; else: ?><div class="empty-panel"><i data-lucide="megaphone"></i><p>No announcements published yet.</p></div><?php endif; ?></div></section>
<?php require "_footer.php"; ?>
