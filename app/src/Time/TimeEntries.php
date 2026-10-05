<?php

declare(strict_types=1);

namespace Macrolab\Time;

use DateTimeImmutable;
use Macrolab\Db;

/**
 * Every query against the `time_entries` table.
 */
final class TimeEntries
{
    private const SELECT = 'SELECT t.id, t.user_id, t.project_id, t.worked_on, t.minutes, t.note,
                                   p.name AS project_name, p.code AS project_code,
                                   u.netid AS owner_netid, u.display_name AS owner_name
                              FROM time_entries t
                              JOIN projects p ON p.id = t.project_id
                              JOIN users u ON u.id = t.user_id';

    public static function find(int $id): ?TimeEntry
    {
        $row = Db::get()->one(self::SELECT . ' WHERE t.id = ?', [$id]);

        return $row === null ? null : TimeEntry::fromRow($row);
    }

    /**
     * One person's entries over a date range, newest day first. The timesheet.
     *
     * @return list<TimeEntry>
     */
    public static function forUser(int $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = Db::get()->all(
            self::SELECT . ' WHERE t.user_id = ? AND t.worked_on >= ? AND t.worked_on <= ?
                          ORDER BY t.worked_on, p.name, t.id',
            [$userId, $from->format('Y-m-d'), $to->format('Y-m-d')]
        );

        return array_map([TimeEntry::class, 'fromRow'], $rows);
    }

    /**
     * One person's entries on one day, keyed by project id. The day sheet.
     *
     * The unique key on (user_id, worked_on, project_id) is what makes keying
     * by project safe: there is never a second entry to overwrite.
     *
     * @return array<int, TimeEntry>
     */
    public static function forUserOnDay(int $userId, DateTimeImmutable $day): array
    {
        $rows = Db::get()->all(
            self::SELECT . ' WHERE t.user_id = ? AND t.worked_on = ?',
            [$userId, $day->format('Y-m-d')]
        );

        $entries = [];
        foreach ($rows as $row) {
            $entry = TimeEntry::fromRow($row);
            $entries[$entry->projectId] = $entry;
        }

        return $entries;
    }

    /** The one entry a day-sheet cell stands for, if it has been filled in. */
    public static function findForUserProjectDay(int $userId, int $projectId, DateTimeImmutable $day): ?TimeEntry
    {
        $row = Db::get()->one(
            self::SELECT . ' WHERE t.user_id = ? AND t.project_id = ? AND t.worked_on = ?',
            [$userId, $projectId, $day->format('Y-m-d')]
        );

        return $row === null ? null : TimeEntry::fromRow($row);
    }

    /**
     * How much this person has already logged on one day, for the daily cap.
     * Excludes one entry when that entry is the one being edited.
     */
    public static function minutesForUserOnDay(
        int $userId,
        DateTimeImmutable $day,
        ?int $excludeEntryId = null,
    ): int {
        $sql = 'SELECT COALESCE(SUM(minutes), 0) FROM time_entries
                 WHERE user_id = ? AND worked_on = ?';
        $params = [$userId, $day->format('Y-m-d')];

        if ($excludeEntryId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeEntryId;
        }

        return (int) Db::get()->value($sql, $params);
    }

    /**
     * Everyone's time as filtered on the overview (administrator and lab
     * managers), oldest first. The CSV export lists the same rows in the same
     * order.
     *
     * @return list<TimeEntry>
     */
    public static function search(TimeFilter $filter, int $limit = 1000): array
    {
        [$where, $params] = $filter->toSql();

        $rows = Db::get()->all(
            self::SELECT . $where . ' ORDER BY t.worked_on, u.netid, t.id
                                      LIMIT ' . max(1, min(5000, $limit)),
            $params
        );

        return array_map([TimeEntry::class, 'fromRow'], $rows);
    }

    /**
     * Minutes per person per project over a date range, both ends included,
     * for the time registrations table on the overview page.
     *
     * @return list<array{user_id: int, project_id: int, minutes: int}>
     */
    public static function minutesByUserAndProject(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = Db::get()->all(
            'SELECT t.user_id, t.project_id, SUM(t.minutes) AS minutes
               FROM time_entries t
              WHERE t.worked_on >= ? AND t.worked_on <= ?
              GROUP BY t.user_id, t.project_id',
            [$from->format('Y-m-d'), $to->format('Y-m-d')]
        );

        return array_map(
            static fn (array $row): array => [
                'user_id'    => (int) $row['user_id'],
                'project_id' => (int) $row['project_id'],
                'minutes'    => (int) $row['minutes'],
            ],
            $rows
        );
    }

    /**
     * The entries with a note over a date range, both ends included, oldest
     * first: the remarks shown when hovering over the overview's table.
     *
     * @return list<array{user_id: int, project_id: int, worked_on: string, minutes: int, note: string}>
     */
    public static function notesByUserAndProject(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = Db::get()->all(
            'SELECT t.user_id, t.project_id, t.worked_on, t.minutes, t.note
               FROM time_entries t
              WHERE t.worked_on >= ? AND t.worked_on <= ?
                AND t.note IS NOT NULL AND t.note <> \'\'
              ORDER BY t.worked_on, t.id',
            [$from->format('Y-m-d'), $to->format('Y-m-d')]
        );

        return array_map(
            static fn (array $row): array => [
                'user_id'    => (int) $row['user_id'],
                'project_id' => (int) $row['project_id'],
                'worked_on'  => (string) $row['worked_on'],
                'minutes'    => (int) $row['minutes'],
                'note'       => (string) $row['note'],
            ],
            $rows
        );
    }

    public static function countForUser(int $userId): int
    {
        return (int) Db::get()->value('SELECT COUNT(*) FROM time_entries WHERE user_id = ?', [$userId]);
    }
}
