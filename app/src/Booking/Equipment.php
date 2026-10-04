<?php

declare(strict_types=1);

namespace Macrolab\Booking;

use Macrolab\Clock;
use Macrolab\Db;
use Macrolab\Http\HttpException;
use RuntimeException;

/**
 * The bookable equipment. Each booking references one row here - one piece of
 * equipment - so the lab can run several pieces from one calendar; the
 * administrator manages the list and each member picks which piece they are
 * looking at.
 */
final class Equipment
{
    private const COLUMNS = 'id, name, slug, description, is_active, created_at';

    /**
     * The oldest still-active piece of equipment. The calendar never picks it
     * for anyone (see selected()); tests and scripts use it as "a piece".
     *
     * @return array<string, mixed>
     */
    public static function primary(): array
    {
        $row = Db::get()->one(
            'SELECT ' . self::COLUMNS . ' FROM equipment WHERE is_active = 1 ORDER BY id LIMIT 1'
        );

        if ($row === null) {
            throw new RuntimeException(
                'No active equipment found. Add some in the administration pages, '
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
     * All equipment, including the deactivated pieces. For the admin screen.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return Db::get()->all(
            'SELECT ' . self::COLUMNS . ',
                    (SELECT COUNT(*) FROM bookings b WHERE b.equipment_id = equipment.id) AS booking_count
               FROM equipment
              ORDER BY is_active DESC, name'
        );
    }

    /**
     * The equipment a member may book, in the order the picker shows it.
     *
     * @return list<array<string, mixed>>
     */
    public static function allActive(): array
    {
        return Db::get()->all(
            'SELECT ' . self::COLUMNS . ' FROM equipment WHERE is_active = 1 ORDER BY name'
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        return Db::get()->one('SELECT ' . self::COLUMNS . ' FROM equipment WHERE id = ?', [$id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findBySlug(string $slug): ?array
    {
        return Db::get()->one('SELECT ' . self::COLUMNS . ' FROM equipment WHERE slug = ?', [$slug]);
    }

    /**
     * The piece of equipment the calendar shows: the active one named in the
     * URL, or none.
     *
     * Nothing is remembered between visits and there is no default: the
     * calendar starts empty until the member picks a piece, so nobody books
     * the wrong equipment by accident. An unknown or retired slug - an old
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
     * The piece of equipment a booking write names. Unlike selected() this
     * refuses anything it does not recognise: a booking must land on a real,
     * active piece of equipment.
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
            throw HttpException::unprocessable('That equipment is not available for booking.');
        }

        return $row;
    }

    public static function create(string $name, ?string $description = null): array
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 128) {
            throw new RuntimeException('A piece of equipment needs a name of 1 to 128 characters.');
        }

        $description = trim((string) $description);

        $id = Db::get()->insert('equipment', [
            'name'        => $name,
            'slug'        => self::uniqueSlug($name),
            'description' => $description === '' ? null : $description,
            'is_active'   => 1,
            'created_at'  => Clock::sql(),
        ]);

        $row = self::find($id);

        if ($row === null) {
            throw new RuntimeException('Equipment row disappeared immediately after insert.');
        }

        return $row;
    }

    /**
     * Rename a piece of equipment or change its description. The slug is left alone: it is
     * in the addresses people have bookmarked.
     */
    public static function update(int $id, string $name, ?string $description = null): void
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 128) {
            throw new RuntimeException('A piece of equipment needs a name of 1 to 128 characters.');
        }

        $description = trim((string) $description);

        Db::get()->update('equipment', [
            'name'        => $name,
            'description' => $description === '' ? null : $description,
        ], 'id = ?', [$id]);
    }

    public static function setActive(int $id, bool $active): void
    {
        Db::get()->update('equipment', ['is_active' => $active ? 1 : 0], 'id = ?', [$id]);
    }

    public static function delete(int $id): void
    {
        Db::get()->query('DELETE FROM equipment WHERE id = ?', [$id]);
    }

    public static function countBookings(int $id): int
    {
        return (int) Db::get()->value('SELECT COUNT(*) FROM bookings WHERE equipment_id = ?', [$id]);
    }

    public static function countActive(): int
    {
        return (int) Db::get()->value('SELECT COUNT(*) FROM equipment WHERE is_active = 1');
    }

    /**
     * Take a row lock on the piece of equipment. Every booking write does this
     * first, so that writes for one piece are serialised and two requests cannot
     * both find a slot free and then both fill it. Two different pieces take two
     * different locks and do not wait for each other.
     *
     * MySQL has no exclusion constraint for time ranges, and relying on InnoDB
     * gap locks would be subtle; one explicit row lock is easy to verify.
     */
    public static function lock(int $equipmentId): void
    {
        Db::get()->query('SELECT id FROM equipment WHERE id = ? FOR UPDATE', [$equipmentId]);
    }

    /** A URL-safe slug for a name, with a suffix if that slug is taken. */
    private static function uniqueSlug(string $name): string
    {
        $base = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-'));

        if ($base === '') {
            $base = 'equipment';
        }

        $base = mb_substr($base, 0, 56);
        $slug = $base;

        for ($suffix = 2; self::findBySlug($slug) !== null; $suffix++) {
            $slug = $base . '-' . $suffix;
        }

        return $slug;
    }
}
