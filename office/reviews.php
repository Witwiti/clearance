<?php
$pageTitle = "Clearance Reviews";
$activePage = "reviews";
require_once "../config/db.php";
require_once "../config/auth.php";
require_role("office");

$currentOfficeUserId = (int)($_SESSION["user_id"] ?? 0);
$clubIds = [];
$clubIdStmt = $conn->prepare("SELECT club_id FROM institutional_clubs WHERE moderator_user_id = ? AND status = 'active'");
if ($clubIdStmt) {
    $clubIdStmt->bind_param("i", $currentOfficeUserId);
    $clubIdStmt->execute();
    $clubIdRes = $clubIdStmt->get_result();
    while ($cr = $clubIdRes->fetch_assoc()) $clubIds[] = (int)($cr["club_id"] ?? 0);
    $clubIdStmt->close();
}
$clubIds = array_values(array_unique(array_filter($clubIds)));

// DSA resolution: assigned DSA user manages SSC President signatories (dean pattern).
$dsaOfficeId = 0;
$sscOfficeId = 0;
$officeRes = $conn->query("SELECT office_id, office_name FROM offices WHERE status = 'active'");
if ($officeRes) {
    while ($o = $officeRes->fetch_assoc()) {
        $name = (string)($o["office_name"] ?? "");
        if ($dsaOfficeId <= 0 && (stripos($name, "Student Affairs") !== false || preg_match('/\(DSA\)/i', $name))) $dsaOfficeId = (int)$o["office_id"];
        if ($sscOfficeId <= 0 && stripos($name, "SSC President") !== false) $sscOfficeId = (int)$o["office_id"];
    }
    $officeRes->close();
}
if ($dsaOfficeId <= 0) $dsaOfficeId = 1;
if ($sscOfficeId <= 0) $sscOfficeId = 7;
$isDsaUser = false;
if ($dsaOfficeId > 0 && $currentOfficeUserId > 0) {
    $dq = $conn->prepare("SELECT assignatory_id FROM office_assignatories WHERE office_id = ? AND user_id = ? AND status = 'active' LIMIT 1");
    if ($dq) {
        $dq->bind_param("ii", $dsaOfficeId, $currentOfficeUserId);
        $dq->execute();
        $isDsaUser = (bool)$dq->get_result()->fetch_assoc();
        $dq->close();
    }
}

// Direct office assignment: admin links an office user via office_assignatories.user_id.
// These users must also review (e.g. Registrar, Librarian, Finance) — not only deans/DSA.
$directOfficeIds = [];
$directStmt = $conn->prepare("SELECT DISTINCT office_id FROM office_assignatories WHERE status = 'active' AND user_id = ?");
if ($directStmt) {
    $directStmt->bind_param("i", $currentOfficeUserId);
    $directStmt->execute();
    $directRes = $directStmt->get_result();
    if ($directRes) {
        while ($dr = $directRes->fetch_assoc()) $directOfficeIds[] = (int)($dr["office_id"] ?? 0);
    }
    $directStmt->close();
}
$directOfficeIds = array_values(array_unique(array_filter($directOfficeIds)));

if (!is_dean_user() && !$clubIds && !$isDsaUser && !$directOfficeIds) {
    require "_header.php";
    ?>
    <div class="page-heading"><div><h1>Clearance Reviews</h1><p>Only assigned office users, the College dean, an Institutional Club moderator, or the assigned DSA user can approve clearance reviews.</p></div></div>
    <div class="empty-panel"><i data-lucide="shield-alert"></i><h3>Access restricted</h3><p>Your office account is not assigned to any office yet. Please contact the administrator.</p></div>
    <?php require "_footer.php"; exit();
}

$message = $_SESSION["flash_message"] ?? "";
unset($_SESSION["flash_message"]);
$error = "";

$officeIds = [];
$deanOfficeStmt = $conn->prepare("SELECT DISTINCT office_id FROM office_assignatories WHERE status = 'active' AND assigned_by_user_id = ?");
$deanOfficeStmt->bind_param("i", $_SESSION["user_id"]);
$deanOfficeStmt->execute();
$deanOfficeResult = $deanOfficeStmt->get_result();
if ($deanOfficeResult) {
    while ($office = $deanOfficeResult->fetch_assoc()) {
        $officeIds[] = (int)($office["office_id"] ?? 0);
    }
}
$deanOfficeStmt->close();
$officeIds = array_values(array_unique($officeIds));

// ---------------------------------------------------------------------------
// Shared review helpers
// ---------------------------------------------------------------------------

