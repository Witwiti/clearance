<?php
/**
 * Shared helpers for the student clearance form.
 *
 * Used by the on-screen form (student/clearance.php) and the PDF export
 * (student/clearance_pdf.php) so both always show the same offices,
 * signatories, statuses and term.
 */

/**
 * School header + term configuration (Form Configuration settings with defaults).
 */
function clearance_form_settings(mysqli $conn): array
{
    $config = [
        "school_name" => "CHRIST THE KING COLLEGE DE MARANDING, INC.",
        "school_address" => "Maranding, Lala, Lanao del Norte, Philippines 9211",
        "school_contact" => "Tel. (063) 338-7039",
        "department" => "COLLEGE DEPARTMENT",
        "form_title" => "STUDENT CLEARANCE FORM",
        "term" => "2nd Semester S.Y. 2025 - 2026",
    ];
    $table = $conn->query("SHOW TABLES LIKE 'system_settings'");
    if ($table && $table->num_rows) {
        $result = $conn->query("SELECT setting_key, setting_value FROM system_settings");
        if ($result) {
            while ($setting = $result->fetch_assoc()) {
                $settingKey = (string)($setting["setting_key"] ?? "");
                if (strpos($settingKey, "clearance_") !== 0) continue;
                $formConfigurationKey = substr($settingKey, 10);
                if (array_key_exists($formConfigurationKey, $config) && trim((string)$setting["setting_value"]) !== "") {
                    $config[$formConfigurationKey] = $setting["setting_value"];
                }
            }
            $result->close();
        }
        $table->close();
    }
    return $config;
}

/**
 * Term printed on the form: the term of the request being printed, otherwise
 * the currently configured term.
 */
function clearance_form_term(array $settings, ?array $request): string
{
    if ($request) {
        $semester = trim((string)($request["semester"] ?? ""));
        $schoolYear = trim((string)($request["school_year"] ?? ""));
        if ($semester !== "" && $schoolYear !== "") return $semester . " S.Y. " . $schoolYear;
    }
    return (string)($settings["term"] ?? "");
}

/**
 * Office rows (office + in charge) plus the approval status of every office
 * for one clearance request.
 *
 * $selectedClub: the institutional club row of the request (club_name, moderator_name) or null.
 */
