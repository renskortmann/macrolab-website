<?php

declare(strict_types=1);

namespace Macrolab\Tests\Integration;

use Macrolab\Db;
use Macrolab\Migrator;
use PDO;
use PDOException;

/**
 * Migration 005 renames resources to equipment on a database that already has
 * bookings and audit entries - which is how it runs on the live site, unlike
 * the empty schema every other test starts from.
 */
final class EquipmentMigrationTest extends DatabaseTestCase
{
    private string $oldMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $pdo = Db::get()->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        // The schema as it was before 005: only the migrations up to 004.
        $this->oldMigrations = sys_get_temp_dir() . '/macrolab-migrations-' . getmypid();
        @mkdir($this->oldMigrations);
        $all = dirname(__DIR__, 2) . '/app/migrations';
        foreach (glob($all . '/00[1-4]_*.sql') ?: [] as $file) {
            copy($file, $this->oldMigrations . '/' . basename($file));
        }
        (new Migrator(Db::get(), $this->oldMigrations))->migrate();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->oldMigrations . '/*.sql') ?: []);
        @rmdir($this->oldMigrations);

        parent::tearDown();
    }

    public function testExistingBookingsAndAuditEntriesSurviveTheRename(): void
    {
        $db = Db::get();
        $luna = (int) $db->value("SELECT id FROM resources WHERE slug = 'luna-od6'");
        $db->insert('users', ['netid' => 'vic', 'status' => 'approved', 'created_at' => '2026-10-01 08:00:00']);
        $userId = (int) $db->value("SELECT id FROM users WHERE netid = 'vic'");
        $db->insert('bookings', [
            'resource_id' => $luna, 'user_id' => $userId,
            'starts_at' => '2026-10-05 07:00:00', 'ends_at' => '2026-10-05 09:00:00',
            'status' => 'confirmed', 'created_by_admin' => 0,
            'created_at' => '2026-10-01 08:00:00', 'updated_at' => '2026-10-01 08:00:00',
        ]);
        $db->insert('audit_log', [
            'actor_type' => 'admin', 'actor_label' => 'admin', 'action' => 'machine_added',
            'target_type' => 'resource', 'target_id' => $luna, 'created_at' => '2026-10-01 08:00:00',
        ]);

        (new Migrator($db))->migrate();

        self::assertSame($luna, (int) $db->value('SELECT equipment_id FROM bookings WHERE user_id = ?', [$userId]));
        self::assertSame('LUNA OD6', $db->value('SELECT description FROM equipment WHERE id = ?', [$luna]));
        self::assertSame(
            ['action' => 'equipment_added', 'target_type' => 'equipment'],
            $db->one('SELECT action, target_type FROM audit_log WHERE target_id = ?', [$luna])
        );

        // The foreign key still protects a piece of equipment that has bookings.
        $this->expectException(PDOException::class);
        $db->query('DELETE FROM equipment WHERE id = ?', [$luna]);
    }
}
