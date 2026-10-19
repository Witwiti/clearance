<?php
/**
 * PDF export of the admin clearance report.
 *
 * Replaces the browser print preview (window.print()) that fails with
 * "ReferenceError: Mojo is not defined" on some Chromium builds and prints
 * the browser date/URL on the sheet.
 */
$pageTitle = "Reports";
$activePage = "reports";
require_once "../config/auth.php";
require_role("admin");
require_once "../config/db.php";
require_once "../config/reports.php";
require_once "../config/clearance_form.php";
require_once "../lib/reports_pdf.php";

$filters = reports_request_filters($_GET);
$data = reports_data($conn, $filters);
$settings = clearance_form_settings($conn);

$pdf = reports_pdf_build($settings, $data["summary"], $data["rows"], reports_filter_labels($filters));
$pdf->Output("D", "clearance_report_" . date("Ymd_His") . ".pdf");
exit;
