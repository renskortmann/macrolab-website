<?php

declare(strict_types=1);

namespace Macrolab\Tests\Integration;

use Macrolab\Actor;
use Macrolab\Auth;
use Macrolab\Auth\Identity;
use Macrolab\Booking\BookingService;
use Macrolab\Booking\Equipment;
use Macrolab\Controller\AccountController;
use Macrolab\Controller\BookingApiController;
use Macrolab\Controller\CalendarController;
use Macrolab\Http\Request;
use Macrolab\Http\Response;
use Macrolab\Users;

/**
 * /booking starts with no equipment: an empty, read-only calendar until the
 * member picks one. Nothing is remembered between visits.
 */
final class CalendarPageTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Users::create('kim');
        Auth::signIn(new Identity(netid: 'kim', method: 'test'));
    }

    public function testPlainBookingShowsNoEquipment(): void
    {
        $response = $this->show();

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Choose equipment', $response->body);
        self::assertSame(null, $this->config($response)['equipmentId']);
    }

    public function testNamedEquipmentIsShown(): void
    {
        $piece = Equipment::primary();

        $response = $this->show(['equipment' => $piece['slug']]);

        self::assertStringContainsString((string) $piece['name'], $response->body);
        self::assertSame((int) $piece['id'], $this->config($response)['equipmentId']);

        // The booking dialog names the equipment and whom the booking is for.
        self::assertMatchesRegularExpression(
            '#<dt>Equipment</dt>\s*<dd>' . preg_quote((string) $piece['name'], '#') . '</dd>#',
            $response->body
        );
        self::assertSame('kim', $this->config($response)['userLabel']);
    }

    public function testTheOldMachineParameterNoLongerSelectsAnything(): void
    {
        // ?machine= was dropped when the app switched to "equipment".
        self::assertSame(null, $this->config($this->show(['machine' => Equipment::primary()['slug']]))['equipmentId']);
    }

    public function testTheChoiceIsNotRememberedForTheNextVisit(): void
    {
        $this->show(['equipment' => Equipment::primary()['slug']]);

        self::assertSame(null, $this->config($this->show())['equipmentId']);
    }

    public function testUnknownOrRetiredEquipmentShowsNone(): void
    {
        self::assertSame(null, $this->config($this->show(['equipment' => 'no-such-equipment']))['equipmentId']);

        $retired = Equipment::create('Old microscope');
        Equipment::setActive((int) $retired['id'], false);

        self::assertSame(null, $this->config($this->show(['equipment' => $retired['slug']]))['equipmentId']);
    }

    public function testTheMembersUpcomingBookingsAreListedUnderTheCalendar(): void
    {
        $piece = Equipment::primary();
        $kim = Users::findByNetid('kim');
        // One booking within a day, one that runs into the next morning.
        $this->book((int) $kim?->id, '2030-01-07 09:00', '2030-01-07 12:30');
        $this->book((int) $kim?->id, '2030-01-08 11:00', '2030-01-09 10:00');

        $body = $this->show()->body;

        self::assertStringContainsString('<details class="my-bookings" id="my-bookings" open>', $body);
        self::assertStringContainsString('09:00-12:30', $body);
        self::assertStringContainsString('11:00-Wed 9 Jan 10:00', $body, 'a booking into the next day names its end day');
        self::assertStringContainsString(
            '<a href="/booking?equipment=' . $piece['slug'] . '">' . $piece['name'] . '</a>',
            $body,
            'the equipment links to its calendar'
        );
    }

    public function testTheListCanBeRefreshedOnItsOwn(): void
    {
        $this->book((int) Users::findByNetid('kim')?->id, '2030-01-07 09:00', '2030-01-07 12:30');

        $response = (new BookingApiController())->mine(new Request('GET', '/api/bookings/mine'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('09:00-12:30', $response->body);
    }

    public function testTheAdministratorHasNoListOfTheirOwn(): void
    {
        Auth::logout();
        Auth::completeAdminLogin(1, 'admin');

        self::assertStringNotContainsString('My upcoming bookings', $this->show()->body);
    }

    public function testMyAccountNoLongerListsBookings(): void
    {
        Auth::resetCache();
        $body = (new AccountController())->show(new Request('GET', '/account'))->body;

        self::assertStringNotContainsString('My upcoming bookings', $body);
    }

    /** A confirmed booking in lab time, made by the administrator so no rule applies. */
    private function book(int $userId, string $start, string $end): void
    {
        $zone = new \DateTimeZone('Europe/Amsterdam');
        BookingService::create(
            Actor::forAdmin(),
            (int) Equipment::primary()['id'],
            (new \DateTimeImmutable($start, $zone))->setTimezone(new \DateTimeZone('UTC')),
            (new \DateTimeImmutable($end, $zone))->setTimezone(new \DateTimeZone('UTC')),
            null,
            $userId,
        );
    }

    /** @param array<string, string> $query */
    private function show(array $query = []): Response
    {
        Auth::resetCache();

        return (new CalendarController())->show(new Request('GET', '/booking', query: $query));
    }

    /** @return array<string, mixed> The configuration the page hands to the calendar script. */
    private function config(Response $response): array
    {
        self::assertSame(1, preg_match('/data-config="([^"]*)"/', $response->body, $m), 'the calendar is on the page');

        return json_decode(html_entity_decode($m[1], ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);
    }
}