// True when the signed-in office user may act on a transaction (office step or club step).
$canReviewTxn = function (int $officeId, int $txnClubId) use ($conn, $clubIds, $isDsaUser, $sscOfficeId, $currentOfficeUserId, $officeIds, $directOfficeIds): bool {
    if ($txnClubId > 0) return in_array($txnClubId, $clubIds, true);
    if ($isDsaUser && $officeId === $sscOfficeId) {
        // DSA reviews SSC transactions they assigned (no course filter, institutional).
        $dchk = $conn->prepare("SELECT assignatory_id FROM office_assignatories WHERE office_id = ? AND assigned_by_user_id = ? AND status = 'active' LIMIT 1");
        if (!$dchk) return false;
        $dchk->bind_param("ii", $sscOfficeId, $currentOfficeUserId);
        $dchk->execute();
        $ok = (bool)$dchk->get_result()->fetch_assoc();
        $dchk->close();
        return $ok;
    }
    return in_array($officeId, $officeIds, true) || in_array($officeId, $directOfficeIds, true);
};

// Offices of one request this reviewer is allowed to act on.
$authorizedOfficesForRequest = function (int $clearanceId) use ($conn, $canReviewTxn): array {
    $offices = [];
    if ($clearanceId <= 0) return $offices;
    $txns = $conn->prepare("SELECT office_id, club_id FROM clearance_transactions WHERE clearance_id = ?");
    if (!$txns) return $offices;
    $txns->bind_param("i", $clearanceId);
    $txns->execute();
    $txnRes = $txns->get_result();
    while ($t = $txnRes->fetch_assoc()) {
        $oid = (int)($t["office_id"] ?? 0);
        if ($oid > 0 && $canReviewTxn($oid, (int)($t["club_id"] ?? 0))) $offices[$oid] = true;
    }
    $txns->close();
    return array_keys($offices);
};

// Recompute the request-level status from the office steps (same rules as admin/clearance.php).
$recalculateOverallStatus = function (int $clearanceId) use ($conn): string {
    $overallStatus = "pending";
    $statusStmt = $conn->prepare("SELECT status FROM clearance_transactions WHERE clearance_id = ?");
    if ($statusStmt) {
        $statusStmt->bind_param("i", $clearanceId);
        $statusStmt->execute();
        $statusResult = $statusStmt->get_result();

        $total = 0;
        $rejected = 0;
        $approved = 0;
        while ($row = $statusResult->fetch_assoc()) {
            $total++;
            $transactionStatus = (string)($row["status"] ?? "pending");
            if ($transactionStatus === "rejected") {
                $rejected++;
            } elseif ($transactionStatus === "approved") {
                $approved++;
            }
        }
        $statusStmt->close();

        if ($total === 0) {
            $overallStatus = "pending";
        } elseif ($rejected > 0) {
            $overallStatus = "not_cleared";
        } elseif ($approved === $total) {
            $overallStatus = "cleared";
        }
    }

    $updateOverallStmt = $conn->prepare("UPDATE clearance_requests SET overall_status = ? WHERE clearance_id = ?");
    if ($updateOverallStmt) {
        $updateOverallStmt->bind_param("si", $overallStatus, $clearanceId);
        $updateOverallStmt->execute();
        $updateOverallStmt->close();
    }
    return $overallStatus;
};

