<?php

declare(strict_types=1);

namespace Macrolab\Time;

use DateTimeImmutable;
use Macrolab\Users;

/**
 * The time registrations table on the overview page: activities down, people
 * across, for one week (Monday to Sunday) or one day. Each cell holds the
 * minutes a person logged on an activity and the share of that person's time
 * in the period, so every column adds up to 100%, plus the notes on those
 * entries, which the page shows when the cell is hovered over.
 *
 * Deliberately separate from TimeFilter: the table has its own period and
 * always shows everyone, whatever the filter of the export below it says.
 */
final class TimeMatrix
{
    public const WEEK = 'week';
    public const DAY = 'day';

    private const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                            'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    /**
     * @param list<array{id: int, label: string}>                                        $people  the columns
     * @param list<array{label: string, cells: list<array{minutes: int, percent: int, notes: list<array{day: string, minutes: int, note: string}>}|null>}> $rows
     * @param list<int>                                                                    $totals  minutes per column
     */
    private function __construct(
        public readonly string $period,
        public readonly DateTimeImmutable $from,
        public readonly DateTimeImmutable $to,
        public readonly array $people,
        public readonly array $rows,
        public readonly array $totals,
    ) {
    }

    /**
     * The table for the period named in the address: period=week|day (week
     * when missing or unknown) around date= (today when missing or not a
     * date). Like TimeFilter, a stale bookmark lands somewhere sensible.
     */
    public static function fromQuery(?string $period, ?string $date): self
    {
        $period = $period === self::DAY ? self::DAY : self::WEEK;
        $anchor = TimeRules::parseDate($date ?? '') ?? TimeRules::today();
        [$from, $to] = self::bounds($period, $anchor);

        return self::build(
            $period,
            $from,
            $to,
            Projects::all(),
            Users::listAll(),
            TimeEntries::minutesByUserAndProject($from, $to),
            TimeEntries::notesByUserAndProject($from, $to),
        );
    }

    /**
     * Assemble the table from plain data, without the database.
     *
     * @param list<Project>                                                 $projects
     * @param list<array<string, mixed>>                                    $users  rows with id, netid, display_name
     * @param list<array{user_id: int, project_id: int, minutes: int}>      $sums
     * @param list<array{user_id: int, project_id: int, worked_on: string, minutes: int, note: string}> $notes  oldest first
     */
    public static function build(
        string $period,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        array $projects,
        array $users,
        array $sums,
        array $notes = [],
    ): self {
        /** @var array<int, array<int, int>> $minutes  project id => user id => minutes */
        $minutes = [];
        foreach ($sums as $sum) {
            if ($sum['minutes'] > 0) {
                $minutes[$sum['project_id']][$sum['user_id']] =
                    ($minutes[$sum['project_id']][$sum['user_id']] ?? 0) + $sum['minutes'];
            }
        }

        /** @var array<int, array<int, list<array{day: string, minutes: int, note: string}>>> $remarks */
        $remarks = [];
        foreach ($notes as $note) {
            $remarks[$note['project_id']][$note['user_id']][] = [
                'day'     => (new DateTimeImmutable($note['worked_on']))->format('D j M'),
                'minutes' => $note['minutes'],
                'note'    => $note['note'],
            ];
        }

        // Columns: everyone with time in the period, by last name.
        $people = [];
        foreach ($users as $user) {
            $id = (int) $user['id'];
            $logged = false;
            foreach ($minutes as $byUser) {
                if (isset($byUser[$id])) {
                    $logged = true;
                    break;
                }
            }
            if ($logged) {
                $name = trim((string) ($user['display_name'] ?? ''));
                $people[] = [
                    'id'    => $id,
                    'label' => $name !== '' ? $name : (string) $user['netid'],
                    'key'   => self::sortKey($name, (string) $user['netid']),
                ];
            }
        }
        usort($people, static fn (array $a, array $b): int => $a['key'] <=> $b['key']);
        $people = array_map(static fn (array $p): array => ['id' => $p['id'], 'label' => $p['label']], $people);

        // Rows: every activity in use, plus retired ones with time in the period.
        $projects = array_values(array_filter(
            $projects,
            static fn (Project $p): bool => $p->isActive || isset($minutes[$p->id])
        ));
        usort($projects, static fn (Project $a, Project $b): int => strcasecmp($a->name, $b->name));

        $totals = [];
        $shares = [];
        foreach ($people as $column => $person) {
            $columnMinutes = array_map(
                static fn (Project $p): int => $minutes[$p->id][$person['id']] ?? 0,
                $projects
            );
            $totals[$column] = array_sum($columnMinutes);
            $shares[$column] = self::percentages($columnMinutes);
        }

        $rows = [];
        foreach ($projects as $index => $project) {
            $cells = [];
            foreach ($people as $column => $person) {
                $logged = $minutes[$project->id][$person['id']] ?? 0;
                $cells[] = $logged > 0 ? [
                    'minutes' => $logged,
                    'percent' => $shares[$column][$index],
                    'notes'   => $remarks[$project->id][$person['id']] ?? [],
                ] : null;
            }
            $rows[] = ['label' => $project->label(), 'cells' => $cells];
        }

        return new self($period, $from, $to, $people, $rows, array_values($totals));
    }

