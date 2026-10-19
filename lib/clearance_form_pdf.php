<?php
/**
 * PDF renderer for the student clearance form.
 *
 * The form used to be printed with window.print(), which opens the browser
 * print preview (chrome://print). That preview crashes / logs
 * "ReferenceError: Mojo is not defined" on some Chromium builds (Brave),
 * and it also stamps the browser date + page title on every sheet.
 * Generating the form server-side avoids the print preview completely and
 * produces a clean, one-page official document.
 *
 * Requires lib/fpdf.php (FPDF, MIT license - see lib/README.md).
 */
require_once __DIR__ . "/fpdf.php";

/**
 * Convert UTF-8 text to Windows-1252, the encoding used by the PDF core fonts.
 */
function clearance_pdf_text($text): string
{
    $text = (string)$text;
    if ($text === "") return "";
    if (function_exists("mb_convert_encoding")) {
        $converted = @mb_convert_encoding($text, "Windows-1252", "UTF-8");
        if (is_string($converted)) return $converted;
    }
    if (function_exists("iconv")) {
        $converted = @iconv("UTF-8", "Windows-1252//TRANSLIT", $text);
        if (is_string($converted)) return $converted;
    }
    return preg_replace('/[^\x20-\x7E]/', "?", $text);
}

class ClearanceFormPDF extends FPDF
{
    /** Column widths (mm): Office | In Charge | Signature. */
    public $colWidths = [70, 64, 46];
    /** Header/footer text. */
    public $formMeta = [];
    public $formFooter = "";
    public $formTitle = "";
    public $formAuthor = "";
    /** Row line height (mm). */
    public $lineHeight = 5.6;

    /** Fit the office table to the printable width of the page. */
    public function applyColumnWidths(): void
    {
        $total = $this->w - $this->lMargin - $this->rMargin;
        $this->colWidths = [$total * 0.38, $total * 0.35, $total * 0.27];
    }

    public function Header()
    {
        if ($this->page == 1) {
            $this->drawSchoolHeader();
            return;
        }
        // Continuation pages repeat the column headings.
        $this->drawColumnHead($this->tMargin);
        $this->SetY($this->tMargin + 10);
    }

    public function Footer()
    {
        $this->SetY(-13);
        $this->SetFont("Helvetica", "I", 7.5);
        $this->SetTextColor(120, 120, 120);
        $this->SetX($this->lMargin);
        $this->Cell($this->w - $this->lMargin - $this->rMargin, 5, clearance_pdf_text($this->formFooter), 0, 0, "L");
        $this->SetX($this->w - $this->rMargin - 45);
        $this->Cell(45, 5, "Page " . $this->page . " of {nb}", 0, 0, "R");
    }

    /** Logo + school name + form title block (first page only). */
    protected function drawSchoolHeader()
    {
        $top = $this->tMargin;
        $logo = dirname(__DIR__) . "/assets/images/ckcm_print_logo.png";
        if (!is_file($logo)) $logo = dirname(__DIR__) . "/assets/images/ckcm transparent.png";
        $logoWidth = 23;
        $textX = $this->lMargin;
        $textWidth = $this->w - $this->lMargin - $this->rMargin;
        if (is_file($logo)) {
            $this->Image($logo, $this->lMargin, $top, $logoWidth, 0, "PNG");
            $textX = $this->lMargin + $logoWidth + 6;
            $textWidth = $this->w - $this->rMargin - $textX;
        }
        $this->SetFont("Helvetica", "B", 12.5);
        $this->SetTextColor(20, 20, 20);
        $this->SetXY($textX, $top + 2);
        $this->Cell($textWidth, 6, clearance_pdf_text($this->formMeta["school_name"] ?? ""), 0, 0, "L");
        $this->SetFont("Helvetica", "", 8.5);
        $this->SetTextColor(60, 60, 60);
        $this->SetXY($textX, $top + 8);
        $this->Cell($textWidth, 4.2, clearance_pdf_text($this->formMeta["school_address"] ?? ""), 0, 0, "L");
        $this->SetXY($textX, $top + 12.2);
        $this->Cell($textWidth, 4.2, clearance_pdf_text($this->formMeta["school_contact"] ?? ""), 0, 0, "L");

        $this->SetX($this->lMargin);
        $this->SetY($top + 28);
        $this->SetFont("Helvetica", "B", 9.5);
        $this->SetTextColor(20, 20, 20);
        $this->Cell(0, 5, strtoupper(clearance_pdf_text($this->formMeta["department"] ?? "")), 0, 1, "C");
        $this->SetFont("Helvetica", "B", 15);
        $this->Cell(0, 7.5, strtoupper(clearance_pdf_text($this->formMeta["form_title"] ?? "")), 0, 1, "C");
        $this->SetFont("Helvetica", "B", 10);
        $this->Cell(0, 5.5, clearance_pdf_text($this->formMeta["term"] ?? ""), 0, 1, "C");
        $this->SetY($this->GetY() + 2);
    }

