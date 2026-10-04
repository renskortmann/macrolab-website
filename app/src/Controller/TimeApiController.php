<?php

declare(strict_types=1);

namespace Macrolab\Controller;

use Macrolab\Auth;
use Macrolab\Csrf;
use Macrolab\Http\HttpException;
use Macrolab\Http\Request;
use Macrolab\Http\Response;
use Macrolab\Time\TimeEntries;
use Macrolab\Time\TimeEntryException;
use Macrolab\Time\TimeEntryService;
use Macrolab\Time\TimeRules;
use Macrolab\View;

/**
 * The JSON the day sheet talks to: one call per cell as it is left, and one
 * to redraw the month list underneath so it agrees with what was just saved.
 *
 * Every write goes through TimeEntryService::saveCell(), which checks
 * ownership and the rules exactly as the edit page does.
 */
final class TimeApiController
{
    /** POST /api/time/cell  {day, project_id, hours, note} */
    public function saveCell(Request $request): Response
    {
        $actor = Auth::requireTimeRegistration();
        Csrf::verify($request);

        $day = TimeRules::parseDate($request->post('day', '') ?? '');

        if ($day === null) {
            throw HttpException::unprocessable('That day could not be read.');
        }

        $hours = $request->post('hours', '') ?? '';
        $minutes = $hours === '' ? null : TimeRules::parseHours($hours);

        try {
            if ($hours !== '' && $minutes === null) {
                throw TimeEntryException::invalid(['Enter the hours as a number like 3.5, or as 3:30.']);
            }

            $entry = TimeEntryService::saveCell(
                actor: $actor,
                day: $day,
                projectId: (int) ($request->post('project_id', '0') ?? '0'),
                minutes: $minutes,
                note: $request->post('note'),
            );
        } catch (TimeEntryException $e) {
            return Response::json([
                'error'  => $e->errors[0] ?? 'That time could not be saved.',
                'errors' => $e->errors,
            ], $e->status);
        }

        $userId = (int) $actor->userId();

        return Response::json([
            // Written back into the cell, so "3,5" reads as the "3:30" stored.
            'hours'    => $entry === null ? '' : $entry->hoursLabel(),
            'note'     => $entry?->note ?? '',
            'dayTotal' => TimeRules::formatHours(TimeEntries::minutesForUserOnDay($userId, $day)),
        ]);
    }

    /** GET /api/time/month?day=&month= - the month list, as rendered HTML. */
    public function month(Request $request): Response
    {
        $user = Auth::requireTimeRegistration()->user;
        $day = TimeController::day($request) ?? TimeRules::today();

        return Response::html(View::render(
            'time/month',
            TimeController::monthData($user->id, TimeController::month($request, $day), $day)
        ));
    }
}
