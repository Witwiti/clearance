<?php
/**
 * Shared helpers for the server-side PDF generators (lib/).
 */

/**
 * Convert UTF-8 text to Windows-1252, the encoding used by the PDF core fonts.
 */
function pdf_text($text): string
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