// Human date for the "Updated" column. Empty and zero dates are treated as "Waiting"
// so a malformed timestamp never renders as a bogus 1970 date.
$formatReviewedAt = function ($value): string {
    $raw = trim((string)($value ?? ""));
    if ($raw === "" || strpos($raw, "0000-00-00") === 0) return "Waiting";
    $timestamp = strtotime($raw);
    if ($timestamp === false) return "Waiting";
    return date("M d, Y", $timestamp);
};

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    if ($action === "bulk_update") {
        // One decision per student: applies to every step of this request
        // the reviewer is authorized for (same rules as single updates).
        $clearanceId = (int)($_POST["clearance_id"] ?? 0);
        $status = in_array($_POST["status"] ?? "", ["pending", "approved", "rejected"], true) ? $_POST["status"] : "pending";
        $remarks = trim((string)($_POST["remarks"] ?? ""));

        $authorizedOffices = $authorizedOfficesForRequest($clearanceId);

        if ($authorizedOffices) {
            $okAll = true;
            $stmt = $conn->prepare("UPDATE clearance_transactions SET status = ?, remarks = ?, reviewed_at = NOW() WHERE clearance_id = ? AND office_id = ?");
            if ($stmt) {
                foreach ($authorizedOffices as $oid) {
                    $stmt->bind_param("ssii", $status, $remarks, $clearanceId, $oid);
                    if (!$stmt->execute()) $okAll = false;
                }
                $stmt->close();
            } else {
                $okAll = false;
            }
            if ($okAll) {
                $overallStatus = $recalculateOverallStatus($clearanceId);

                // Automatic student notification (approved / rejected / needs action).
                $su = $conn->prepare("SELECT s.user_id, CONCAT(s.first_name,' ',s.last_name) AS nm FROM clearance_requests cr INNER JOIN students s ON s.student_id=cr.student_id WHERE cr.clearance_id=? LIMIT 1");
                if ($su) {
                    $su->bind_param("i", $clearanceId);
                    $su->execute();
                    $sr = $su->get_result()->fetch_assoc();
                    $su->close();
                    if ($sr && (int)($sr["user_id"] ?? 0) > 0 && function_exists('notify_user')) {
                        $verb = $status === 'approved' ? 'approved' : ($status === 'rejected' ? 'rejected — action needed' : 'set back to pending');
                        $msg = 'An office reviewer ' . $verb . ' ' . count($authorizedOffices) . ' office step(s) of your clearance request.' . ($remarks !== '' ? ' Remarks: ' . $remarks : '') . ' Overall status: ' . $overallStatus . '.';
                        notify_user($conn, (int)$sr["user_id"], 'Clearance ' . $status, $msg, 'review_update', $clearanceId);
                    }
                }

                $_SESSION["flash_message"] = "Clearance update saved.";
                header("Location: reviews.php");
                exit;
            } else {
                $error = "Unable to update this clearance review.";
            }
        } else {
            $error = "You are not assigned to this office.";
        }
    } elseif ($action === "update_requirements") {
        // Requirement compliance (clearance_items) for the offices this reviewer owns.
        // Works in both directions: a requirement can be marked as complied, or
        // un-marked when the student has to be sent back for action — even when
        // the student already complied and the request was already cleared.
        $clearanceId = (int)($_POST["clearance_id"] ?? 0);
        $authorizedOffices = $authorizedOfficesForRequest($clearanceId);

        if (!$authorizedOffices) {
            $error = "You are not assigned to this office.";
        } else {
            // Only the checked boxes are posted; everything else is un-marked.
            $postedItemIds = [];
            $postedItems = $_POST["item_completed"] ?? [];
            if (is_array($postedItems)) {
                foreach ($postedItems as $postedItemId => $postedValue) {
                    $postedItemId = (int)$postedItemId;
                    if ($postedItemId > 0) $postedItemIds[$postedItemId] = true;
                }
            }

            $officePlaceholders = implode(",", array_fill(0, count($authorizedOffices), "?"));
            $itemStmt = $conn->prepare(
                "SELECT item_id, office_id, item_name, is_required, is_completed
                 FROM clearance_items
                 WHERE clearance_id = ? AND office_id IN (" . $officePlaceholders . ")
                 ORDER BY office_id, item_name"
            );

            $markedCount = 0;
            $reopenedCount = 0;
            $reopenedOffices = [];

            if ($itemStmt) {
                $itemStmt->bind_param(str_repeat("i", count($authorizedOffices) + 1), $clearanceId, ...$authorizedOffices);
                $itemStmt->execute();
                $itemRes = $itemStmt->get_result();
                $updateStmt = $conn->prepare("UPDATE clearance_items SET is_completed = ? WHERE item_id = ? AND clearance_id = ? AND office_id = ?");
                while ($item = $itemRes->fetch_assoc()) {
                    $itemId = (int)($item["item_id"] ?? 0);
                    $officeId = (int)($item["office_id"] ?? 0);
                    $wasCompleted = !empty($item["is_completed"]);
                    $isCompleted = isset($postedItemIds[$itemId]);
                    if ($wasCompleted === $isCompleted) continue;
                    if (!$updateStmt) break;
                    $flag = $isCompleted ? 1 : 0;
                    $updateStmt->bind_param("iiii", $flag, $itemId, $clearanceId, $officeId);
                    if ($updateStmt->execute()) {
                        if ($isCompleted) {
                            $markedCount++;
                        } else {
                            $reopenedCount++;
                            if (!empty($item["is_required"])) $reopenedOffices[$officeId] = true;
                        }
                    }
                }
                if ($updateStmt) $updateStmt->close();
                $itemStmt->close();
            }

            // Un-marking a required requirement reopens an already approved office step.
            $reopenedSteps = 0;
            if ($reopenedOffices) {
                $stepStmt = $conn->prepare(
                    "UPDATE clearance_transactions
                     SET status = 'pending', reviewed_by = ?, reviewed_at = NOW()
                     WHERE clearance_id = ? AND office_id = ? AND status = 'approved'"
                );
                if ($stepStmt) {
                    foreach (array_keys($reopenedOffices) as $officeId) {
                        $stepStmt->bind_param("iii", $currentOfficeUserId, $clearanceId, $officeId);
                        if ($stepStmt->execute() && $stepStmt->affected_rows > 0) $reopenedSteps++;
                    }
                    $stepStmt->close();
                }
            }

            $overallStatus = $recalculateOverallStatus($clearanceId);

            // Tell the student what changed on their requirements.
            $su = $conn->prepare("SELECT s.user_id FROM clearance_requests cr INNER JOIN students s ON s.student_id=cr.student_id WHERE cr.clearance_id=? LIMIT 1");
            if ($su) {
                $su->bind_param("i", $clearanceId);
                $su->execute();
                $sr = $su->get_result()->fetch_assoc();
                $su->close();
                if ($sr && (int)($sr["user_id"] ?? 0) > 0 && function_exists('notify_user')) {
                    $parts = [];
                    if ($markedCount > 0) $parts[] = $markedCount . ' requirement(s) marked as complied';
                    if ($reopenedCount > 0) $parts[] = $reopenedCount . ' requirement(s) sent back for action';
                    $msg = 'An office reviewer updated the requirement status on your clearance request'
                        . ($parts ? ': ' . implode(', ', $parts) : '.')
                        . '. Overall status: ' . $overallStatus . '.';
                    notify_user($conn, (int)$sr["user_id"], 'Clearance requirements updated', $msg, 'requirements_update', $clearanceId);
                }
            }

            $flashParts = [];
            if ($markedCount > 0) $flashParts[] = $markedCount . ' requirement(s) marked as complied';
            if ($reopenedCount > 0) $flashParts[] = $reopenedCount . ' requirement(s) sent back for action';
            if ($reopenedSteps > 0) $flashParts[] = $reopenedSteps . ' approved step(s) set back to pending';
            $_SESSION["flash_message"] = $flashParts
                ? ucfirst(implode('; ', $flashParts)) . '.'
                : "Requirement status saved.";
            header("Location: reviews.php");
            exit;
        }
    }
}