    /** Student identity block (name / course / year level / student number). */
    public function drawStudentMeta(array $student)
    {
        $name = trim(($student["first_name"] ?? "") . " " . ($student["middle_name"] ?? "") . " " . ($student["last_name"] ?? ""));
        $name = trim(preg_replace('/\s+/', " ", $name));
        $course = trim((string)($student["course"] ?? ""));
        $yearLevel = trim((string)($student["year_level"] ?? ""));
        $number = trim((string)($student["student_number"] ?? ""));

        $this->SetY($this->y + 3);
        $colWidth = ($this->w - $this->lMargin - $this->rMargin) / 2;
        $rows = [
            ["NAME:", $name, "COURSE:", $course],
            ["YEAR LEVEL:", $yearLevel, "STUDENT NO.:", $number],
        ];
        foreach ($rows as $row) {
            $y = $this->y;
            $height = 6.2;
            $this->drawMetaCell($this->lMargin, $y, $colWidth, $height, $row[0], $row[1]);
            $this->drawMetaCell($this->lMargin + $colWidth, $y, $colWidth, $height, $row[2], $row[3]);
            $this->y = $y + $height;
            $this->SetLineWidth(0.2);
            $this->Line($this->lMargin, $this->y, $this->w - $this->rMargin, $this->y);
        }
        $this->SetY($this->y + 1);
    }

    protected function drawMetaCell(float $x, float $y, float $width, float $height, string $label, string $value)
    {
        $this->SetFont("Helvetica", "B", 8);
        $this->SetTextColor(20, 20, 20);
        $this->SetXY($x, $y + 1);
        $this->Cell(22, 4.6, $label, 0, 0, "L");
        $this->SetFont("Helvetica", "", 9.5);
        $this->SetTextColor(25, 25, 25);
        $this->SetXY($x + 22, $y + 1);
        $this->Cell($width - 22, 4.6, clearance_pdf_text($value !== "" ? $value : "-"), 0, 0, "L");
    }

    /** Column headings of the office table. */
    public function drawColumnHead(float $y)
    {
        $total = array_sum($this->colWidths);
        $height = 7.6;
        $this->SetFillColor(236, 236, 236);
        $this->Rect($this->lMargin, $y, $total, $height, "F");
        $this->SetFont("Helvetica", "B", 8);
        $this->SetTextColor(20, 20, 20);
        $labels = ["OFFICE", "IN CHARGE", "SIGNATURE"];
        $aligns = ["L", "L", "C"];
        $x = $this->lMargin;
        foreach ($this->colWidths as $index => $columnWidth) {
            $this->SetXY($x + 2.5, $y + 2);
            $this->Cell($columnWidth - 5, 4, $labels[$index], 0, 0, $aligns[$index]);
            $x += $columnWidth;
        }
        $this->SetLineWidth(0.25);
        $this->Rect($this->lMargin, $y, $total, $height, "D");
        $x = $this->lMargin;
        foreach ($this->colWidths as $columnWidth) {
            $x += $columnWidth;
            if ($x < $this->lMargin + $total - 0.01) $this->Line($x, $y, $x, $y + $height);
        }
        $this->SetY($y + $height);
    }