function clearance_form_data(mysqli $conn, array $student, ?array $selectedClub, int $selectedRequestId): array
{
    $isFirstYearStudent = strcasecmp(trim((string)($student["year_level"] ?? "")), "1st Year") === 0;
    $studentCourse = trim((string)($student["course"] ?? ""));

    // Institutional clubs: student picks one; only that moderator sees the approval.
    $clubOfficeId = 0;
    $co = $conn->query("SELECT office_id FROM offices WHERE office_name = 'Institutional Club' LIMIT 1");
    if ($co && ($coRow = $co->fetch_assoc())) $clubOfficeId = (int)$coRow["office_id"];
    if ($co) $co->close();

    $officeRows = [];
    $activeOffices = [];
    $officeResult = $conn->query("SELECT office_id, office_name, description, status FROM offices WHERE status = 'active' ORDER BY office_name ASC");
    if ($officeResult) {
        while ($office = $officeResult->fetch_assoc()) $activeOffices[] = $office;
        $officeResult->close();
    }

    $bestByOffice = [];
    // DSA-assigned SSC rows outrank admin-global rows for the SSC office.
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
            $isDeptMatch = $studentCourse !== "" && $ownerCourse !== "" && strcasecmp($ownerCourse, $studentCourse) === 0;
            $isGlobal = $candidate["assigned_by_user_id"] === null || $ownerCourse === "";
            if (!$isDeptMatch && !$isGlobal) continue;
            // Head/Dean seat: a dean-flagged linked user only heads their own course.
            $linkedDeanCourse = ((int)($candidate["linked_is_dean"] ?? 0) === 1) ? trim((string)($candidate["linked_dean_course"] ?? "")) : "";
            if ($linkedDeanCourse !== "" && $studentCourse !== "" && isset($headDeanOfficeIds[$candidateOfficeId]) && strcasecmp($linkedDeanCourse, $studentCourse) !== 0) continue;
            $priority = $isDeptMatch ? 0 : 1;
            // DSA boost: DSA-assigned SSC signatory wins over admin generic.
            if ($dsaSscOfficeId > 0 && $candidateOfficeId === $dsaSscOfficeId && isset($dsaUserIds[(int)($candidate["assigned_by_user_id"] ?? 0)])) $priority = 0;
            if (!isset($bestByOffice[$candidateOfficeId]) || $priority < $bestByOffice[$candidateOfficeId]["_priority"]) {
                $candidate["_priority"] = $priority;
                $bestByOffice[$candidateOfficeId] = $candidate;
            }
        }
        $assignatoryResult->close();
    }
    foreach ($activeOffices as $office) {
        $officeId = (int)$office["office_id"];
        $best = $bestByOffice[$officeId] ?? null;
        $signatoryName = null;
        $position = null;
        if ($best) {
            $signatoryName = trim((string)($best["assignatory_name"] ?? "")) !== "" ? $best["assignatory_name"] : ($best["user_full_name"] ?? null);
            if (trim((string)$signatoryName) === "") $signatoryName = null;
            $position = trim((string)($best["position"] ?? "")) !== "" ? $best["position"] : null;
        }
        $displayName = $office["office_name"];
        // Institutional Club: show only the chosen club, e.g. "Institutional Club - Choir / Juan Dela Cruz".
        if ($clubOfficeId > 0 && $officeId === $clubOfficeId) {
            if ($selectedClub) {
                $displayName = "Institutional Club - " . $selectedClub["club_name"];
                $signatoryName = $selectedClub["moderator_name"] ?: $signatoryName;
                $position = "Club Moderator";
            } else {
                // No club chosen (legacy request): hide generic row later.
                $displayName = "Institutional Club";
            }
        }
        $officeRows[] = [
            "office_id" => $officeId,
            "office_name" => $displayName,
            "description" => $office["description"],
            "status" => $office["status"],
            "position" => $position,
            "assignatory_name" => $signatoryName,
        ];
    }
    // Hide generic Institutional Club row when no club was chosen (legacy).
    if (!$selectedClub && $clubOfficeId > 0) {
        $officeRows = array_values(array_filter($officeRows, fn(array $o): bool => (int)$o["office_id"] !== $clubOfficeId));
    }
    if (!$isFirstYearStudent) {
        $officeRows = array_values(array_filter($officeRows, function (array $office): bool {
            $officeKey = preg_replace('/[^a-z]/i', '', (string)$office["office_name"]);
            return strcasecmp($officeKey, "nstpnsrc") !== 0;
        }));
    }

    // Approval status per office for the printed request.
    $officeStatuses = [];
    if ($selectedRequestId > 0) {
        $stmt = $conn->prepare("SELECT office_id, status FROM clearance_transactions WHERE clearance_id = ?");
        $stmt->bind_param("i", $selectedRequestId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $officeStatuses[(int)$row["office_id"]] = $row["status"] ?? "pending";
        }
        $stmt->close();
    }

    return [
        "offices" => $officeRows,
        "statuses" => $officeStatuses,
        "club_office_id" => $clubOfficeId,
    ];
}

/**
 * File name used when the form is downloaded as PDF.
 */
function clearance_form_filename(array $student, array $request): string
{
    $number = trim((string)($student["student_number"] ?? ""));
    $number = preg_replace('/[^A-Za-z0-9]+/', '-', $number);
    $number = trim((string)$number, '-');
    if ($number === "") $number = "student";
    return "clearance-form-" . $number . "-" . (int)($request["clearance_id"] ?? 0) . ".pdf";
}