$officeFilter = (int)($_GET["office_filter"] ?? 0);
$clubFilter = (int)($_GET["club_filter"] ?? 0);
$deanContextStmt = $conn->prepare("SELECT college_dean_course FROM users WHERE user_id = ? LIMIT 1");
$deanContextStmt->bind_param("i", $_SESSION["user_id"]);
$deanContextStmt->execute();
$deanContext = $deanContextStmt->get_result()->fetch_assoc() ?: [];
$deanContextStmt->close();
$deanCourse = trim((string)($deanContext["college_dean_course"] ?? ""));
$officeOptions = [];
$clubOptions = [];
if (is_dean_user() && $officeIds) {
    $officeOptionStmt = $conn->prepare(
        "SELECT oa.office_id, o.office_name, oa.assignatory_name, oa.position
         FROM office_assignatories oa
         INNER JOIN offices o ON o.office_id = oa.office_id
         WHERE oa.assigned_by_user_id = ? AND oa.status = 'active'
         ORDER BY o.office_name, oa.assignatory_name"
    );
    $officeOptionStmt->bind_param("i", $_SESSION["user_id"]);
    $officeOptionStmt->execute();
    $officeOptionResult = $officeOptionStmt->get_result();
    while ($officeOption = $officeOptionResult->fetch_assoc()) {
        $officeOptions[] = $officeOption;
    }
    $officeOptionStmt->close();
}
if ($directOfficeIds) {
    $directOptionStmt = $conn->prepare(
        "SELECT oa.office_id, o.office_name, 'My assignment' AS assignatory_name, oa.position
         FROM office_assignatories oa
         INNER JOIN offices o ON o.office_id = oa.office_id
         WHERE oa.user_id = ? AND oa.status = 'active'
         ORDER BY o.office_name"
    );
    if ($directOptionStmt) {
        $directOptionStmt->bind_param("i", $currentOfficeUserId);
        $directOptionStmt->execute();
        $directOptionResult = $directOptionStmt->get_result();
        while ($do = $directOptionResult->fetch_assoc()) {
            // Avoid duplicate office entries when user is both dean-owner and directly assigned.
            $dup = false;
            foreach ($officeOptions as $ex) {
                if ((int)$ex["office_id"] === (int)$do["office_id"]) { $dup = true; break; }
            }
            if (!$dup) $officeOptions[] = $do;
        }
        $directOptionStmt->close();
    }
}
if ($clubIds) {
    $cOpt = $conn->query("SELECT club_id, club_name FROM institutional_clubs WHERE moderator_user_id = " . (int)$currentOfficeUserId . " AND status = 'active' ORDER BY club_name ASC");
    if ($cOpt) {
        while ($co = $cOpt->fetch_assoc()) $clubOptions[] = $co;
        $cOpt->close();
    }
}
$dsaSscOptions = [];
if ($isDsaUser) {
    $dsaOpt = $conn->prepare("SELECT assignatory_name, position FROM office_assignatories WHERE office_id = ? AND assigned_by_user_id = ? AND status = 'active' ORDER BY assignatory_name ASC");
    if ($dsaOpt) {
        $dsaOpt->bind_param("ii", $sscOfficeId, $currentOfficeUserId);
        $dsaOpt->execute();
        $dsaRes = $dsaOpt->get_result();
        while ($dr = $dsaRes->fetch_assoc()) $dsaSscOptions[] = $dr;
        $dsaOpt->close();
    }
}

