<?php
/**
 * PDF renderer for the admin clearance report.
 *
 * Same purpose as the clearance form export: the browser print preview
 * (chrome://print) breaks on some Chromium builds and stamps the browser
 * date/URL on the sheet, so the report is rendered server-side instead.
 */
require_once __DIR__ . "/fpdf.php";
require_once __DIR__ . "/pdf_common.php";

class ReportsPDF extends FPDF
{
    /** Column widths (mm): Student | Term | Status | Progress | Requested. */
    public $colWidths = [100, 55, 40, 40, 38];
    public $reportTitle = "Clearance Reports";
    public $reportFooter = "";
    public $reportMeta = [];
    public $lineHeight = 5.4;

    /** Fit the table to the printable width of the page. */
    public function applyColumnWidths(): void
    {
        $total = $this->w - $this->lMargin - $this->rMargin;
        $this->colWidths = [$total * 0.37, $total * 0.20, $total * 0.14, $total * 0.14, $total * 0.15];
    }

    public function Header()
    {
        if ($this->page == 1) {
            $this->drawReportHeader();
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
        $this->Cell($this->w - $this->lMargin - $this->rMargin - 45, 5, pdf_text($this->reportFooter), 0, 0, "L");
        $this->SetX($this->w - $this->rMargin - 45);
        $this->Cell(45, 5, "Page " . $this->page . " of {nb}", 0, 0, "R");
    }

    /** Title block: school, report title, generated on, active filters. */
    protected function drawReportHeader()
    {
        $top = $this->tMargin;
        $this->SetFont("Helvetica", "B", 11);
        $this->SetTextColor(20, 20, 20);
        $this->SetXY($this->lMargin, $top);
        $this->Cell(0, 5.5, pdf_text(strtoupper($this->reportMeta["school_name"] ?? "")), 0, 1, "L");
        $this->SetFont("Helvetica", "B", 15);
        $this->Cell(0, 8, pdf_text(strtoupper($this->reportTitle)), 0, 1, "L");
        $this->SetFont("Helvetica", "", 8.5);
        $this->SetTextColor(70, 70, 70);
        $this->Cell(0, 4.4, pdf_text("Generated on " . date("F j, Y g:i A")), 0, 1, "L");
        if (!empty($this->reportMeta["filters"])) {
            $this->Cell(0, 4.4, pdf_text("Filters: " . implode(" · ", $this->reportMeta["filters"])), 0, 1, "L");
        }
        $this->SetY($this->GetY() + 2);
    }

    /** Summary strip: total / pending / cleared / not cleared. */
    public function drawSummary(array $summary)
    {
        $labels = [
            "Total requests" => (int)($summary["total"] ?? 0),
            "Pending" => (int)($summary["pending"] ?? 0),
            "Cleared" => (int)($summary["cleared"] ?? 0),
            "Not cleared" => (int)($summary["not_cleared"] ?? 0),
        ];
        $total = array_sum($this->colWidths);
        $boxWidth = $total / 4;
        $y = $this->GetY();
        $height = 14;
        $this->SetFillColor(245, 245, 245);
        $this->Rect($this->lMargin, $y, $total, $height, "F");
        $x = $this->lMargin;
        foreach ($labels as $label => $value) {
            $this->SetFont("Helvetica", "", 7.5);
            $this->SetTextColor(110, 110, 110);
            $this->SetXY($x + 3, $y + 2.6);
            $this->Cell($boxWidth - 6, 4, strtoupper($label), 0, 0, "L");
            $this->SetFont("Helvetica", "B", 12);
            $this->SetTextColor(20, 20, 20);
            $this->SetXY($x + 3, $y + 6.8);
            $this->Cell($boxWidth - 6, 6, (string)$value, 0, 0, "L");
            $x += $boxWidth;
        }
        $this->SetLineWidth(0.2);
        $this->SetDrawColor(215, 215, 215);
        $this->Rect($this->lMargin, $y, $total, $height, "D");
        $this->SetDrawColor(0, 0, 0);
        $this->SetY($y + $height + 4);
    }

    /** Column headings of the report table. */
    public function drawColumnHead(float $y)
    {
        $total = array_sum($this->colWidths);
        $height = 7.6;
        $this->SetFillColor(236, 236, 236);
        $this->Rect($this->lMargin, $y, $total, $height, "F");
        $this->SetFont("Helvetica", "B", 8);
        $this->SetTextColor(20, 20, 20);
        $labels = ["STUDENT", "TERM", "STATUS", "PROGRESS", "REQUESTED"];
        $aligns = ["L", "L", "L", "L", "L"];
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

    /** One report row. */
    public function drawReportRow(array $row)
    {
        $total = array_sum($this->colWidths);
        $student = trim((string)($row["student_name"] ?? ""));
        $meta = trim((string)($row["student_number"] ?? "")) . " · " . trim((string)($row["course"] ?? "No course"));
        $term = trim((string)($row["school_year"] ?? "")) . " · " . trim((string)($row["semester"] ?? ""));
        $status = ucwords(str_replace("_", " ", trim((string)($row["overall_status"] ?? ""))));
        $progress = (int)($row["offices_approved"] ?? 0) . "/" . (int)($row["offices_total"] ?? 0) . " offices";
        $requested = trim((string)($row["requested_at"] ?? "")) !== "" ? date("M d, Y", strtotime($row["requested_at"])) : "—";

        $this->SetFont("Helvetica", "", 9);
        $nameLines = $this->countLines($student, $this->colWidths[0] - 6);
        $metaLines = $this->countLines($meta, $this->colWidths[0] - 6);
        $lines = max(
            $nameLines + $metaLines,
            $this->countLines($term, $this->colWidths[1] - 6),
            1
        );
        $height = $lines * $this->lineHeight + 4.4;
        if ($this->GetY() + $height > $this->PageBreakTrigger) {
            $this->AddPage();
        }
        $y = $this->GetY();
        $x = $this->lMargin;

        // Student (name + number / course on the second line).
        $this->SetTextColor(25, 25, 25);
        $this->SetFont("Helvetica", "B", 9);
        $this->SetXY($x + 3, $y + 1.6);
        $this->MultiCell($this->colWidths[0] - 6, $this->lineHeight, pdf_text($student), 0, "L");
        $this->SetFont("Helvetica", "", 7.5);
        $this->SetTextColor(110, 110, 110);
        $this->SetXY($x + 3, $y + 1.6 + $nameLines * $this->lineHeight);
        $this->MultiCell($this->colWidths[0] - 6, $this->lineHeight, pdf_text($meta), 0, "L");

        // Term.
        $x += $this->colWidths[0];
        $this->SetFont("Helvetica", "", 9);
        $this->SetTextColor(25, 25, 25);
        $this->SetXY($x + 3, $y + 1.6);
        $this->MultiCell($this->colWidths[1] - 6, $this->lineHeight, pdf_text($term), 0, "L");

        // Status.
        $x += $this->colWidths[1];
        $statusKey = strtolower(trim((string)($row["overall_status"] ?? "")));
        $colors = [
            "cleared" => [22, 101, 42],
            "not_cleared" => [185, 28, 28],
            "pending" => [146, 88, 0],
        ];
        $color = $colors[$statusKey] ?? $colors["pending"];
        $this->SetFont("Helvetica", "B", 8.5);
        $this->SetTextColor($color[0], $color[1], $color[2]);
        $this->SetXY($x + 3, $y + 1.6);
        $this->MultiCell($this->colWidths[2] - 6, $this->lineHeight, pdf_text($status), 0, "L");

        // Progress.
        $x += $this->colWidths[2];
        $this->SetFont("Helvetica", "", 9);
        $this->SetTextColor(25, 25, 25);
        $this->SetXY($x + 3, $y + 1.6);
        $this->MultiCell($this->colWidths[3] - 6, $this->lineHeight, pdf_text($progress), 0, "L");

        // Requested.
        $x += $this->colWidths[3];
        $this->SetXY($x + 3, $y + 1.6);
        $this->MultiCell($this->colWidths[4] - 6, $this->lineHeight, pdf_text($requested), 0, "L");

        $this->SetY($y);
        $this->SetLineWidth(0.25);
        $this->Rect($this->lMargin, $y, $total, $height, "D");
        $x = $this->lMargin;
        foreach ($this->colWidths as $columnWidth) {
            $x += $columnWidth;
            if ($x < $this->lMargin + $total - 0.01) $this->Line($x, $y, $x, $y + $height);
        }
        $this->SetY($y + $height);
    }

    /** Number of wrapped lines a text needs in the given cell width (mm). */
    protected function countLines(string $text, float $width): int
    {
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
 * Build the clearance report PDF.
 *
 * @param array $settings school header configuration
 * @param array $summary  summary counts
 * @param array $rows     detail rows
 * @param array $filters  active filter labels
 */
function reports_pdf_build(array $settings, array $summary, array $rows, array $filters): ReportsPDF
{
    $pdf = new ReportsPDF("L", "mm", "A4");
    $pdf->SetMargins(12, 12, 12);
    $pdf->SetAutoPageBreak(true, 16);
    $pdf->AliasNbPages("{nb}");
    $pdf->applyColumnWidths();
    $pdf->SetTitle("Clearance Reports");
    $pdf->SetAuthor(trim(($settings["school_name"] ?? "") . " - Student Clearance System"));
    $pdf->SetSubject("Clearance report generated on " . date("F j, Y"));

    $pdf->reportTitle = "Clearance Reports";
    $pdf->reportMeta = [
        "school_name" => (string)($settings["school_name"] ?? ""),
        "filters" => $filters,
    ];
    $pdf->reportFooter = "Generated " . date("F j, Y g:i A") . " · " . count($rows) . " record(s)";

    $pdf->AddPage();
    $pdf->drawSummary($summary);
    $pdf->drawColumnHead($pdf->GetY());
    foreach ($rows as $row) {
        $pdf->drawReportRow($row);
    }
    if (!$rows) {
        $pdf->SetFont("Helvetica", "I", 9);
        $pdf->SetTextColor(90, 90, 90);
        $pdf->SetX($pdf->lMargin);
        $pdf->Cell(0, 8, "No records match these filters.", 0, 1, "L");
    }

    return $pdf;
}
