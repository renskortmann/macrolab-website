<?php

declare(strict_types=1);

namespace Macrolab\Tests\Integration;

use Macrolab\Auth;
use Macrolab\Auth\Identity;
use Macrolab\Controller\AdminController;
use Macrolab\Controller\AdminTimeController;
use Macrolab\Controller\CalendarController;
use Macrolab\Controller\TimeApiController;
use Macrolab\Controller\TimeController;
use Macrolab\Context;
use Macrolab\Csrf;
use Macrolab\Db;
use Macrolab\Http\HttpException;
use Macrolab\Http\Request;
use Macrolab\Navigation;
use Macrolab\Role;
use Macrolab\Users;

/**
 * The three member roles, as a member meets them: what the hub offers and
 * which pages open.
 */
final class RolesTest extends DatabaseTestCase
{
    public function testANewAccountIsALabUserUnlessToldOtherwise(): void
    {
        self::assertSame(Role::LabUser, Users::create('nina')->role);
        self::assertSame(Role::LabManager, Users::create('omar', role: Role::LabManager)->role);
    }

    public function testARowWithoutARoleGetsTheLeastAccess(): void
    {
        Db::get()->insert('users', ['netid' => 'pat', 'status' => 'approved', 'created_at' => '2026-10-04 08:00:00']);

        self::assertSame(Role::LabUser, Users::findByNetid('pat')?->role);
    }

    public function testALabUserBooksButHasNoTimeRegistration(): void
    {
        $this->signInAs('quinn', Role::LabUser);

        self::assertSame(['/booking', '/account'], $this->destinations());
        self::assertSame(200, (new CalendarController())->show(new Request('GET', '/booking'))->status);

        $this->assertForbidden(fn () => (new TimeController())->show(new Request('GET', '/time')));
        $this->assertForbidden(fn () => (new TimeApiController())->saveCell(new Request('POST', '/api/time/cell', post: [
            Csrf::FIELD => Csrf::token(), 'day' => '2026-10-02', 'project_id' => '1', 'hours' => '1',
        ])));
        $this->assertForbidden(fn () => $this->overview());
    }

    public function testALabTechnicianRegistersTimeButSeesOnlyTheirOwn(): void
    {
        $this->signInAs('rosa', Role::LabTechnician);

        self::assertSame(['/booking', '/time', '/account'], $this->destinations());

        $request = new Request('GET', '/time');
        Context::setRequest($request);
        $page = (new TimeController())->show($request);
        self::assertSame(200, $page->status);
        self::assertMatchesRegularExpression('#href="/time"\s+data-label="Time registration"\s+aria-current="page"#',
            $page->body, 'the Time registration tab is current');

        $this->assertForbidden(fn () => $this->overview());
        $this->assertForbidden(fn () => (new AdminTimeController())->export(new Request('GET', '/time/overview.csv')));
    }

    public function testALabManagerAlsoSeesAndExportsEveryonesTime(): void
    {
        $this->signInAs('sam', Role::LabManager);

        self::assertSame(['/booking', '/time', '/time/overview', '/account'], $this->destinations());
        self::assertSame(200, (new TimeController())->show(new Request('GET', '/time'))->status);

        $page = $this->overview();
        self::assertSame(200, $page->status);
        self::assertSame(1, substr_count($page->body, 'aria-current="page"'), 'one tab is current');
        self::assertMatchesRegularExpression('#href="/time/overview"\s+data-label="Time overview"\s+aria-current="page"#',
            $page->body, 'and it is Time overview, not Time registration');
        self::assertStringContainsString('action="/time/overview"', $page->body, 'the filter stays on the manager path');
        self::assertStringContainsString('/time/overview.csv?', $page->body, 'and so do the export links');
        self::assertStringContainsString('<div class="filter-fields">', $page->body, 'the filter is laid out as a grid');
        self::assertMatchesRegularExpression('#<h2>Export to CSV</h2>.*<h3>Totals</h3>.*<h3>Download</h3>#s', $page->body);

        $csv = (new AdminTimeController())->export(new Request('GET', '/time/overview.csv', query: ['sep' => 'semicolon']));
        self::assertStringContainsString('date;netid;name', $csv->body);
    }

    public function testTheAdministratorKeepsTheOverviewAtItsOwnPath(): void
    {
        Auth::completeAdminLogin(1, 'admin');

        $page = (new AdminTimeController())->entries(new Request('GET', '/admin/time'));

        self::assertSame(200, $page->status);
        self::assertStringContainsString('action="/admin/time"', $page->body);
    }

    public function testTheAdministratorSetsAndChangesRoles(): void
    {
        Auth::completeAdminLogin(1, 'admin');
        $post = fn (array $fields) => (new AdminController())->users(
            new Request('POST', '/admin/users', post: [Csrf::FIELD => Csrf::token()] + $fields)
        );

        $post(['action' => 'add', 'netid' => 'uma', 'role' => 'lab_technician']);
        $uma = Users::findByNetid('uma');
        self::assertSame(Role::LabTechnician, $uma?->role);

        $post(['action' => 'role', 'user_id' => (string) $uma->id, 'role' => 'lab_manager']);
        self::assertSame(Role::LabManager, Users::findByNetid('uma')?->role);
        self::assertSame(1, (int) Db::get()->value(
            'SELECT COUNT(*) FROM audit_log WHERE action = ? AND target_id = ?', ['user_role_changed', $uma->id]
        ));

        $refused = $post(['action' => 'role', 'user_id' => (string) $uma->id, 'role' => 'superuser']);
        self::assertSame(422, $refused->status);
        self::assertSame(Role::LabManager, Users::findByNetid('uma')?->role, 'an invalid role changes nothing');
    }

    public function testARoleChangeAppliesOnTheNextRequest(): void
    {
        $user = $this->signInAs('tess', Role::LabUser);
        self::assertNotContains('/time', $this->destinations());

        Users::setRole($user->id, Role::LabTechnician);
        Auth::resetCache();

        self::assertContains('/time', $this->destinations());
    }

    private function signInAs(string $netid, Role $role): \Macrolab\User
    {
        Users::create($netid, role: $role);
        $user = Auth::signIn(new Identity(netid: $netid, method: 'test'));
        Auth::resetCache();

        return $user;
    }

    /** @return list<string> */
    private function destinations(): array
    {
        return array_column(Navigation::destinations(Auth::actor()), 'href');
    }

    private function overview(): \Macrolab\Http\Response
    {
        $request = new Request('GET', '/time/overview');
        Context::setRequest($request);

        return (new AdminTimeController())->entries($request);
    }

    private function assertForbidden(callable $call): void
    {
        try {
            $call();
            self::fail('expected a 403');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }
    }
}
