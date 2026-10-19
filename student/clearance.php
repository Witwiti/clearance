<?php
$pageTitle = "My Clearance";
$activePage = "clearance";
require_once "../config/db.php";
require_once "../config/auth.php";
require_once "../config/clearance_form.php";
require_role("student");

$studentStmt = $conn->prepare("SELECT * FROM students WHERE user_id = ? LIMIT 1");
$studentStmt->bind_param("i", $_SESSION["user_id"]);
$studentStmt->execute();
$student = $studentStmt->get_result()->fetch_assoc();
$studentStmt->close();
$isFirstYearStudent = strcasecmp(trim((string)($student["year_level"] ?? "")), "1st Year") === 0;

$formConfiguration = clearance_form_settings($conn);
$configuredSemester = "";
$configuredSchoolYear = "";
if (preg_match('/^(1st Semester|2nd Semester|Summer)/i', $formConfiguration["term"], $termMatch)) {
    $configuredSemester = $termMatch[1];
}
if (preg_match('/(\d{4}\s*-\s*\d{4})/', $formConfiguration["term"], $yearMatch)) {
    $configuredSchoolYear = preg_replace('/\s+/', '', $yearMatch[1]);
}

// Institutional clubs: student picks one; only that moderator sees the approval.
$clubOfficeId = 0;
$co = $conn->query("SELECT office_id FROM offices WHERE office_name = 'Institutional Club' LIMIT 1");
if ($co && ($coRow = $co->fetch_assoc())) $clubOfficeId = (int)$coRow["office_id"];
if ($co) $co->close();
$institutionalClubs = [];
$clubRes = $conn->query("SELECT c.club_id, c.club_name, u.full_name AS moderator_name FROM institutional_clubs c LEFT JOIN users u ON u.user_id = c.moderator_user_id WHERE c.status = 'active' ORDER BY c.club_name ASC");
if ($clubRes) {
    while ($cr = $clubRes->fetch_assoc()) $institutionalClubs[] = $cr;
    $clubRes->close();
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "create_request") {
    $schoolYear = $configuredSchoolYear;
    $semester = $configuredSemester;
    $institutionalClubId = (int)($_POST["institutional_club_id"] ?? 0);
    $chosenClubModeratorId = 0;
    $allowRequests = true;
    $table = $conn->query("SHOW TABLES LIKE 'system_settings'");
    if ($table && $table->num_rows) {
        $settingStmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'allow_clearance_requests' LIMIT 1");
        $settingStmt->execute();
        $setting = $settingStmt->get_result()->fetch_assoc();
        $settingStmt->close();
        $allowRequests = ($setting["setting_value"] ?? "1") === "1";
    }

    if (!$allowRequests) {
        $error = "New clearance requests are currently disabled.";
    } elseif ($schoolYear === "" || $semester === "") {
        $error = "The configured clearance term is incomplete. Please contact the administrator.";
    } elseif ($institutionalClubs && $institutionalClubId <= 0) {
        $error = "Please select your Institutional Club.";
    } else {
        if ($institutionalClubs && $institutionalClubId > 0) {
            $vc = $conn->prepare("SELECT club_id, moderator_user_id FROM institutional_clubs WHERE club_id = ? AND status = 'active' LIMIT 1");
            $vc->bind_param("i", $institutionalClubId);
            $vc->execute();
            $validClub = $vc->get_result()->fetch_assoc();
            $vc->close();
            if (!$validClub) $error = "Selected Institutional Club is no longer available.";
            else $chosenClubModeratorId = (int)($validClub["moderator_user_id"] ?? 0);
        } else {
            $institutionalClubId = 0;
        }
        if (empty($error)) {
            // Same term already requested: keep the familiar notice.
            $duplicateStmt = $conn->prepare("SELECT clearance_id FROM clearance_requests WHERE student_id = ? AND school_year = ? AND semester = ? LIMIT 1");
            $duplicateStmt->bind_param("iss", $student["student_id"], $schoolYear, $semester);
            $duplicateStmt->execute();
            $duplicate = $duplicateStmt->get_result()->fetch_assoc();
            $duplicateStmt->close();
            if ($duplicate) {
                $error = "You already have a clearance request for this school year and semester.";
            }
        }
        if (empty($error)) {
            // Another term while a request is still pending: must finish it first.
            $pendingStmt = $conn->prepare("SELECT school_year, semester FROM clearance_requests WHERE student_id = ? AND overall_status = 'pending' LIMIT 1");
            $pendingStmt->bind_param("i", $student["student_id"]);
            $pendingStmt->execute();
            $pending = $pendingStmt->get_result()->fetch_assoc();
            $pendingStmt->close();
            if ($pending) {
                $error = "You still have a pending " . trim((string)($pending["semester"] ?? "")) . " " . trim((string)($pending["school_year"] ?? "")) . " clearance. Complete it before requesting another term.";
            }
        }
        if (empty($error)) {
            $conn->begin_transaction();
            if ($institutionalClubId > 0) {
                $requestStmt = $conn->prepare("INSERT INTO clearance_requests (student_id, school_year, semester, institutional_club_id) VALUES (?, ?, ?, ?)");
                $requestStmt->bind_param("issi", $student["student_id"], $schoolYear, $semester, $institutionalClubId);
            } else {
                $requestStmt = $conn->prepare("INSERT INTO clearance_requests (student_id, school_year, semester) VALUES (?, ?, ?)");
                $requestStmt->bind_param("iss", $student["student_id"], $schoolYear, $semester);
            }
            $requestCreated = $requestStmt->execute();
            $clearanceId = $requestStmt->insert_id;
            $requestStmt->close();

            $transactionCreated = true;
            $requestOfficeIds = [];
            $offices = $conn->query("SELECT office_id, office_name FROM offices WHERE status = 'active'");
            if ($offices) {
                while ($office = $offices->fetch_assoc()) {
                    $oid = (int)$office["office_id"];
                    if ($clubOfficeId > 0 && $oid === $clubOfficeId) continue;
                    $officeKey = preg_replace('/[^a-z]/i', '', (string)$office["office_name"]);
                    if (!$isFirstYearStudent && strcasecmp($officeKey, "nstpnsrc") === 0) continue;
                    $requestOfficeIds[$oid] = true;
                }
            }
            if ($requestCreated && $offices) {
                $transactionStmt = $conn->prepare("INSERT INTO clearance_transactions (clearance_id, office_id) VALUES (?, ?)");
                foreach (array_keys($requestOfficeIds) as $requestOfficeId) {
                    $transactionStmt->bind_param("ii", $clearanceId, $requestOfficeId);
                    if (!$transactionStmt->execute()) {
                        $transactionCreated = false;
                    }
                }
                $transactionStmt->close();
                if ($transactionCreated && $institutionalClubId > 0 && $clubOfficeId > 0) {
                    $clubTxn = $conn->prepare("INSERT INTO clearance_transactions (clearance_id, office_id, club_id) VALUES (?, ?, ?)");
                    if ($clubTxn) {
                        $clubTxn->bind_param("iii", $clearanceId, $clubOfficeId, $institutionalClubId);
                        if (!$clubTxn->execute()) $transactionCreated = false;
                        $clubTxn->close();
                    } else $transactionCreated = false;
                }
                // Auto-attach published requirements (public to all + custom-mapped to this student).
                if ($transactionCreated && function_exists("apply_requirement_templates_to_request")) {
                    if (apply_requirement_templates_to_request($conn, $clearanceId, (int)$student["student_id"]) === false) $transactionCreated = false;
                }
            }

            if ($requestCreated && $transactionCreated) {
                $conn->commit();
                if (function_exists('notify_role')) {
                    $__sname = trim(($student["first_name"] ?? "") . " " . ($student["last_name"] ?? ""));
                    $__snum = $student["student_number"] ?? "";
                    notify_role($conn, 'admin', 'New clearance request', $__sname . ' (' . $__snum . ') submitted a request for ' . $semester . ' ' . $schoolYear . '.', 'new_request', (int)$clearanceId);
                    if ($requestOfficeIds) {
                        $__ids = implode(',', array_map('intval', array_keys($requestOfficeIds)));
                        $__q = $conn->query("SELECT DISTINCT user_id FROM office_assignatories WHERE office_id IN ($__ids) AND status='active' AND user_id IS NOT NULL UNION SELECT DISTINCT assigned_by_user_id FROM office_assignatories WHERE office_id IN ($__ids) AND status='active' AND assigned_by_user_id IS NOT NULL");
                        if ($__q) {
                            while ($__u = $__q->fetch_assoc()) {
                                $__uid = (int)(array_values($__u)[0] ?? 0);
                                if ($__uid > 0 && function_exists('notify_user')) notify_user($conn, $__uid, 'New clearance request', $__sname . ' (' . $__snum . ') needs review for ' . $semester . ' ' . $schoolYear . '.', 'new_request', (int)$clearanceId);
                            }
                            $__q->close();
                        }
                    }
                    if ($chosenClubModeratorId > 0 && function_exists('notify_user')) notify_user($conn, $chosenClubModeratorId, 'New clearance request', $__sname . ' (' . $__snum . ') needs club review for ' . $semester . ' ' . $schoolYear . '.', 'new_request', (int)$clearanceId);
                }
                header("Location: clearance.php?request=" . (int)$clearanceId);
                exit;
            }

            $conn->rollback();
            $error = "Unable to submit your clearance request.";
        }
    }
}

