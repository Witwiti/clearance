<?php
/**
 * PDF export of the student clearance form.
 *
 * Replaces the browser print preview (window.print()) that triggered the
 * "ReferenceError: Mojo is not defined" crash in Chromium/Brave.
 * Only the signed-in student can download their own form.
 */
$activePage = "clearance";
require_once "../config/db.php";
require_once "../config/auth.php";
require_role("student");
require_once "../config/clearance_form.php";
require_once "../lib/clearance_form_pdf.php";

$studentStmt = $conn->prepare("SELECT * FROM students WHERE user_id = ? LIMIT 1");
$studentStmt->bind_param("i", $_SESSION["user_id"]);
$studentStmt->execute();
$student = $studentStmt->get_result()->fetch_assoc();
$studentStmt->close();
if (!$student) {
    http_response_code(404);
    die("Student profile not found.");
}

$requests = [];
$stmt = $conn->prepare("SELECT clearance_id, school_year, semester, overall_status, institutional_club_id FROM clearance_requests WHERE student_id = ? ORDER BY requested_at DESC");
$stmt->bind_param("i", $student["student_id"]);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $requests[] = $row;
$stmt->close();

$selectedId = (int)($_GET["request"] ?? ($requests[0]["clearance_id"] ?? 0));
$selectedRequest = null;
foreach ($requests as $request) {
    if ((int)$request["clearance_id"] === $selectedId) $selectedRequest = $request;
}
if (!$selectedRequest) {
    http_response_code(404);
    die("Clearance request not found.");
}

// Institutional club of the printed request (only its moderator appears on the form).
$selectedClub = null;
$selectedClubId = (int)($selectedRequest["institutional_club_id"] ?? 0);
if ($selectedClubId > 0) {
    $cs = $conn->prepare("SELECT c.club_name, u.full_name AS moderator_name FROM institutional_clubs c LEFT JOIN users u ON u.user_id = c.moderator_user_id WHERE c.club_id = ? LIMIT 1");
    $cs->bind_param("i", $selectedClubId);
    $cs->execute();
    $selectedClub = $cs->get_result()->fetch_assoc() ?: null;
    $cs->close();
}

$settings = clearance_form_settings($conn);
$formData = clearance_form_data($conn, $student, $selectedClub, $selectedId);

$pdf = clearance_form_pdf_build($settings, $student, $selectedRequest, $formData["offices"], $formData["statuses"]);
$pdf->Output("D", clearance_form_filename($student, $selectedRequest));
exit;