$reviewRows = [];
$whereParts = [];
$bindValues = [];
$bindTypes = "";
// When a signatory filter is active, only the matching queue should return rows.
$hasOfficeFilter = $officeFilter > 0 && (in_array($officeFilter, $officeIds, true) || in_array($officeFilter, $directOfficeIds, true));
if (is_dean_user() && $officeIds) {
    if (!$hasOfficeFilter || in_array($officeFilter, $officeIds, true)) {
        $placeholders = implode(",", array_fill(0, count($officeIds), "?"));
        $deptCond = "ct.office_id IN (" . $placeholders . ") AND ct.club_id IS NULL AND s.course = ?";
        if ($hasOfficeFilter) {
            $deptCond .= " AND ct.office_id = ?";
        }
        $whereParts[] = "(" . $deptCond . ")";
        $bindValues = array_merge($bindValues, $officeIds);
        $bindTypes .= str_repeat("i", count($officeIds));
        $bindValues[] = $deanCourse;
        $bindTypes .= "s";
        if ($hasOfficeFilter) {
            $bindValues[] = $officeFilter;
            $bindTypes .= "i";
        }
    }
}
if ($directOfficeIds) {
    // Directly assigned office staff review their own office for all courses (institutional).
    if (!$hasOfficeFilter || in_array($officeFilter, $directOfficeIds, true)) {
        $placeholders = implode(",", array_fill(0, count($directOfficeIds), "?"));
        $directCond = "ct.office_id IN (" . $placeholders . ") AND ct.club_id IS NULL";
        if ($hasOfficeFilter) {
            $directCond .= " AND ct.office_id = ?";
        }
        $whereParts[] = "(" . $directCond . ")";
        $bindValues = array_merge($bindValues, $directOfficeIds);
        $bindTypes .= str_repeat("i", count($directOfficeIds));
        if ($hasOfficeFilter) {
            $bindValues[] = $officeFilter;
            $bindTypes .= "i";
        }
    }
}
if ($clubIds) {
    $placeholders = implode(",", array_fill(0, count($clubIds), "?"));
    $clubCond = "ct.club_id IN (" . $placeholders . ")";
    $clubBind = $clubIds;
    $clubTypes = str_repeat("i", count($clubIds));
    if ($clubFilter > 0 && in_array($clubFilter, $clubIds, true)) {
        $clubCond .= " AND ct.club_id = ?";
        $clubBind[] = $clubFilter;
        $clubTypes .= "i";
    }
    $whereParts[] = "(" . $clubCond . ")";
    $bindValues = array_merge($bindValues, $clubBind);
    $bindTypes .= $clubTypes;
}
if ($isDsaUser) {
    // DSA reviews SSC transactions for all courses (institutional, no course filter).
    $whereParts[] = "(ct.office_id = ? AND ct.club_id IS NULL)";
    $bindValues[] = $sscOfficeId;
    $bindTypes .= "i";
}
if ($whereParts) {
    $sql = "SELECT ct.clearance_id, ct.office_id, ct.club_id,
                CASE WHEN ct.club_id IS NOT NULL THEN CONCAT('Institutional Club - ', c.club_name) ELSE o.office_name END AS office_name,
                cr.school_year, cr.semester, s.student_number,
                CONCAT(s.first_name, ' ', s.last_name) AS student_name, u.avatar_path, s.course, cc.course_code, ct.status, ct.remarks, ct.reviewed_at,
                cr.overall_status, cr.requested_at
            FROM clearance_transactions ct
            INNER JOIN clearance_requests cr ON cr.clearance_id = ct.clearance_id
            INNER JOIN students s ON s.student_id = cr.student_id
            INNER JOIN users u ON u.user_id = s.user_id
            INNER JOIN offices o ON o.office_id = ct.office_id
            LEFT JOIN institutional_clubs c ON c.club_id = ct.club_id
            LEFT JOIN courses cc ON cc.course_name = s.course
                WHERE (" . implode(" OR ", $whereParts) . ") AND cr.is_archived=0
            ORDER BY cr.requested_at DESC, s.student_number ASC";

    $stmt = $conn->prepare($sql);
    if ($bindTypes !== "" && $bindValues) $stmt->bind_param($bindTypes, ...$bindValues);
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

// Group by request: one row per student (bulk decision per request).
$groupedReviews = [];
foreach ($reviewRows as $row) {
    $cid = (int)($row["clearance_id"] ?? 0);
    if ($cid <= 0) continue;
    if (!isset($groupedReviews[$cid])) {
        $groupedReviews[$cid] = [
            "clearance_id" => $cid,
            "student_name" => (string)($row["student_name"] ?? ""),
            "student_number" => (string)($row["student_number"] ?? ""),
            "course_code" => (string)($row["course_code"] ?? ""),
            "school_year" => (string)($row["school_year"] ?? ""),
            "semester" => (string)($row["semester"] ?? ""),
            "overall_status" => (string)($row["overall_status"] ?? "pending"),
            "avatar_path" => (string)($row["avatar_path"] ?? ""),
            "office_name" => (string)($row["office_name"] ?? ""),
            "steps" => 0,
            "approved" => 0,
            "reviewed_at" => null,
        ];
    }
    $groupedReviews[$cid]["steps"]++;
    if ((string)($row["status"] ?? "pending") === "approved") $groupedReviews[$cid]["approved"]++;
    if (!empty($row["reviewed_at"]) && ($groupedReviews[$cid]["reviewed_at"] === null || $row["reviewed_at"] > $groupedReviews[$cid]["reviewed_at"])) {
        $groupedReviews[$cid]["reviewed_at"] = $row["reviewed_at"];
    }
}

// Requirement items (clearance_items) the reviewer may update.
// Scoped to the offices this reviewer owns for each request, so an office can
// only touch its own requirements - never another office's.
$authorizedOfficeMap = [];
foreach ($reviewRows as $row) {
    $cid = (int)($row["clearance_id"] ?? 0);
    $oid = (int)($row["office_id"] ?? 0);
    if ($cid > 0 && $oid > 0) $authorizedOfficeMap[$cid][$oid] = true;
}

$requestItems = [];
if ($authorizedOfficeMap) {
    $queueClearanceIds = array_keys($authorizedOfficeMap);
    $clearancePlaceholders = implode(",", array_fill(0, count($queueClearanceIds), "?"));
    $itemListStmt = $conn->prepare(
        "SELECT ci.item_id, ci.clearance_id, ci.office_id, ci.item_name, ci.notes, ci.is_required, ci.is_completed, o.office_name
         FROM clearance_items ci
         LEFT JOIN offices o ON o.office_id = ci.office_id
         WHERE ci.clearance_id IN (" . $clearancePlaceholders . ")
         ORDER BY ci.clearance_id, o.office_name, ci.item_name"
    );
    if ($itemListStmt) {
        $itemListStmt->bind_param(str_repeat("i", count($queueClearanceIds)), ...$queueClearanceIds);
        $itemListStmt->execute();
        $itemListRes = $itemListStmt->get_result();
        while ($item = $itemListRes->fetch_assoc()) {
            $cid = (int)($item["clearance_id"] ?? 0);
            $oid = (int)($item["office_id"] ?? 0);
            if (!isset($authorizedOfficeMap[$cid][$oid])) continue;
            $requestItems[$cid][] = $item;
        }
        $itemListStmt->close();
    }
}

// Every office step of every request in the queue (not only the reviewer's own
// offices), so the row can explain the request-level status.
$requestSteps = [];
if ($groupedReviews) {
    $queueClearanceIds = array_map("intval", array_keys($groupedReviews));
    $clearancePlaceholders = implode(",", array_fill(0, count($queueClearanceIds), "?"));
    $stepsStmt = $conn->prepare(
        "SELECT ct.clearance_id, ct.status,
                CASE WHEN ct.club_id IS NOT NULL THEN CONCAT('Institutional Club - ', COALESCE(c.club_name, 'Club')) ELSE o.office_name END AS office_name
         FROM clearance_transactions ct
         LEFT JOIN offices o ON o.office_id = ct.office_id
         LEFT JOIN institutional_clubs c ON c.club_id = ct.club_id
         WHERE ct.clearance_id IN (" . $clearancePlaceholders . ")
         ORDER BY ct.clearance_id, office_name"
    );
    if ($stepsStmt) {
        $stepsStmt->bind_param(str_repeat("i", count($queueClearanceIds)), ...$queueClearanceIds);
        $stepsStmt->execute();
        $stepsRes = $stepsStmt->get_result();
        while ($step = $stepsRes->fetch_assoc()) {
            $cid = (int)($step["clearance_id"] ?? 0);
            $stepStatus = (string)($step["status"] ?? "pending");
            if (!isset($requestSteps[$cid])) {
                $requestSteps[$cid] = ["total" => 0, "approved" => 0, "rejected" => 0, "pending" => 0, "offices" => []];
            }
            $requestSteps[$cid]["total"]++;
            if ($stepStatus === "approved") $requestSteps[$cid]["approved"]++;
            elseif ($stepStatus === "rejected") $requestSteps[$cid]["rejected"]++;
            else $requestSteps[$cid]["pending"]++;
            $requestSteps[$cid]["offices"][] = (string)($step["office_name"] ?: "Office") . " — " . $stepStatus;
        }
        $stepsStmt->close();
    }
}

require "_header.php";
?>
<div class="page-heading">
    <div>
        <h1>Clearance Reviews</h1>
        <p>Review student requests assigned to your office and update each requirement.</p>
    </div>
</div>

<?php if ($message !== ""): ?><div class="notice notice-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ""): ?><div class="notice notice-error"><?= e($error) ?></div><?php endif; ?>

<?php if (!$officeIds && !$clubIds && !$isDsaUser && !$directOfficeIds): ?>
    <div class="empty-panel">
        <i data-lucide="shield-alert"></i>
        <h3>No office assignment</h3>
        <p>Your office account is not assigned to any office yet. Please contact the administrator.</p>
    </div>
<?php elseif (!$reviewRows): ?>
    <div class="empty-panel">
        <i data-lucide="file-check-2"></i>
        <h3>No clearance requests yet</h3>
        <p>Students will appear here once they submit a clearance request for your office.</p>
    </div>
<?php else: ?>
    <div class="toolbar-card filter-row review-toolbar">
        <div class="search-field review-search-field">
            <i data-lucide="search"></i>
            <input type="search" placeholder="Search students..." aria-label="Search clearance reviews">
        </div>
        <form method="get" class="course-filter-form" style="display:flex;gap:10px;flex-wrap:wrap;">
            <?php if ($officeOptions): ?>
            <label class="review-filter-control course-filter-label">
                <span>Signatory</span>
                <span class="review-select-wrap">
                    <select name="office_filter" onchange="this.form.submit()">
                        <option value="">All Signatories</option>
                        <?php foreach ($officeOptions as $officeOption): ?>
                            <option value="<?= (int)$officeOption["office_id"] ?>" <?= $officeFilter === (int)$officeOption["office_id"] ? "selected" : "" ?>><?= e($officeOption["office_name"] . " - " . ($officeOption["assignatory_name"] ?: "Unnamed signatory") . ($officeOption["position"] ? " (" . $officeOption["position"] . ")" : "")) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <i data-lucide="chevron-down" aria-hidden="true"></i>
                </span>
            </label>
            <?php endif; ?>
            <?php if ($clubOptions): ?>
            <label class="review-filter-control course-filter-label">
                <span>Club</span>
                <span class="review-select-wrap">
                    <select name="club_filter" onchange="this.form.submit()">
                        <option value="">All Clubs</option>
                        <?php foreach ($clubOptions as $clubOption): ?>
                            <option value="<?= (int)$clubOption["club_id"] ?>" <?= $clubFilter === (int)$clubOption["club_id"] ? "selected" : "" ?>><?= e($clubOption["club_name"]) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <i data-lucide="chevron-down" aria-hidden="true"></i>
                </span>
            </label>
            <?php endif; ?>
        </form>
        <label class="review-filter-control review-status-filter">
            <span>Status</span>
            <span class="review-select-wrap">
                <select data-filter="status" aria-label="Filter reviews by status">
                    <option value="all_statuses">All Statuses</option>
                    <option value="approved">Cleared</option>
                    <option value="pending">Pending</option>
                    <option value="rejected">Rejected</option>
                </select>
                <i data-lucide="chevron-down" aria-hidden="true"></i>
            </span>
        </label>
    </div>

    <div class="table-card review-table-card" id="reviews">
        <div class="table-wrapper">
            <table class="review-table">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Office</th>
                        <th>School Year</th>
                        <th>Semester</th>
                        <th>Overall status</th>
                        <th>Checklist</th>
                        <th>Updated</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($groupedReviews as $row): ?>
                        <?php
                        $ovStatus = (string)($row["overall_status"] ?? "pending");
                        $rowCls = $ovStatus === "cleared" ? "st-approved" : ($ovStatus === "not_cleared" ? "st-rejected" : "st-pending");
                        $stCls = $ovStatus === "cleared" ? "approved" : ($ovStatus === "not_cleared" ? "rejected" : "pending");
                        $stLabel = $ovStatus === "cleared" ? "Cleared" : ($ovStatus === "not_cleared" ? "Not cleared" : "Pending");
                        $defSt = ((int)$row["steps"] > 0 && (int)$row["approved"] === (int)$row["steps"]) ? "approved" : "pending";
                        ?>
                        <tr class="<?= $rowCls ?>">
                            <td>
                                <div class="student-review-cell">
                                    <div class="student-review-avatar">
                                        <?php if (!empty($row["avatar_path"])): ?>
                                            <img src="../<?= e($row["avatar_path"]) ?>" alt="<?= e($row["student_name"]) ?> profile picture">
                                        <?php else: ?>
                                            <span class="student-review-initials" aria-hidden="true"><?= e(initials_of($row["student_name"] ?? "")) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <strong><?= e($row["student_name"]) ?></strong>
                                        <small><?= e($row["student_number"] . (!empty($row["course_code"]) ? " - " . $row["course_code"] : "")) ?></small>
                                    </div>
                                </div>
                            </td>
                            <td><?php if ((int)$row["steps"] <= 1): ?><span class="office-pill"><?= e($row["office_name"]) ?></span><?php else: ?><span class="office-pill"><?= (int)$row["steps"] ?> offices</span><small><?= (int)$row["approved"] ?> of <?= (int)$row["steps"] ?> approved</small><?php endif; ?></td>
                            <td><?= e($row["school_year"]) ?></td>
                            <td><?= e($row["semester"]) ?></td>
                            <td class="review-status-cell">
                                <span class="status status-<?= e($stCls) ?>"><?= e($stLabel) ?></span>
                                <?php $stepStats = $requestSteps[(int)$row["clearance_id"]] ?? ["total" => 0, "approved" => 0, "rejected" => 0, "pending" => 0, "offices" => []]; ?>
                                <?php if ((int)$stepStats["total"] > 0): ?>
                                    <small class="review-status-breakdown" title="<?= e(implode("\n", $stepStats["offices"])) ?>">
                                        <?= (int)$stepStats["approved"] ?> of <?= (int)$stepStats["total"] ?> offices approved
                                    </small>
                                    <?php if ((int)$stepStats["rejected"] > 0): ?>
                                        <small class="review-status-detail is-rejected"><?= (int)$stepStats["rejected"] ?> office rejected — action needed</small>
                                    <?php elseif ((int)$stepStats["pending"] > 0): ?>
                                        <small class="review-status-detail"><?= (int)$stepStats["pending"] ?> still waiting on other offices</small>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="review-requirements-cell">
                                <?php $rowItems = $requestItems[(int)$row["clearance_id"]] ?? []; ?>
                                <?php if (!$rowItems): ?>
                                    <span class="muted" style="font-size:12px;">No requirements published</span>
                                <?php else: ?>
                                    <?php
                                    $itemTotal = count($rowItems);
                                    $itemDone = 0;
                                    foreach ($rowItems as $rowItem) if (!empty($rowItem["is_completed"])) $itemDone++;
                                    ?>
                                    <details class="review-requirements">
                                        <summary>
                                            <i data-lucide="list-checks"></i>
                                            Requirement checklist
                                            <span class="req-counter"><?= (int)$itemDone ?>/<?= (int)$itemTotal ?> complied</span>
                                        </summary>
                                        <form method="post" class="review-requirements-form">
                                            <input type="hidden" name="action" value="update_requirements">
                                            <input type="hidden" name="clearance_id" value="<?= (int)$row["clearance_id"] ?>">
                                            <?php foreach ($rowItems as $rowItem): ?>
                                                <label class="review-requirement-item">
                                                    <input type="checkbox" name="item_completed[<?= (int)$rowItem["item_id"] ?>]" value="1" <?= !empty($rowItem["is_completed"]) ? "checked" : "" ?>>
                                                    <span class="review-requirement-text">
                                                        <strong><?= e($rowItem["item_name"]) ?></strong>
                                                        <small>
                                                            <?= e($rowItem["office_name"] ?: "Office") ?> &middot;
                                                            <?= !empty($rowItem["is_required"]) ? "Required" : "Optional" ?>
                                                            <?= !empty($rowItem["notes"]) ? " &middot; " . e($rowItem["notes"]) : "" ?>
                                                        </small>
                                                    </span>
                                                </label>
                                            <?php endforeach; ?>
                                            <button class="btn btn-primary review-requirements-save" type="submit"><i data-lucide="save"></i>Save checklist</button>
                                            <small class="review-requirements-hint">Uncheck a complied requirement to send it back for action.</small>
                                        </form>
                                    </details>
                                <?php endif; ?>
                            </td>
                            <td><?= e($formatReviewedAt($row["reviewed_at"] ?? "")) ?></td>
                            <td>
                                <form method="post" class="review-action-form">
                                    <input type="hidden" name="action" value="bulk_update">
                                    <input type="hidden" name="clearance_id" value="<?= (int)$row["clearance_id"] ?>">
                                    <div class="review-controls">
                                        <select name="status" aria-label="Review status">
                                            <option value="pending" <?= $defSt === "pending" ? "selected" : "" ?>>Pending</option>
                                            <option value="approved" <?= $defSt === "approved" ? "selected" : "" ?>>Approved</option>
                                            <option value="rejected" <?= $defSt === "rejected" ? "selected" : "" ?>>Rejected</option>
                                        </select>
                                        <textarea name="remarks" rows="2" placeholder="Add approval remarks..."></textarea>
                                        <button class="btn btn-primary" type="submit"><i data-lucide="save"></i>Save changes</button>
                                        <?php if ((int)$row["steps"] > 1): ?><small style="color:var(--muted)">Applies to <?= (int)$row["steps"] ?> office steps</small><?php endif; ?>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require "_footer.php"; ?>