$requests = [];
if ($student) {
    $stmt = $conn->prepare("SELECT clearance_id, school_year, semester, overall_status, institutional_club_id, requested_at, updated_at FROM clearance_requests WHERE student_id = ? ORDER BY requested_at DESC");
    $stmt->bind_param("i", $student["student_id"]);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $requests[] = $row;
    $stmt->close();
}

$selectedId = (int)($_GET["request"] ?? ($requests[0]["clearance_id"] ?? 0));
$selectedRequest = null;
foreach ($requests as $request) {
    if ((int)$request["clearance_id"] === $selectedId) $selectedRequest = $request;
}
// Fallback: selected request may lack club id on legacy rows.
$selectedClubId = (int)($selectedRequest["institutional_club_id"] ?? 0);
$selectedClub = null;
if ($selectedClubId > 0) {
    $cs = $conn->prepare("SELECT c.club_name, u.full_name AS moderator_name FROM institutional_clubs c LEFT JOIN users u ON u.user_id = c.moderator_user_id WHERE c.club_id = ? LIMIT 1");
    $cs->bind_param("i", $selectedClubId);
    $cs->execute();
    $selectedClub = $cs->get_result()->fetch_assoc() ?: null;
    $cs->close();
}

$officeRows = [];
$officeStatuses = [];
if ($student) {
    // Shared with student/clearance_pdf.php so screen and PDF always match.
    $formData = clearance_form_data($conn, $student, $selectedClub, $selectedId);
    $officeRows = $formData["offices"];
    $officeStatuses = $formData["statuses"];
}
// The printable form shows the term of the request being viewed.
$formTerm = clearance_form_term($formConfiguration, $selectedRequest);

