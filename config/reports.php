<?php
/**
 * Shared report helpers (admin/reports.php + admin/reports_pdf.php).
 *
 * The screen and the PDF export must always show the same numbers, so the
 * filters and the queries live here.
 */

/**
 * Normalize the report filters coming from the request.
 * Invalid dates are ignored, an inverted range is swapped.
 */
function reports_request_filters(array $get): array
{
    $filters = [
        "school_year" => trim((string)($get["school_year"] ?? "")),
        "semester" => trim((string)($get["semester"] ?? "")),
        "status" => trim((string)($get["status"] ?? "")),
        "course" => trim((string)($get["course"] ?? "")),
        "date_from" => trim((string)($get["date_from"] ?? "")),
        "date_to" => trim((string)($get["date_to"] ?? "")),
        "include_archived" => isset($get["include_archived"]) && $get["include_archived"] === "1",
    ];

    $isValidDate = function (string $date): bool {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
        [$year, $month, $day] = array_map('intval', explode('-', $date));
        return checkdate($month, $day, $year);
    };
    if ($filters["date_from"] !== "" && !$isValidDate($filters["date_from"])) $filters["date_from"] = "";
    if ($filters["date_to"] !== "" && !$isValidDate($filters["date_to"])) $filters["date_to"] = "";
    if ($filters["date_from"] !== "" && $filters["date_to"] !== "" && $filters["date_from"] > $filters["date_to"]) {
        [$filters["date_from"], $filters["date_to"]] = [$filters["date_to"], $filters["date_from"]];
    }
    if ($filters["status"] !== "" && !in_array($filters["status"], ["pending", "cleared", "not_cleared"], true)) {
        $filters["status"] = "";
    }

    return $filters;
}

/**
 * Human readable list of the active filters (used by the PDF header).
 */
function reports_filter_labels(array $filters): array
{
    $labels = [];
    if ($filters["school_year"] !== "") $labels[] = "S.Y. " . $filters["school_year"];
    if ($filters["semester"] !== "") $labels[] = $filters["semester"];
    if ($filters["status"] !== "") $labels[] = ucwords(str_replace("_", " ", $filters["status"]));
    if ($filters["course"] !== "") $labels[] = $filters["course"];
    if ($filters["date_from"] !== "" || $filters["date_to"] !== "") {
        $labels[] = ($filters["date_from"] !== "" ? date("M d, Y", strtotime($filters["date_from"])) : "Start")
            . " → " . ($filters["date_to"] !== "" ? date("M d, Y", strtotime($filters["date_to"])) : "Today");
    }
    if ($filters["include_archived"]) $labels[] = "Incl. archived";
    return $labels;
}

/**
 * Summary counts + detail rows for the report.
 * The summary ignores the status filter (it counts every status), like the screen.
 */
function reports_data(mysqli $conn, array $filters): array
{
    $where = [];
    $params = [];
    $types = "";
    if ($filters["school_year"] !== "") { $where[] = "cr.school_year=?"; $params[] = $filters["school_year"]; $types .= "s"; }
    if ($filters["semester"] !== "") { $where[] = "cr.semester=?"; $params[] = $filters["semester"]; $types .= "s"; }
    if ($filters["status"] !== "") { $where[] = "cr.overall_status=?"; $params[] = $filters["status"]; $types .= "s"; }
    if ($filters["course"] !== "") { $where[] = "s.course=?"; $params[] = $filters["course"]; $types .= "s"; }
    if ($filters["date_from"] !== "") { $where[] = "DATE(cr.requested_at)>=?"; $params[] = $filters["date_from"]; $types .= "s"; }
    if ($filters["date_to"] !== "") { $where[] = "DATE(cr.requested_at)<=?"; $params[] = $filters["date_to"]; $types .= "s"; }
    if (!$filters["include_archived"]) $where[] = "cr.is_archived=0";
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

    // Summary counts respect every filter except the status.
    $summaryWhere = [];
    $summaryParams = [];
    $summaryTypes = "";
    if ($filters["school_year"] !== "") { $summaryWhere[] = "cr.school_year=?"; $summaryParams[] = $filters["school_year"]; $summaryTypes .= "s"; }
    if ($filters["semester"] !== "") { $summaryWhere[] = "cr.semester=?"; $summaryParams[] = $filters["semester"]; $summaryTypes .= "s"; }
    if ($filters["course"] !== "") { $summaryWhere[] = "s.course=?"; $summaryParams[] = $filters["course"]; $summaryTypes .= "s"; }
    if ($filters["date_from"] !== "") { $summaryWhere[] = "DATE(cr.requested_at)>=?"; $summaryParams[] = $filters["date_from"]; $summaryTypes .= "s"; }
    if ($filters["date_to"] !== "") { $summaryWhere[] = "DATE(cr.requested_at)<=?"; $summaryParams[] = $filters["date_to"]; $summaryTypes .= "s"; }
    if (!$filters["include_archived"]) $summaryWhere[] = "cr.is_archived=0";
    $summarySql = $summaryWhere ? "WHERE " . implode(" AND ", $summaryWhere) : "";

    $summary = ["total" => 0, "pending" => 0, "cleared" => 0, "not_cleared" => 0];
    $stmt = $conn->prepare("SELECT COUNT(*) t, SUM(cr.overall_status='pending') p, SUM(cr.overall_status='cleared') c, SUM(cr.overall_status='not_cleared') n FROM clearance_requests cr INNER JOIN students s ON s.student_id=cr.student_id $summarySql");
    if ($stmt) {
        if ($summaryParams) $stmt->bind_param($summaryTypes, ...$summaryParams);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $summary = [
            "total" => (int)($result["t"] ?? 0),
            "pending" => (int)($result["p"] ?? 0),
            "cleared" => (int)($result["c"] ?? 0),
            "not_cleared" => (int)($result["n"] ?? 0),
        ];
        $stmt->close();
    }

    $rows = [];
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
    }

    return ["summary" => $summary, "rows" => $rows];
}
