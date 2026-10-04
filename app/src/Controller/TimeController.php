<?php

declare(strict_types=1);

namespace Macrolab\Controller;

use DateTimeImmutable;
use Macrolab\Auth;
use Macrolab\Csrf;
use Macrolab\Http\HttpException;
use Macrolab\Http\Request;
use Macrolab\Http\Response;
use Macrolab\Time\Project;
use Macrolab\Time\Projects;
use Macrolab\Session;
use Macrolab\Time\TimeEntries;
use Macrolab\Time\TimeEntry;
use Macrolab\Time\TimeEntryException;
use Macrolab\Time\TimeEntryPolicy;
use Macrolab\Time\TimeEntryService;
use Macrolab\Time\TimeRuleSet;
use Macrolab\Time\TimeRules;
use Macrolab\View;

/**
 * An employee's own timesheet.
 *
 * The top of the page is a day sheet: one row per project for a single day,
 * each cell saved on its own through TimeApiController as it is left. The
 * bottom is the month's entries as a list, with the edit page and the remove
 * button behind each one.
 */
final class TimeController
{
    public function show(Request $request): Response
    {
        $actor = Auth::requireActor();

        /*
         * The administrator has no timesheet, so send them to the overview
         * instead. Without this, requireUser() below would throw 401 and the
         * error handler would bounce a signed-in administrator to the sign-in
         * page, which is a genuinely baffling thing to have happen.
         */
        if ($actor->isAdmin) {
            return Response::redirect('/admin/time');
        }

        $user = Auth::requireTimeRegistration()->user;
        $rules = TimeRuleSet::fromSettings();
        $today = TimeRules::today();
        $day = self::day($request) ?? $today;
        $rows = $this->rows($user->id, $day);

        return View::page('time/index', [
            'title'      => 'Time registration',
            'day'        => $day,
            // A calendar day, so ->format() and never Clock::local(): see TimeEntry.
            'dayLabel'   => $day->format('l - d-m-Y'),
            'prevDay'    => $day->modify('-1 day')->format('Y-m-d'),
            'nextDay'    => $day->modify('+1 day')->format('Y-m-d'),
            'isWeekend'  => (int) $day->format('N') >= 6,
            'isOpen'     => TimeRules::isOpenForLogging($rules, $day, $today),
            'rows'       => $rows,
            'rules'      => $rules,
            // The month list starts folded, unless the visitor came here by
            // stepping through months (without the script, that reloads the page).
            'month'      => self::monthData($user->id, self::month($request, $day), $day,
                $request->query('month') !== null),
        ]);
    }

    /**
     * What the month list at the bottom of the page needs. Shared with the
     * endpoint that re-renders that list after a cell is saved or a month
     * arrow is clicked.
     *
     * @return array<string, mixed>
     */
    public static function monthData(
        int $userId,
        DateTimeImmutable $month,
        DateTimeImmutable $day,
        bool $open = false,
    ): array {
        $entries = TimeEntries::forUser($userId, $month, $month->modify('last day of this month'));
        $prev = $month->modify('-1 month');
        $next = $month->modify('+1 month');

        return [
            'entries'      => $entries,
            'month'        => $month,
            'open'         => $open,
            'day'          => $day->format('Y-m-d'),
            'prevMonth'    => $prev->format('Y-m'),
            'nextMonth'    => $next->format('Y-m'),
            'prevLabel'    => $prev->format('F Y'),
            'nextLabel'    => $next->format('F Y'),
            'totalMinutes' => array_sum(array_map(static fn (TimeEntry $e): int => $e->minutes, $entries)),
            'byProject'    => self::byProject($entries),
        ];
    }

    /** The day asked for in ?day=, or null when it is missing or malformed. */
    public static function day(Request $request): ?DateTimeImmutable
    {
        return TimeRules::parseDate($request->query('day', '') ?? '');
    }

    /**
     * The month to list, as its first day: ?month= when the list has been
     * paged on its own, otherwise the month of the day on the sheet.
     */
    public static function month(Request $request, DateTimeImmutable $day): DateTimeImmutable
    {
        $month = (string) ($request->query('month', '') ?? '');

        if (preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            $parsed = TimeRules::parseDate($month . '-01');

            if ($parsed !== null) {
                return $parsed;
            }
        }

        return $day->modify('first day of this month');
    }

