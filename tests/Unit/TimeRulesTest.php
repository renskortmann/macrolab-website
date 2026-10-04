<?php

declare(strict_types=1);

namespace Macrolab\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Macrolab\Time\TimeRules;
use Macrolab\Time\TimeRuleSet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The time registration rules and the converters underneath them.
 *
 * parseHours() carries most of the risk in the feature: it is where what a
 * person typed becomes what the database stores, and getting it wrong means
 * wrong hours rather than an error anyone would notice.
 */
final class TimeRulesTest extends TestCase
{
    /**
     * @return list<array{0: string, 1: ?int}>
     */
    public static function hourInputs(): array
    {
        return [
            ['3.5', 210],
            // The decimal comma is normal in Dutch and these are TU Delft users.
            ['3,5', 210],
            ['3:30', 210],
            ['3h30', 210],
            ['3h', 180],
            ['8', 480],
            ['0.25', 15],
            ['  2.5  ', 150],
            ['0', 0],
            ['', null],
            ['abc', null],
            ['-2', null],
            // 75 minutes past the hour is not a time.
            ['2:75', null],
            ['1:2:3', null],
        ];
    }

    #[DataProvider('hourInputs')]
    public function testParseHoursUnderstandsWhatPeopleType(string $input, ?int $expected): void
    {
        self::assertSame($expected, TimeRules::parseHours($input));
    }

    public function testThreeAndAHalfHoursIsExactlyTwoHundredAndTenMinutes(): void
    {
        // Rounded once, not floored off a float. 209 would be a silent wrong
        // answer rather than a visible failure.
        self::assertSame(210, TimeRules::parseHours('3.5'));
        self::assertSame('3:30', TimeRules::formatHours(210));
        self::assertSame('3.50', TimeRules::decimalHours(210));
        self::assertSame('3,50', TimeRules::decimalHours(210, ','), 'decimal comma for European spreadsheets');
    }

    public function testFormatHoursPadsTheMinutes(): void
    {
        self::assertSame('0:05', TimeRules::formatHours(5));
        self::assertSame('1:00', TimeRules::formatHours(60));
        self::assertSame('16:00', TimeRules::formatHours(960));
    }

    public function testParseDateRefusesADayThatDoesNotExist(): void
    {
        // createFromFormat on its own rolls this forward to 2 March.
        self::assertNull(TimeRules::parseDate('2025-02-30'));
        self::assertNull(TimeRules::parseDate('not-a-date'));
        self::assertNull(TimeRules::parseDate(''));
    }

    public function testParseDateReadsTheDayFirstFormatTheSiteShows(): void
    {
        self::assertSame('2026-10-08', TimeRules::parseDate('08-10-2026')?->format('Y-m-d'));
        // Not the American reading, which would be 10 August.
        self::assertSame('2026-10-08', TimeRules::parseDate('08/10/2026')?->format('Y-m-d'));
        self::assertNull(TimeRules::parseDate('30-02-2025'));
    }

    public function testParseDateReadsAPlainDayAsUtcMidnight(): void
    {
        $date = TimeRules::parseDate('2026-09-17');

        self::assertNotNull($date);
        self::assertSame('2026-09-17 00:00:00', $date->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $date->getTimezone()->getName());
    }

    public function testAnEntryWithinEveryRuleIsAccepted(): void
    {
        self::assertSame([], $this->validate(minutes: 210));
    }

    public function testADurationOfZeroIsRefused(): void
    {
        self::assertNotSame([], $this->validate(minutes: 0));
    }

    public function testTooShortAnEntryIsRefused(): void
    {
        $errors = $this->validate(minutes: 2, rules: new TimeRuleSet(minMinutes: 5));

        self::assertCount(1, $errors);
        self::assertStringContainsString('shortest', $errors[0]);
    }

    public function testTooLongASingleEntryIsRefused(): void
    {
        $errors = $this->validate(minutes: 800, rules: new TimeRuleSet(maxMinutesPerEntry: 720));

        self::assertNotSame([], $errors);
        self::assertStringContainsString('12 hours', $errors[0]);
    }

