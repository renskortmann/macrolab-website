<?php

declare(strict_types=1);

namespace Macrolab\Tests\Integration;

use Macrolab\Auth;
use Macrolab\Auth\Identity;
use Macrolab\Context;
use Macrolab\Controller\AdminController;
use Macrolab\Controller\AdminTimeController;
use Macrolab\Controller\CalendarController;
use Macrolab\Http\Request;
use Macrolab\Http\Response;
use Macrolab\Users;

/**
 * The second row of tabs on the Administration pages.
 */
final class AdminTabsTest extends DatabaseTestCase
{
    public function testAnAdministrationPageShowsTheTabsWithItsOwnMarked(): void
    {
        Auth::completeAdminLogin(1, 'admin');

        $body = $this->get('/admin/users', fn (Request $r) => (new AdminController())->users($r))->body;

        self::assertStringContainsString('<nav class="subtabs" aria-label="Administration">', $body);
        self::assertMatchesRegularExpression('#href="/admin/users"\\s+data-label="Who may sign in"\\s+aria-current="page"#', $body);
        self::assertMatchesRegularExpression('#href="/admin"\\s+data-label="Administration"\\s+aria-current="page"#', $body,
            'and in the top ribbon, Administration');
    }

    public function testTheDashboardIsATabAndStartsWithAtAGlance(): void
    {
        Auth::completeAdminLogin(1, 'admin');

        $body = $this->get('/admin', fn (Request $r) => (new AdminController())->dashboard($r))->body;

        self::assertMatchesRegularExpression('#href="/admin"\\s+data-label="Dashboard"\\s+aria-current="page"#', $body);
        self::assertMatchesRegularExpression('#<main>\\s*(<p class="flash[^<]*</p>\\s*)*<section class="card">\\s*<h1>At a glance</h1>#', $body);
    }

    public function testTheTimeOverviewHasNoAdministrationTabs(): void
    {
        Auth::completeAdminLogin(1, 'admin');

        $body = $this->get('/admin/time', fn (Request $r) => (new AdminTimeController())->entries($r))->body;

        self::assertStringNotContainsString('class="subtabs"', $body);
    }

    public function testMembersNeverSeeThem(): void
    {
        Users::create('yan');
        Auth::signIn(new Identity(netid: 'yan', method: 'test'));
        Auth::resetCache();

        $body = $this->get('/booking', fn (Request $r) => (new CalendarController())->show($r))->body;

        self::assertStringNotContainsString('class="subtabs"', $body);
    }

    /** A page rendered as Bootstrap would: with the request in the context. */
    private function get(string $path, callable $action): Response
    {
        $request = new Request('GET', $path);
        Context::setRequest($request);

        return $action($request);
    }
}