    public function edit(Request $request, string $id): Response
    {
        $actor = Auth::requireTimeRegistration();
        $entry = $this->entryOr404($id);

        // The 403 for somebody else's entry, decided on the loaded row.
        TimeEntryPolicy::assertCanModify($entry, $actor);

        $error = null;

        if ($request->isPost()) {
            Csrf::verify($request);

            try {
                $updated = TimeEntryService::update(
                    actor: $actor,
                    entry: $entry,
                    projectId: (int) ($request->post('project_id', '0') ?? '0'),
                    workedOn: $this->workedOn($request),
                    minutes: $this->minutes($request),
                    note: $request->post('note'),
                );

                Session::flash('success', 'Your time entry has been changed.');

                // To the day it now sits on, which may not be the one it left.
                return Response::redirect('/time?day=' . $updated->workedOnDate());
            } catch (TimeEntryException $e) {
                $error = implode(' ', $e->errors);
            } catch (\RuntimeException $e) {
                $error = $e->getMessage();
            }
        }

        return View::page('time/edit', [
            'title'    => 'Change a time entry',
            'entry'    => $entry,
            'projects' => Projects::allActive(),
            'rules'    => TimeRuleSet::fromSettings(),
            'error'    => $error,
        ], $error !== null ? 422 : 200);
    }

    public function delete(Request $request, string $id): Response
    {
        Csrf::verify($request);

        $actor = Auth::requireTimeRegistration();
        $entry = $this->entryOr404($id);

        TimeEntryService::delete($actor, $entry);
        Session::flash('success', 'That time entry has been removed.');

        return Response::redirect('/time?day=' . $entry->workedOnDate());
    }

    /** @throws TimeEntryException when the field is missing or malformed */
    private function workedOn(Request $request): DateTimeImmutable
    {
        $date = TimeRules::parseDate($request->post('worked_on', '') ?? '');

        if ($date === null) {
            throw TimeEntryException::invalid(['Choose the day you worked.']);
        }

        return $date;
    }

    /** @throws TimeEntryException when the field is missing or malformed */
    private function minutes(Request $request): int
    {
        $minutes = TimeRules::parseHours($request->post('hours', '') ?? '');

        if ($minutes === null) {
            throw TimeEntryException::invalid([
                'Enter the hours as a number like 3.5, or as 3:30.',
            ]);
        }

        return $minutes;
    }

    private function entryOr404(string $id): TimeEntry
    {
        if (!ctype_digit($id)) {
            throw HttpException::notFound();
        }

        $entry = TimeEntries::find((int) $id);

        if ($entry === null) {
            throw HttpException::notFound('That time entry no longer exists.');
        }

        return $entry;
    }

    /**
     * One row per project for the day sheet: every active project, plus any
     * retired one that already has time on this day. Leaving those out would
     * make the day look shorter than it is; they are shown read-only.
     *
     * @return list<array{project: Project, entry: TimeEntry|null}>
     */
    private function rows(int $userId, DateTimeImmutable $day): array
    {
        $entries = TimeEntries::forUserOnDay($userId, $day);
        $rows = [];

        foreach (Projects::allActive() as $project) {
            $rows[] = ['project' => $project, 'entry' => $entries[$project->id] ?? null];
            unset($entries[$project->id]);
        }

        // Whatever is left belongs to a project that has since been retired.
        foreach ($entries as $projectId => $entry) {
            $project = Projects::find($projectId);

            if ($project !== null) {
                $rows[] = ['project' => $project, 'entry' => $entry];
            }
        }

        return $rows;
    }

    /**
     * @param list<TimeEntry> $entries
     * @return list<array{project: string, minutes: int}>
     */
    private static function byProject(array $entries): array
    {
        $totals = [];

        foreach ($entries as $entry) {
            $name = $entry->projectLabel();
            $totals[$name] = ($totals[$name] ?? 0) + $entry->minutes;
        }

        arsort($totals);

        $out = [];
        foreach ($totals as $project => $minutes) {
            $out[] = ['project' => (string) $project, 'minutes' => $minutes];
        }

        return $out;
    }
}
