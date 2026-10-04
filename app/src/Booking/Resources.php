<?php

declare(strict_types=1);

namespace Macrolab\Booking;

use Macrolab\Clock;
use Macrolab\Db;
use Macrolab\Http\HttpException;
use RuntimeException;

/**
 * The bookable machines. Bookings reference a resource row, so the lab can run
 * several instruments from one calendar; the administrator manages the list and
 * each member picks which machine they are looking at.
 */
final class Resources
{
    private const COLUMNS = 'id, name, slug, description, is_active, created_at';

    /**
     * The oldest still-active machine. The calendar never picks it for anyone
     * (see selected()); tests and scripts use it as "a machine".
     *
     * @return array<string, mixed>
     */
    public static function primary(): array
    {
        $row = Db::get()->one(
            'SELECT ' . self::COLUMNS . ' FROM resources WHERE is_active = 1 ORDER BY id LIMIT 1'
        );

        if ($row === null) {
            throw new RuntimeException(
                'No active machine found. Add one in the administration pages, '
                . 'or run app/cli/migrate.php if this is a fresh installation.'
            );
        }

        return $row;
    }

    public static function primaryId(): int
    {
        return (int) self::primary()['id'];
    }

    /**
     * Every machine, including the deactivated ones. For the admin screen.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return Db::get()->all(
            'SELECT ' . self::COLUMNS . ',
                    (SELECT COUNT(*) FROM bookings b WHERE b.resource_id = resources.id) AS booking_count
               FROM resources
              ORDER BY is_active DESC, name'
        );
    }

    /**
     * The machines a member may book, in the order the picker shows them.
     *
     * @return list<array<string, mixed>>
     */
    public static function allActive(): array
    {
        return Db::get()->all(
            'SELECT ' . self::COLUMNS . ' FROM resources WHERE is_active = 1 ORDER BY name'
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        return Db::get()->one('SELECT ' . self::COLUMNS . ' FROM resources WHERE id = ?', [$id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findBySlug(string $slug): ?array
    {
        return Db::get()->one('SELECT ' . self::COLUMNS . ' FROM resources WHERE slug = ?', [$slug]);
    }

    /**
     * The machine the calendar shows: the active one named in the URL, or none.
     *
     * Nothing is remembered between visits and there is no default: the
     * calendar starts empty until the member picks a machine, so nobody books
     * the wrong instrument by accident. An unknown or retired slug - an old
     * link - also gives none rather than an error.
     *
     * @return array<string, mixed>|null
     */
    public static function selected(?string $slug): ?array
    {
        if ($slug === null || $slug === '') {
            return null;
        }

        $row = self::findBySlug($slug);

        return $row !== null && (int) $row['is_active'] === 1 ? $row : null;
    }

    /**
     * The machine a booking write names. Unlike resolve() this refuses anything
     * it does not recognise: a booking must land on a real, active machine.
     */
    public static function requireActive(?string $identifier): array
    {
        $identifier = trim((string) $identifier);

        $row = $identifier === ''
            ? null
            : (ctype_digit($identifier)
                ? self::find((int) $identifier)
                : self::findBySlug($identifier));

        if ($row === null || (int) $row['is_active'] !== 1) {
            throw HttpException::unprocessable('That machine is not available for booking.');
        }

        return $row;
    }

    public static function create(string $name, ?string $description = null): array
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 128) {
            throw new RuntimeException('A machine needs a name of 1 to 128 characters.');
        }

        $description = trim((string) $description);

        $id = Db::get()->insert('resources', [
            'name'        => $name,
            'slug'        => self::uniqueSlug($name),
            'description' => $description === '' ? null : $description,
            'is_active'   => 1,
            'created_at'  => Clock::sql(),
        ]);

        $row = self::find($id);

        if ($row === null) {
            throw new RuntimeException('Machine row disappeared immediately after insert.');
        }

        return $row;
    }

    /**
     * Rename a machine or change its description. The slug is left alone: it is
     * in the addresses people have bookmarked.
     */
    public static function update(int $id, string $name, ?string $description = null): void
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 128) {
            throw new RuntimeException('A machine needs a name of 1 to 128 characters.');
        }

        $description = trim((string) $description);

        Db::get()->update('resources', [
            'name'        => $name,
            'description' => $description === '' ? null : $description,
        ], 'id = ?', [$id]);
    }

    public static function setActive(int $id, bool $active): void
    {
        Db::get()->update('resources', ['is_active' => $active ? 1 : 0], 'id = ?', [$id]);
    }

    public static function delete(int $id): void
    {
        Db::get()->query('DELETE FROM resources WHERE id = ?', [$id]);
    }

    public static function countBookings(int $id): int
    {
        return (int) Db::get()->value('SELECT COUNT(*) FROM bookings WHERE resource_id = ?', [$id]);
    }

    public static function countActive(): int
    {
        return (int) Db::get()->value('SELECT COUNT(*) FROM resources WHERE is_active = 1');
    }

    /**
     * Take a row lock on the resource. Every booking write does this first, so
     * that writes for one machine are serialised and two requests cannot both
     * find a slot free and then both fill it. Two different machines take two
     * different locks and do not wait for each other.
     *
     * MySQL has no exclusion constraint for time ranges, and relying on InnoDB
     * gap locks would be subtle; one explicit row lock is easy to verify.
     */
    public static function lock(int $resourceId): void
    {
        Db::get()->query('SELECT id FROM resources WHERE id = ? FOR UPDATE', [$resourceId]);
    }

    /** A URL-safe slug for a name, with a suffix if that slug is taken. */
    private static function uniqueSlug(string $name): string
    {
        $base = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-'));

        if ($base === '') {
            $base = 'machine';
        }

        $base = mb_substr($base, 0, 56);
        $slug = $base;

        for ($suffix = 2; self::findBySlug($slug) !== null; $suffix++) {
            $slug = $base . '-' . $suffix;
        }

        return $slug;
    }
}
