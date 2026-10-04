<?php

declare(strict_types=1);

namespace Macrolab\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * date_field(): a day-first text field, submitted under its name, with the
 * browser's date input beside it only as a picker.
 */
final class DateFieldTest extends TestCase
{
    public function testTheVisibleFieldShowsTheDateDayFirst(): void
    {
        $html = date_field('worked_on', '2026-10-08', ['id' => 'worked_on', 'required' => true]);

        self::assertMatchesRegularExpression(
            '#<input type="text" name="worked_on" value="08-10-2026" placeholder="dd-mm-yyyy"[^>]* id="worked_on" required>#',
            $html
        );
    }

    public function testThePickerHasNoNameSoOnlyTheDayFirstValueIsSubmitted(): void
    {
        $html = date_field('from', '2026-10-01');

        self::assertMatchesRegularExpression('#<input type="date" class="date-native"[^>]*value="2026-10-01">#', $html);
        self::assertSame(1, substr_count($html, 'name='), 'only the text field is named');
    }

    public function testAnEmptyFieldStaysEmpty(): void
    {
        self::assertStringContainsString('value=""', date_field('start_date', null));
    }

    public function testAttributesAreEscaped(): void
    {
        self::assertStringContainsString('aria-label="a &quot;b&quot;"', date_field('x', null, ['aria-label' => 'a "b"']));
    }
}