    public function testTheDailyCapCountsWhatIsAlreadyLoggedThatDay(): void
    {
        // 14h already logged, another 3h asked for, against a 16h daily cap.
        $errors = $this->validate(
            minutes: 180,
            minutesAlreadyOnDay: 840,
            rules: new TimeRuleSet(maxMinutesPerDay: 960),
        );

        self::assertNotSame([], $errors);
        self::assertStringContainsString('one day', $errors[0]);
    }

    public function testTheDailyCapAllowsSeveralEntriesThatFitTogether(): void
    {
        self::assertSame([], $this->validate(minutes: 120, minutesAlreadyOnDay: 240));
    }

    public function testTimeCannotBeLoggedTooFarAhead(): void
    {
        $errors = $this->validate(
            workedOn: '2026-10-01',
            rules: new TimeRuleSet(maxFutureDays: 7),
        );

        self::assertNotSame([], $errors);
        self::assertStringContainsString('ahead', $errors[0]);
    }

    public function testTimeCanBeLoggedForTodayWhenNoFutureIsAllowed(): void
    {
        self::assertSame(
            [],
            $this->validate(workedOn: '2026-09-17', rules: new TimeRuleSet(maxFutureDays: 0))
        );
    }

    public function testTimeCannotBeLoggedTooFarBack(): void
    {
        $errors = $this->validate(
            workedOn: '2026-01-01',
            rules: new TimeRuleSet(maxBackdateDays: 90),
        );

        self::assertNotSame([], $errors);
        self::assertStringContainsString('days back', $errors[0]);
    }

    /**
     * The day sheet asks this up front to decide whether a day is editable,
     * so it has to agree with validate() at both edges of the window.
     */
    public function testTheLoggingWindowIncludesBothOfItsEdges(): void
    {
        $rules = new TimeRuleSet(maxFutureDays: 7, maxBackdateDays: 90);
        $today = new DateTimeImmutable('2026-09-17', new DateTimeZone('UTC'));
        $open = static fn (string $day): bool => TimeRules::isOpenForLogging(
            $rules,
            new DateTimeImmutable($day, new DateTimeZone('UTC')),
            $today,
        );

        self::assertTrue($open('2026-09-17'));
        self::assertTrue($open('2026-09-24'));   // 7 days ahead
        self::assertFalse($open('2026-09-25'));  // 8 days ahead
        self::assertTrue($open('2026-06-19'));   // 90 days back
        self::assertFalse($open('2026-06-18'));  // 91 days back
    }

    public function testAnOverLongNoteIsRefusedRatherThanTruncated(): void
    {
        $errors = $this->validate(note: str_repeat('x', TimeRules::NOTE_MAX + 1));

        self::assertNotSame([], $errors);
        self::assertStringContainsString('note', $errors[0]);
    }

    public function testANoteAtExactlyTheLimitIsFine(): void
    {
        self::assertSame([], $this->validate(note: str_repeat('x', TimeRules::NOTE_MAX)));
    }

    public function testEveryProblemIsReportedAtOnce(): void
    {
        // Too long for one entry, and far too far in the future.
        $errors = $this->validate(
            workedOn: '2027-01-01',
            minutes: 900,
            rules: new TimeRuleSet(maxMinutesPerEntry: 720, maxFutureDays: 7),
        );

        self::assertGreaterThan(1, count($errors));
    }

    /**
     * @return list<string>
     */
    private function validate(
        string $workedOn = '2026-09-16',
        int $minutes = 120,
        int $minutesAlreadyOnDay = 0,
        ?string $note = null,
        ?TimeRuleSet $rules = null,
    ): array {
        $utc = new DateTimeZone('UTC');

        return TimeRules::validate(
            rules: $rules ?? new TimeRuleSet(),
            workedOn: new DateTimeImmutable($workedOn, $utc),
            minutes: $minutes,
            today: new DateTimeImmutable('2026-09-17', $utc),
            minutesAlreadyOnDay: $minutesAlreadyOnDay,
            note: $note,
        );
    }
}
