<?php

declare(strict_types=1);

namespace Macrolab\Time;

use DateTimeImmutable;
use Macrolab\Http\Request;

/**
 * What the administrator is currently looking at: optionally one person,
 * optionally one project, and a date range.
 *
 * This exists so the entry list and the CSV export filter through one piece
 * of code. Written out twice, that WHERE clause drifts, and the export quietly
 * stops matching the screen it was taken from. (The time registrations table
 * above them has its own period; see TimeMatrix.)
 */
final class TimeFilter
{
    public function __construct(
        public readonly ?int $userId,
        public readonly ?int $projectId,
        public readonly DateTimeImmutable $from,
        public readonly DateTimeImmutable $to,
    ) {
    }

    /**
     * Read the filter off the query string, defaulting to the current month.
     * Anything unparseable falls back rather than failing: this is a view, and
     * a stale bookmark should still land somewhere sensible.
     */
    public static function fromRequest(Request $request): self
    {
        $today = TimeRules::today();

        $from = TimeRules::parseDate($request->query('from', '') ?? '')
            ?? $today->modify('first day of this month');
        $to = TimeRules::parseDate($request->query('to', '') ?? '')
            ?? $today->modify('last day of this month');

        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        $userId = $request->query('user', '') ?? '';
        $projectId = $request->query('project', '') ?? '';

        return new self(
            userId: ctype_digit((string) $userId) ? (int) $userId : null,
            projectId: ctype_digit((string) $projectId) ? (int) $projectId : null,
            from: $from,
            to: $to,
        );
    }

    /**
     * The WHERE clause and its bound values. Placeholders only - no request
     * value is ever interpolated into SQL.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public function toSql(): array
    {
        $sql = ' WHERE t.worked_on >= ? AND t.worked_on <= ?';
        $params = [$this->from->format('Y-m-d'), $this->to->format('Y-m-d')];

        if ($this->userId !== null) {
            $sql .= ' AND t.user_id = ?';
            $params[] = $this->userId;
        }

        if ($this->projectId !== null) {
            $sql .= ' AND t.project_id = ?';
            $params[] = $this->projectId;
        }

        return [$sql, $params];
    }

    /** The filter as a query string, for paging and for the export link. */
    public function queryString(): string
    {
        $parts = [
            'from' => $this->from->format('Y-m-d'),
            'to'   => $this->to->format('Y-m-d'),
        ];

        if ($this->userId !== null) {
            $parts['user'] = (string) $this->userId;
        }

        if ($this->projectId !== null) {
            $parts['project'] = (string) $this->projectId;
        }

        return http_build_query($parts);
    }

    /** A filename stem describing what was exported. */
    public function filenameStem(): string
    {
        return 'macrolab-time-' . $this->from->format('Y-m-d') . '-to-' . $this->to->format('Y-m-d');
    }
}
