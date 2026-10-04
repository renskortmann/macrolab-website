<?php

declare(strict_types=1);

namespace Macrolab\Time;

use DateTimeImmutable;
use Macrolab\Actor;
use Macrolab\Audit;
use Macrolab\Clock;
use Macrolab\Db;
use Macrolab\Http\HttpException;
use PDOException;
use RuntimeException;

/**
 * Creating, changing and deleting time entries: the ownership check, the rule
 * checks and the audit entry, in one transaction.
 *
 * Note what is absent. There is no row lock, because unlike a booking there is
 * nothing here two requests can both claim. The per-day cap is advisory, and a
 * race that lets somebody log sixteen hours and five minutes is harmless.
 * Copying Equipment::lock() would be borrowing a mechanism whose reason does
 * not apply.
 */
final class TimeEntryService
{
    public static function create(
        Actor $actor,
        int $projectId,
        DateTimeImmutable $workedOn,
        int $minutes,
        ?string $note = null,
    ): TimeEntry {
        $userId = $actor->userId();

        if ($userId === null) {
            throw HttpException::forbidden(
                'The administrator does not keep a timesheet. Time is logged by lab members.'
            );
        }

        $project = Projects::requireActive($projectId);
        $note = self::normaliseNote($note);

        self::assertRules($workedOn, $minutes, $note, $userId, null);
        self::assertCellFree($userId, $project, $workedOn, null);

        try {
            return Db::get()->transaction(static function () use (
                $userId, $project, $workedOn, $minutes, $note
            ): TimeEntry {
                $now = Clock::sql();

                $id = Db::get()->insert('time_entries', [
                    'user_id'    => $userId,
                    'project_id' => $project->id,
                    'worked_on'  => $workedOn->format('Y-m-d'),
                    'minutes'    => $minutes,
                    'note'       => $note,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $entry = TimeEntries::find($id);

                if ($entry === null) {
                    throw new RuntimeException('Time entry row disappeared immediately after insert.');
                }

                Audit::log('time_entry_created', 'time_entry', $id, [
                    'owner_id'  => $userId,
                    'project'   => $project->name,
                    'worked_on' => $entry->workedOnDate(),
                    'minutes'   => $minutes,
                ]);

                return $entry;
            });
        } catch (PDOException $e) {
            throw self::isDuplicate($e) ? self::cellTaken($project, $workedOn) : $e;
        }
    }

    public static function update(
        Actor $actor,
        TimeEntry $entry,
        int $projectId,
        DateTimeImmutable $workedOn,
        int $minutes,
        ?string $note = null,
    ): TimeEntry {
        TimeEntryPolicy::assertCanModify($entry, $actor);

        $project = Projects::requireActive($projectId);
        $note = self::normaliseNote($note);

        self::assertRules($workedOn, $minutes, $note, $entry->userId, $entry->id);
        self::assertCellFree($entry->userId, $project, $workedOn, $entry->id);

        try {
            return Db::get()->transaction(static function () use (
                $entry, $project, $workedOn, $minutes, $note
            ): TimeEntry {
                Db::get()->update('time_entries', [
                    'project_id' => $project->id,
                    'worked_on'  => $workedOn->format('Y-m-d'),
                    'minutes'    => $minutes,
                    'note'       => $note,
                    'updated_at' => Clock::sql(),
                ], 'id = ?', [$entry->id]);

                $updated = TimeEntries::find($entry->id);

                if ($updated === null) {
                    throw new RuntimeException('Time entry disappeared while being updated.');
                }

                Audit::log('time_entry_updated', 'time_entry', $entry->id, [
                    'owner_id' => $entry->userId,
                    'from' => [
                        'project'   => $entry->projectName,
                        'worked_on' => $entry->workedOnDate(),
                        'minutes'   => $entry->minutes,
                    ],
                    'to' => [
                        'project'   => $project->name,
                        'worked_on' => $updated->workedOnDate(),
                        'minutes'   => $minutes,
                    ],
                ]);

                return $updated;
            });
        } catch (PDOException $e) {
            throw self::isDuplicate($e) ? self::cellTaken($project, $workedOn) : $e;
        }
    }

    /**
     * Save one cell of the day sheet: one project, one day, for the signed-in
     * person. The single write path behind the grid.
     *
     * What happens follows from what the cell now holds:
     *   no hours and no remark   the entry is removed, if there was one
     *   a remark but no hours    refused - a remark needs time to belong to
     *   hours                    the entry is created, or changed
     *
     * Each branch goes through create(), update() or delete(), so ownership,
     * the rules, the daily cap and the audit trail are exactly those of the
     * edit page. Returns the entry as saved, or null when the cell is empty.
     *
     * @param int|null $minutes null or 0 for an empty hours cell
     * @throws TimeEntryException
     */
    public static function saveCell(
        Actor $actor,
        DateTimeImmutable $day,
        int $projectId,
        ?int $minutes,
        ?string $note = null,
    ): ?TimeEntry {
        $userId = $actor->userId();

        if ($userId === null) {
            throw HttpException::forbidden(
                'The administrator does not keep a timesheet. Time is logged by lab members.'
            );
        }

        // A retired project's row is shown read-only, and this is what makes
        // that true for a request that ignores the disabled inputs.
        $project = Projects::requireActive($projectId);
        $note = self::normaliseNote($note);
        $existing = TimeEntries::findForUserProjectDay($userId, $project->id, $day);

        if ($minutes === null || $minutes === 0) {
            if ($note !== null) {
                throw TimeEntryException::invalid(['Enter the hours for this remark.']);
            }

            if ($existing !== null) {
                self::delete($actor, $existing);
            }

            return null;
        }

        if ($existing === null) {
            return self::create($actor, $project->id, $day, $minutes, $note);
        }

        // Leaving a cell without changing it is not a change, and should not
        // leave an audit entry saying it was.
        if ($existing->minutes === $minutes && $existing->note === $note) {
            return $existing;
        }

        return self::update($actor, $existing, $project->id, $day, $minutes, $note);
    }

    /**
     * Remove an entry outright.
     *
     * Time entries are deleted rather than kept with a cancelled status: unlike
     * a booking, a withdrawn entry says nothing useful about who had what. The
     * audit record carries the whole of what was removed, and is what remains
     * of it.
     */
    public static function delete(Actor $actor, TimeEntry $entry): void
    {
        TimeEntryPolicy::assertCanModify($entry, $actor);

        Db::get()->transaction(static function () use ($entry): void {
            Audit::log('time_entry_deleted', 'time_entry', $entry->id, [
                'owner_id'  => $entry->userId,
                'owner'     => $entry->ownerNetid,
                'project'   => $entry->projectName,
                'worked_on' => $entry->workedOnDate(),
                'minutes'   => $entry->minutes,
                'note'      => $entry->note,
            ]);

            Db::get()->query('DELETE FROM time_entries WHERE id = ?', [$entry->id]);
        });
    }

    /**
     * @throws TimeEntryException
     */
    private static function assertRules(
        DateTimeImmutable $workedOn,
        int $minutes,
        ?string $note,
        int $ownerUserId,
        ?int $excludeEntryId,
    ): void {
        $errors = TimeRules::validate(
            rules: TimeRuleSet::fromSettings(),
            workedOn: $workedOn,
            minutes: $minutes,
            // Read here, not inside TimeRules, which stays pure.
            today: TimeRules::today(),
            minutesAlreadyOnDay: TimeEntries::minutesForUserOnDay($ownerUserId, $workedOn, $excludeEntryId),
            note: $note,
        );

        if ($errors !== []) {
            throw TimeEntryException::invalid($errors);
        }
    }

    /**
     * One entry per person, project and day - the unique key enforces it, and
     * this turns the collision into a sentence before the key has to.
     *
     * @throws TimeEntryException
     */
    private static function assertCellFree(
        int $userId,
        Project $project,
        DateTimeImmutable $workedOn,
        ?int $excludeEntryId,
    ): void {
        $other = TimeEntries::findForUserProjectDay($userId, $project->id, $workedOn);

        if ($other !== null && $other->id !== $excludeEntryId) {
            throw self::cellTaken($project, $workedOn);
        }
    }

    private static function cellTaken(Project $project, DateTimeImmutable $workedOn): TimeEntryException
    {
        return TimeEntryException::invalid([
            'You already have time on ' . $project->name . ' for ' . $workedOn->format('D j M Y')
                . '. Change that entry instead.',
        ]);
    }

    /**
     * A unique-key collision. Only reachable when two requests fill the same
     * cell at once, since assertCellFree() has already looked.
     */
    private static function isDuplicate(PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }

    /** Trim to null, but never truncate: TimeRules rejects an over-long note. */
    private static function normaliseNote(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note === '' ? null : $note;
    }
}
