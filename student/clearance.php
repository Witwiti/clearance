<?php
$pageTitle = "My Clearance";
$activePage = "clearance";
require_once "../config/db.php";
require_once "../config/auth.php";
require_role("student");

$studentStmt = $conn->prepare("SELECT * FROM students WHERE user_id = ? LIMIT 1");
$studentStmt->bind_param("i", $_SESSION["user_id"]);
$studentStmt->execute();
$student = $studentStmt->get_result()->fetch_assoc();
$studentStmt->close();
$isFirstYearStudent = strcasecmp(trim((string)($student["year_level"] ?? "")), "1st Year") === 0;

$formConfiguration = [
    "school_name" => "CHRIST THE KING COLLEGE DE MARANDING, INC.",
    "school_address" => "Maranding, Lala, Lanao del Norte, Philippines 9211",
    "school_contact" => "Tel. (063) 338-7039",
    "department" => "COLLEGE DEPARTMENT",
    "form_title" => "STUDENT CLEARANCE FORM",
    "term" => "2nd Semester S.Y. 2025 - 2026"
];
$configurationTable = $conn->query("SHOW TABLES LIKE 'system_settings'");
if ($configurationTable && $configurationTable->num_rows) {
    $configurationResult = $conn->query("SELECT setting_key, setting_value FROM system_settings");
    if ($configurationResult) {
        while ($setting = $configurationResult->fetch_assoc()) {
            $settingKey = (string)$setting["setting_key"];
            if (strpos($settingKey, "clearance_") !== 0) continue;
            $formConfigurationKey = substr($settingKey, 10);
            if (array_key_exists($formConfigurationKey, $formConfiguration) && trim((string)$setting["setting_value"]) !== "") {
                $formConfiguration[$formConfigurationKey] = $setting["setting_value"];
            }
        }
    }
}
$configuredSemester = "";
$configuredSchoolYear = "";
if (preg_match('/^(1st Semester|2nd Semester|Summer)/i', $formConfiguration["term"], $termMatch)) {
    $configuredSemester = $termMatch[1];
}
if (preg_match('/(\d{4}\s*-\s*\d{4})/', $formConfiguration["term"], $yearMatch)) {
    $configuredSchoolYear = preg_replace('/\s+/', '', $yearMatch[1]);
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($GLOBALS["_POST"]["action"] ?? "") === "create_request") {
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

    if (!$allowRequests) {
        $error = "New clearance requests are currently disabled.";
    } elseif ($schoolYear === "" || $semester === "") {
        $error = "The configured clearance term is incomplete. Please contact the administrator.";
    } else {
        $duplicateStmt = $conn->prepare("SELECT clearance_id FROM clearance_requests WHERE student_id = ? AND school_year = ? AND semester = ? LIMIT 1");
        $duplicateStmt->bind_param("iss", $student["student_id"], $schoolYear, $semester);
        $duplicateStmt->execute();
        $duplicate = $duplicateStmt->get_result()->fetch_assoc();
        $duplicateStmt->close();

        if ($duplicate) {
            $error = "You already have a clearance request for this school year and semester.";
        } else {
            $conn->begin_transaction();
            $requestStmt = $conn->prepare("INSERT INTO clearance_requests (student_id, school_year, semester) VALUES (?, ?, ?)");
            $requestStmt->bind_param("iss", $student["student_id"], $schoolYear, $semester);
            $requestCreated = $requestStmt->execute();
            $clearanceId = $requestStmt->insert_id;
            $requestStmt->close();

            $transactionCreated = true;
            $requestOfficeIds = [];
            $offices = $conn->query("SELECT office_id FROM offices WHERE status = 'active'");
            if ($offices) {
                while ($office = $offices->fetch_assoc()) {
                    $officeKey = preg_replace('/[^a-z]/i', '', (string)$office["office_name"]);
                    if (!$isFirstYearStudent && strcasecmp($officeKey, "nstpnsrc") === 0) continue;
                    $requestOfficeIds[(int)$office["office_id"]] = true;
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
            }

            if ($requestCreated && $transactionCreated) {
                $conn->commit();
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
    $stmt = $conn->prepare("SELECT clearance_id, school_year, semester, overall_status, requested_at, updated_at FROM clearance_requests WHERE student_id = ? ORDER BY requested_at DESC");
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

$transactions = [];
$items = [];
$officeRows = [];
$officeStatuses = [];
if ($student) {
    $officeStmt = $conn->prepare(
        "SELECT o.office_id, o.office_name, o.description, o.status,
                oa.position,
            COALESCE(oa.assignatory_name, u.full_name) AS assignatory_name
         FROM offices o
         LEFT JOIN office_assignatories oa ON oa.office_id = o.office_id AND oa.status = 'active'
         LEFT JOIN users u ON u.user_id = oa.user_id
         WHERE o.status = 'active'
         GROUP BY o.office_id, o.office_name, o.description, o.status, oa.position, u.full_name
         ORDER BY o.office_name ASC"
    );
    $officeStmt->execute();
    $officeRows = $officeStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    if (!$isFirstYearStudent) {
        $officeRows = array_values(array_filter($officeRows, function (array $office): bool {
            $officeKey = preg_replace('/[^a-z]/i', '', (string)$office["office_name"]);
            return strcasecmp($officeKey, "nstpnsrc") !== 0;
        }));
    }
    $officeStmt->close();
}
if ($selectedRequest) {
    $stmt = $conn->prepare("SELECT o.office_name, o.description, ct.status, ct.remarks, ct.reviewed_at, ct.office_id FROM clearance_transactions ct INNER JOIN offices o ON o.office_id = ct.office_id WHERE ct.clearance_id = ? ORDER BY o.office_name");
    $stmt->bind_param("i", $selectedId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
        $officeStatuses[(int)$row["office_id"]] = $row["status"] ?? "pending";
    }
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT ci.item_name, ci.notes, ci.is_required, ci.is_completed, o.office_name
         FROM clearance_items ci
         LEFT JOIN offices o ON o.office_id = ci.office_id
         WHERE ci.clearance_id = ?
         ORDER BY o.office_name, ci.item_name"
    );
    $stmt->bind_param("i", $selectedId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $items[] = $row;
    $stmt->close();
}

require "_header.php";
?>
<div class="page-heading"><div><h1>My Clearance</h1><p>Review every office requirement and the latest decision on your request.</p></div><a class="btn btn-primary" href="#request-form" data-form-target="request-form"><i data-lucide="plus"></i> New request</a></div>

<?php if (!empty($error)): ?><div class="notice notice-error"><?= e($error) ?></div><?php endif; ?>

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
                        <p class="student-clearance-term"><?= e($formConfiguration["term"]) ?></p>
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
                                    <td><?= e($office["office_name"]) ?></td>
                                    <td><?= e($office["assignatory_name"] ? ($office["assignatory_name"] . ($office["position"] ? " — " . $office["position"] : "")) : "—") ?></td>
                                    <td class="signature-cell">
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
