<?php

declare(strict_types=1);

namespace Macrolab\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Macrolab\Time\Project;
use Macrolab\Time\TimeMatrix;
use PHPUnit\Framework\TestCase;

/**
 * The time registrations table on the overview page, built from plain data:
 * the period, the order of people, and percentages that add up to 100.
 */
final class TimeMatrixTest extends TestCase
{
    public function testPercentagesAlwaysAddUpToAHundred(): void
    {
        self::assertSame([34, 33, 33], TimeMatrix::percentages([1, 1, 1]));
        self::assertSame([100], TimeMatrix::percentages([90]));
        self::assertSame([67, 0, 33], TimeMatrix::percentages([120, 0, 60]));

        foreach ([[7, 7, 7, 7, 7, 7], [1, 2, 3, 4, 5, 6, 7], [59, 61, 1]] as $minutes) {
            self::assertSame(100, array_sum(TimeMatrix::percentages($minutes)), implode(',', $minutes));
        }
    }

    public function testNoTimeAtAllGivesNoPercentages(): void
    {
        self::assertSame([0, 0], TimeMatrix::percentages([0, 0]));
        self::assertSame([], TimeMatrix::percentages([]));
    }

    public function testAWeekRunsMondayToSundayAcrossMonthAndYearEnds(): void
    {
        [$from, $to] = TimeMatrix::bounds(TimeMatrix::WEEK, self::day('2026-10-08'));
        self::assertSame(['2026-10-05', '2026-10-11'], [$from->format('Y-m-d'), $to->format('Y-m-d')]);

        [$from, $to] = TimeMatrix::bounds(TimeMatrix::WEEK, self::day('2026-10-04')); // a Sunday
        self::assertSame(['2026-09-28', '2026-10-04'], [$from->format('Y-m-d'), $to->format('Y-m-d')]);

        [$from, $to] = TimeMatrix::bounds(TimeMatrix::WEEK, self::day('2027-01-01'));
        self::assertSame(['2026-12-28', '2027-01-03'], [$from->format('Y-m-d'), $to->format('Y-m-d')]);

        [$from, $to] = TimeMatrix::bounds(TimeMatrix::DAY, self::day('2026-10-08'));
        self::assertSame(['2026-10-08', '2026-10-08'], [$from->format('Y-m-d'), $to->format('Y-m-d')]);
    }

    public function testTheLabelCollapsesWhatTheEndsShare(): void
    {
        self::assertSame('5 - 11 Oct 2026', TimeMatrix::rangeLabel(self::day('2026-10-05'), self::day('2026-10-11')));
        self::assertSame('28 Sep - 4 Oct 2026', TimeMatrix::rangeLabel(self::day('2026-09-28'), self::day('2026-10-04')));
        self::assertSame('28 Dec 2026 - 3 Jan 2027', TimeMatrix::rangeLabel(self::day('2026-12-28'), self::day('2027-01-03')));
        self::assertSame('5 Oct 2026', TimeMatrix::rangeLabel(self::day('2026-10-05'), self::day('2026-10-05')));
    }

    public function testPeopleAreOrderedByLastName(): void
    {
        $matrix = self::matrix(
            [
                ['id' => 1, 'netid' => 'rkortmann', 'display_name' => 'Rens Kortmann'],
                ['id' => 2, 'netid' => 'jberg', 'display_name' => 'Jan van der Berg'],
                ['id' => 3, 'netid' => 'apieters', 'display_name' => 'Anna Pieters'],
                ['id' => 4, 'netid' => 'lnoname', 'display_name' => null],
                ['id' => 5, 'netid' => 'quiet', 'display_name' => 'Has Nothing Logged'],
            ],
            [[1, 10, 60], [2, 10, 60], [3, 10, 60], [4, 10, 60]],
        );

        self::assertSame(
            ['Jan van der Berg', 'Rens Kortmann', 'lnoname', 'Anna Pieters'],
            array_column($matrix->people, 'label'),
            'by last word of the name, the netID standing in for a missing name; nobody without time'
        );
    }

    public function testCellsHoldMinutesAndTheirShareOfThePersonsTime(): void
    {
        $matrix = self::matrix(
            [
                ['id' => 1, 'netid' => 'a', 'display_name' => 'Ann A'],
                ['id' => 2, 'netid' => 'b', 'display_name' => 'Bob B'],
            ],
            [[1, 10, 90], [1, 20, 30], [2, 20, 45]],
        );

        // Rows by name: Lab tidying (20), Maintenance (10); Teaching (30) is
        // in use with no time; Retired (40) has none and is left out.
        self::assertSame(['Lab tidying', 'Maintenance', 'Teaching'], array_column($matrix->rows, 'label'));
        self::assertSame([['minutes' => 30, 'percent' => 25], ['minutes' => 45, 'percent' => 100]], $matrix->rows[0]['cells']);
        self::assertSame([['minutes' => 90, 'percent' => 75], null], $matrix->rows[1]['cells']);
        self::assertSame([null, null], $matrix->rows[2]['cells']);
        self::assertSame([120, 45], $matrix->totals);
    }

    public function testARetiredActivityStaysWhileItHasTimeInThePeriod(): void
    {
        $matrix = self::matrix([['id' => 1, 'netid' => 'a', 'display_name' => 'Ann A']], [[1, 40, 60]]);

        self::assertContains('Retired', array_column($matrix->rows, 'label'));
    }

    public function testNobodyLoggedMeansAnEmptyTable(): void
    {
        self::assertTrue(self::matrix([['id' => 1, 'netid' => 'a', 'display_name' => 'Ann A']], [])->isEmpty());
    }

    /**
     * @param list<array<string, mixed>> $users
     * @param list<array{0: int, 1: int, 2: int}> $sums  user id, project id, minutes
     */
    private static function matrix(array $users, array $sums): TimeMatrix
    {
        $projects = [
            new Project(10, 'Maintenance', null, null, true),
            new Project(20, 'Lab tidying', null, null, true),
            new Project(30, 'Teaching', null, null, true),
            new Project(40, 'Retired', null, null, false),
        ];

        return TimeMatrix::build(
            TimeMatrix::WEEK,
            self::day('2026-10-05'),
            self::day('2026-10-11'),
            $projects,
            $users,
            array_map(static fn (array $s): array => ['user_id' => $s[0], 'project_id' => $s[1], 'minutes' => $s[2]], $sums),
        );
    }

    private static function day(string $iso): DateTimeImmutable
    {
        return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    }
}
