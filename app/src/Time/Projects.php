<?php

declare(strict_types=1);

namespace Macrolab\Time;

use Macrolab\Clock;
use Macrolab\Db;
use Macrolab\Http\HttpException;
use RuntimeException;

/**
 * Every query against the `projects` table.
 *
 * Shaped like Booking\Equipment, with one difference: it returns Project objects rather
 * than raw rows, which is the convention everything newer in this codebase
 * follows.
 */
final class Projects
{
    private const SELECT = 'SELECT p.id, p.name, p.code, p.description, p.is_active, p.created_at
                              FROM projects p';

    public static function find(int $id): ?Project
    {
        $row = Db::get()->one(self::SELECT . ' WHERE p.id = ?', [$id]);

        return $row === null ? null : Project::fromRow($row);
    }

    public static function findByName(string $name): ?Project
    {
        $row = Db::get()->one(self::SELECT . ' WHERE p.name = ?', [trim($name)]);

        return $row === null ? null : Project::fromRow($row);
    }

    /**
     * Every project, retired ones included, with their usage. For the admin
     * screen, which needs the counts to decide what may be deleted.
     *
     * @return list<Project>
     */
    public static function all(): array
    {
        $rows = Db::get()->all(
            'SELECT p.id, p.name, p.code, p.description, p.is_active, p.created_at,
                    (SELECT COUNT(*) FROM time_entries t WHERE t.project_id = p.id) AS entry_count,
                    (SELECT COALESCE(SUM(t.minutes), 0) FROM time_entries t WHERE t.project_id = p.id) AS total_minutes
               FROM projects p
              ORDER BY p.is_active DESC, p.name'
        );

        return array_map([Project::class, 'fromRow'], $rows);
    }

    /**
     * The projects an employee may log time against, in picker order.
     *
     * @return list<Project>
     */
    public static function allActive(): array
    {
        return array_map(
            [Project::class, 'fromRow'],
            Db::get()->all(self::SELECT . ' WHERE p.is_active = 1 ORDER BY p.name')
        );
    }

    /**
     * The project a write names. Unlike find() this refuses anything it does
     * not recognise: time must land on a real, active project.
     */
    public static function requireActive(int|string|null $identifier): Project
    {
        $identifier = trim((string) $identifier);

        $project = $identifier === '' || !ctype_digit($identifier)
            ? null
            : self::find((int) $identifier);

        if ($project === null || !$project->isActive) {
            throw HttpException::unprocessable('That activity is not available for time registration.');
        }

        return $project;
    }

    public static function create(string $name, ?string $code = null, ?string $description = null): Project
    {
        [$name, $code, $description] = self::clean($name, $code, $description);

        if (self::findByName($name) !== null) {
            throw new RuntimeException('There is already an activity called ' . $name . '.');
        }

        $id = Db::get()->insert('projects', [
            'name'        => $name,
            'code'        => $code,
            'description' => $description,
            'is_active'   => 1,
            'created_at'  => Clock::sql(),
        ]);

        $project = self::find($id);

        if ($project === null) {
            throw new RuntimeException('Project row disappeared immediately after insert.');
        }

        return $project;
    }

    public static function update(int $id, string $name, ?string $code = null, ?string $description = null): void
    {
        [$name, $code, $description] = self::clean($name, $code, $description);

        $clash = self::findByName($name);

        if ($clash !== null && $clash->id !== $id) {
            throw new RuntimeException('There is already an activity called ' . $name . '.');
        }

        Db::get()->update('projects', [
            'name'        => $name,
            'code'        => $code,
            'description' => $description,
        ], 'id = ?', [$id]);
    }

    public static function setActive(int $id, bool $active): void
    {
        Db::get()->update('projects', ['is_active' => $active ? 1 : 0], 'id = ?', [$id]);
    }

    public static function delete(int $id): void
    {
        Db::get()->query('DELETE FROM projects WHERE id = ?', [$id]);
    }

    public static function countEntries(int $id): int
    {
        return (int) Db::get()->value('SELECT COUNT(*) FROM time_entries WHERE project_id = ?', [$id]);
    }

    public static function countActive(): int
    {
        return (int) Db::get()->value('SELECT COUNT(*) FROM projects WHERE is_active = 1');
    }

    /**
     * @return array{0: string, 1: ?string, 2: ?string}
     */
    private static function clean(string $name, ?string $code, ?string $description): array
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 128) {
            throw new RuntimeException('An activity needs a name of 1 to 128 characters.');
        }

        $code = trim((string) $code);

        if (mb_strlen($code) > 32) {
            throw new RuntimeException('An activity code cannot be longer than 32 characters.');
        }

        $description = trim((string) $description);

        return [$name, $code === '' ? null : $code, $description === '' ? null : $description];
    }
}