    /** One office row: office, in charge and the current status. */
    public function drawOfficeRow(string $office, string $inCharge, string $status)
    {
        $total = array_sum($this->colWidths);
        // Measure with the row font so wrapped lines are counted correctly.
        $this->SetFont("Helvetica", "", 9);
        $lines = max(
            $this->countLines($office, $this->colWidths[0] - 6),
            $this->countLines($inCharge, $this->colWidths[1] - 6),
            1
        );
        $height = $lines * $this->lineHeight + 3.4;
        if ($this->GetY() + $height > $this->PageBreakTrigger) {
            $this->AddPage();
        }
        $y = $this->GetY();
        $this->SetFont("Helvetica", "", 9);
        $this->SetTextColor(25, 25, 25);
        $this->SetXY($this->lMargin + 3, $y + 1.6);
        $this->MultiCell($this->colWidths[0] - 6, $this->lineHeight, clearance_pdf_text($office), 0, "L");
        $this->SetXY($this->lMargin + $this->colWidths[0] + 3, $y + 1.6);
        $this->MultiCell($this->colWidths[1] - 6, $this->lineHeight, clearance_pdf_text($inCharge !== "" ? $inCharge : "-"), 0, "L");

        $status = strtolower(trim($status)) ?: "pending";
        $colors = [
            "approved" => [22, 101, 42],
            "rejected" => [185, 28, 28],
            "pending" => [146, 88, 0],
        ];
        $color = $colors[$status] ?? $colors["pending"];
        $this->SetFont("Helvetica", "B", 8);
        $this->SetTextColor($color[0], $color[1], $color[2]);
        $this->SetXY($this->lMargin + $this->colWidths[0] + $this->colWidths[1], $y + 1.6);
        $this->MultiCell($this->colWidths[2], $this->lineHeight, strtoupper(clearance_pdf_text($status)), 0, "C");

        $this->SetY($y);
        $this->SetLineWidth(0.25);
        $this->Rect($this->lMargin, $y, $total, $height, "D");
        $x = $this->lMargin;
        foreach ($this->colWidths as $columnWidth) {
            $x += $columnWidth;
            if ($x < $this->lMargin + $total - 0.01) $this->Line($x, $y, $x, $y + $height);
        }
        // Signature line: the office signs the printed copy below the status.
        $this->SetLineWidth(0.15);
        $this->SetDrawColor(150, 150, 150);
        $signatureX = $this->lMargin + $this->colWidths[0] + $this->colWidths[1];
        $this->Line($signatureX + 5, $y + $height - 2.6, $this->lMargin + $total - 4, $y + $height - 2.6);
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.25);
        $this->SetY($y + $height);
    }

    /** Number of wrapped lines a text needs in the given cell width (mm). */
    protected function countLines(string $text, float $width): int
    {
        // MultiCell keeps the cell padding on both sides.
        $width = $width - 2 * $this->cMargin;
        $text = trim($text);
        if ($text === "") return 1;
        $words = preg_split('/\s+/', $text) ?: [];
        $lines = 1;
        $current = "";
        foreach ($words as $word) {
            $candidate = $current === "" ? $word : $current . " " . $word;
            if ($current !== "" && $this->GetStringWidth($candidate) > $width) {
                $lines++;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }
        return $lines;
    }
}

/**
 * Build the clearance form PDF.
 *
 * @param array $settings  school header + term (clearance_form_settings)
 * @param array $student   students row
 * @param array|null $request clearance_requests row (term of the printed request)
 * @param array $offices   office rows (clearance_form_data)
 * @param array $statuses  office_id => status
 */
function clearance_form_pdf_build(array $settings, array $student, ?array $request, array $offices, array $statuses): ClearanceFormPDF
{
    $pdf = new ClearanceFormPDF("P", "mm", "A4");
    $pdf->SetMargins(15, 14, 15);
    $pdf->SetAutoPageBreak(true, 16);
    $pdf->AliasNbPages("{nb}");
    $pdf->applyColumnWidths();
    $pdf->SetDisplayMode("default");
    $pdf->SetCompression(true);

    $term = clearance_form_term($settings, $request);
    $settings["term"] = $term;
    $pdf->formMeta = $settings;

    $fullName = trim(preg_replace('/\s+/', " ", trim(($student["first_name"] ?? "") . " " . ($student["middle_name"] ?? "") . " " . ($student["last_name"] ?? ""))));
    $pdf->formTitle = trim(($settings["form_title"] ?? "STUDENT CLEARANCE FORM") . " - " . $fullName);
    $pdf->formAuthor = trim(($settings["school_name"] ?? "") . " - Student Clearance System");
    $pdf->SetTitle($pdf->formTitle);
    $pdf->SetAuthor($pdf->formAuthor);
    $pdf->SetSubject(trim($term . " - " . $fullName));

    $footer = $term !== "" ? $term . "  |  " : "";
    $footer .= "Request #" . (int)($request["clearance_id"] ?? 0) . "  |  Printed " . date("F j, Y g:i A");
    $pdf->formFooter = $footer;

    $pdf->AddPage();
    $pdf->drawStudentMeta($student);
    $pdf->SetY($pdf->GetY() + 3);
    $pdf->drawColumnHead($pdf->GetY());

    foreach ($offices as $office) {
        $status = $statuses[(int)$office["office_id"]] ?? "pending";
        $pdf->drawOfficeRow(
            (string)$office["office_name"],
            (string)($office["assignatory_name"] ?? ""),
            (string)$status
        );
    }

    if (!$offices) {
        $pdf->SetFont("Helvetica", "I", 9);
        $pdf->SetTextColor(90, 90, 90);
        $pdf->SetX($pdf->lMargin);
        $pdf->Cell(0, 8, "No offices are assigned to this clearance request yet.", 0, 1, "L");
    }

    return $pdf;
}