require "_header.php";
?>
<div class="page-heading"><div><h1>My Clearance</h1><p>Review every office requirement and the latest decision on your request.</p></div><div style="display:flex;gap:8px;flex-wrap:wrap;"><?php if ($selectedRequest): ?><a class="btn btn-secondary" href="clearance_pdf.php?request=<?= (int)$selectedId ?>" data-delete-confirm="Download the clearance form as PDF?" data-confirm-title="Print form?" data-confirm-action="Download" data-confirm-kind="warning" data-confirm-icon="printer"><i data-lucide="printer"></i> Print form (PDF)</a><?php else: ?><button class="btn btn-secondary" type="button" disabled><i data-lucide="printer"></i> Print form</button><?php endif; ?><?php if ($selectedRequest && ($selectedRequest["overall_status"] ?? "") === "cleared"): ?><a class="btn btn-primary" href="certificate.php?request=<?= (int)$selectedRequest["clearance_id"] ?>"><i data-lucide="award"></i> Download Clearance Certificate</a><?php endif; ?><a class="btn btn-primary" href="#request-form" data-form-target="request-form"><i data-lucide="plus"></i> New request</a></div></div>

<?php if (!empty($error)): ?><div class="notice notice-error"><?= e($error) ?></div><?php endif; ?>
<?php if ($selectedRequest && ($selectedRequest["overall_status"] ?? "") === "cleared"): ?>
<div class="notice notice-success">🎉 Your clearance is fully approved! <a href="certificate.php?request=<?= (int)$selectedRequest["clearance_id"] ?>" style="font-weight:700;text-decoration:underline;">Download your official Clearance Certificate here</a>.</div>
<?php endif; ?>

