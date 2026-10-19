# lib/

Server-side PDF support for the clearance form.

| File | Purpose |
| --- | --- |
| `fpdf.php` | FPDF 1.9 — pure-PHP PDF generator (no external binaries, no Composer install). |
| `font/*.json` | Core font metrics for FPDF (Helvetica, Times, Courier, Symbol, ZapfDingbats). |
| `clearance_form_pdf.php` | `ClearanceFormPDF` class + `clearance_form_pdf_build()` — renders the student clearance form (school header, student block, office table with signature lines, footer). |

## Why the form is generated server-side

Printing with `window.print()` opens the browser print preview (`chrome://print`).
On several Chromium builds (Brave in particular) that preview throws
`ReferenceError: Mojo is not defined` and can fail outright, and it also stamps
the browser date/URL/title on every sheet. `student/clearance_pdf.php` builds the
same form as a PDF on the server, so no print preview is opened and the printed
sheet is clean.

## Requirements

- PHP 7.4+ (tested on PHP 8.2) with `ext-zlib` (used for the PNG alpha channel of the logo).
- `ext-gd` is **not** required: the PNG logo is embedded with the built-in parser, JPEG is read directly.

## Text encoding

The form uses the built-in PDF core fonts (Helvetica), which are WinAnsi encoded, so
`clearance_pdf_text()` converts UTF-8 → Windows-1252 (`Ñ`, `—`, `“ ”` are supported).
Characters outside that range (e.g. CJK) are replaced with `?`. For full Unicode,
add a TTF with `$pdf->AddFont()` (DejaVuSans) and pass the raw UTF-8 text instead.

## Provenance / license

- FPDF by Olivier Plathey — <http://www.fpdf.org> — MIT license.
  Source: <https://github.com/setasign/fpdf> (version 1.9, `fpdf.php` + `font/*.json`).
  Vendored as-is so the project stays dependency-free; update by replacing these files.