    /**
     * First and last day of the period around $date: the day itself, or the
     * Monday to Sunday of its ISO week.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    public static function bounds(string $period, DateTimeImmutable $date): array
    {
        if ($period === self::DAY) {
            return [$date, $date];
        }

        $monday = $date->modify('-' . ((int) $date->format('N') - 1) . ' days');

        return [$monday, $monday->modify('+6 days')];
    }

    /**
     * Whole percentages of the total that add up to exactly 100 (largest
     * remainder): rounding each share on its own can give 99 or 101.
     * All zeros give all zeros.
     *
     * @param list<int> $minutes
     * @return list<int>
     */
    public static function percentages(array $minutes): array
    {
        $total = array_sum($minutes);
        if ($total <= 0) {
            return array_map(static fn (): int => 0, $minutes);
        }

        $floors = [];
        $remainders = [];
        foreach ($minutes as $i => $value) {
            $exact = $value * 100 / $total;
            $floors[$i] = (int) floor($exact);
            $remainders[$i] = $exact - $floors[$i];
        }

        // Hand the points lost to rounding down to the largest remainders;
        // on a tie, the earlier row.
        $order = array_keys($remainders);
        usort($order, static fn (int $a, int $b): int => [$remainders[$b], $a] <=> [$remainders[$a], $b]);
        $missing = 100 - array_sum($floors);
        for ($i = 0; $i < $missing; $i++) {
            $floors[$order[$i]]++;
        }

        return array_values($floors);
    }

    /**
     * How people are ordered: by last name, taken as the last word of the
     * display name, so "Jan van der Berg" sorts under B. Then the full name
     * and the netID, so the order is always the same. No display name: the
     * netID.
     */
    public static function sortKey(string $displayName, string $netid): string
    {
        $displayName = trim($displayName);
        if ($displayName === '') {
            return mb_strtolower($netid) . "\0\0" . $netid;
        }

        $words = preg_split('/\s+/u', $displayName) ?: [$displayName];
        $last = (string) end($words);

        return mb_strtolower($last) . "\0" . mb_strtolower($displayName) . "\0" . $netid;
    }

    /** "5 - 11 Oct 2026", "28 Sep - 4 Oct 2026", or "5 Oct 2026" for a day. */
    public function label(): string
    {
        return self::rangeLabel($this->from, $this->to);
    }

    /** The same wording as the booking calendar's date label (app.js). */
    public static function rangeLabel(DateTimeImmutable $from, DateTimeImmutable $to): string
    {
        $day = static fn (DateTimeImmutable $d): string => $d->format('j');
        $month = static fn (DateTimeImmutable $d): string => self::MONTHS[(int) $d->format('n') - 1];

        if ($from->format('Y') !== $to->format('Y')) {
            return $day($from) . ' ' . $month($from) . ' ' . $from->format('Y')
                . ' - ' . $day($to) . ' ' . $month($to) . ' ' . $to->format('Y');
        }

        if ($from->format('m') !== $to->format('m')) {
            return $day($from) . ' ' . $month($from) . ' - ' . $day($to) . ' ' . $month($to) . ' ' . $to->format('Y');
        }

        if ($from->format('d') !== $to->format('d')) {
            return $day($from) . ' - ' . $day($to) . ' ' . $month($to) . ' ' . $to->format('Y');
        }

        return $day($from) . ' ' . $month($from) . ' ' . $from->format('Y');
    }

    /** The first day of the period before this one. */
    public function previous(): DateTimeImmutable
    {
        return $this->from->modify($this->period === self::DAY ? '-1 day' : '-7 days');
    }

    /** The first day of the period after this one. */
    public function next(): DateTimeImmutable
    {
        return $this->from->modify($this->period === self::DAY ? '+1 day' : '+7 days');
    }

    /** Whether today is in the period shown, so "Today" would change nothing. */
    public function showsToday(): bool
    {
        $today = TimeRules::today()->format('Y-m-d');

        return $today >= $this->from->format('Y-m-d') && $today <= $this->to->format('Y-m-d');
    }

    /** No one logged anything in the period. */
    public function isEmpty(): bool
    {
        return $this->people === [];
    }
}