<div class="form-card announcement-form floating-form" id="request-form">
    <div class="floating-form-header">
        <h2>New clearance request</h2>
        <button class="floating-form-close" type="button" aria-label="Close form"><i data-lucide="x"></i></button>
    </div>
    <form method="post">
        <input type="hidden" name="action" value="create_request">
        <div class="configured-term-display">
            <span>Clearance term</span>
            <strong><?= e($formConfiguration["term"]) ?></strong>
            <small>This term is configured by the administrator.</small>
        </div>
        <?php if ($institutionalClubs): ?>
        <label>Institutional Club
            <select name="institutional_club_id" required>
                <option value="">Select your club</option>
                <?php foreach ($institutionalClubs as $clubOption): ?>
                    <option value="<?= (int)$clubOption["club_id"] ?>"><?= e($clubOption["club_name"] . ($clubOption["moderator_name"] ? " / " . $clubOption["moderator_name"] : "")) ?></option>
                <?php endforeach; ?>
            </select>
            <small class="field-help">Only your chosen club will appear on your form.</small>
        </label>
        <?php endif; ?>
        <button class="btn btn-primary" type="submit"><i data-lucide="send"></i> Submit request</button>
    </form>
</div>

<div class="clearance-layout clearance-layout-single">
    <section class="form-card clearance-detail">
        <?php if ($student): ?>
            <div class="student-clearance-form-wrapper">
                <div class="student-clearance-form-paper">
                    <div class="student-clearance-form-header">
                        <img class="student-clearance-logo" src="../assets/images/ckcm%20transparent.png" alt="Christ The King College De Maranding, Inc. logo">
                        <div class="student-clearance-header-text">
                            <h3><?= e($formConfiguration["school_name"]) ?></h3>
                            <p><?= e($formConfiguration["school_address"]) ?></p>
                            <p><?= e($formConfiguration["school_contact"]) ?></p>
                        </div>
                    </div>

                    <div class="student-clearance-title-wrap">
                        <p class="student-clearance-subtitle"><?= e($formConfiguration["department"]) ?></p>
                        <h2><?= e($formConfiguration["form_title"]) ?></h2>
                        <p class="student-clearance-term"><?= e($formTerm) ?></p>
                    </div>

                    <?php if ($selectedRequest): ?>
                        <div class="student-clearance-meta">
                            <div class="student-clearance-meta-row">
                                <span>Name:</span>
                                <strong><?= e(trim(($student["first_name"] ?? "") . " " . ($student["last_name"] ?? ""))) ?></strong>
                            </div>
                            <div class="student-clearance-meta-row">
                                <span>Course:</span>
                                <strong><?= e($student["course"] ?: "—") ?></strong>
                            </div>
                            <div class="student-clearance-meta-row">
                                <span>Year Level:</span>
                                <strong><?= e($student["year_level"] ?: "—") ?></strong>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="student-clearance-meta">
                            <div class="student-clearance-meta-row">
                                <span>Name:</span>
                                <strong><?= e(trim(($student["first_name"] ?? "") . " " . ($student["last_name"] ?? ""))) ?></strong>
                            </div>
                            <div class="student-clearance-meta-row">
                                <span>Course:</span>
                                <strong><?= e($student["course"] ?: "—") ?></strong>
                            </div>
                            <div class="student-clearance-meta-row">
                                <span>Year Level:</span>
                                <strong><?= e($student["year_level"] ?: "—") ?></strong>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="table-wrapper student-clearance-table-wrapper">
                    <table class="student-clearance-office-table">
                        <thead>
                            <tr>
                                <th>Office</th>
                                <th>In Charge</th>
                                <th>Signature</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($selectedRequest && $officeRows): foreach ($officeRows as $office): ?>
                                <?php $officeStatus = $officeStatuses[(int)$office["office_id"]] ?? "pending"; ?>
                                <tr>
                                    <td data-label="Office"><?= e($office["office_name"]) ?></td>
                                    <td data-label="In Charge"><?= e($office["assignatory_name"] ?: "—") ?></td>
                                    <td class="signature-cell" data-label="Signature">
                                        <span class="office-status-badge status-<?= e($officeStatus === "approved" ? "approved" : ($officeStatus === "rejected" ? "rejected" : "pending")) ?>">
                                            <?= e(ucfirst($officeStatus)) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                    </div>

                </div>
            </div>
        <?php endif; ?>
    </section>
</div>
<?php require "_footer.php"; ?>
