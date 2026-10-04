<?php

declare(strict_types=1);

namespace Macrolab\Tests\Unit;

use Macrolab\Clock;
use Macrolab\Config;
use PHPUnit\Framework\TestCase;

final class ClockTest extends TestCase
{
    protected function setUp(): void
    {
        Config::set(['app' => ['display_timezone' => 'Europe/Amsterdam']]);
    }

    protected function tearDown(): void
    {
        Clock::unfreeze();
    }

    public function testFreezeMakesNowDeterministic(): void
    {
        Clock::freeze('2026-09-14 08:30:00');

        self::assertSame('2026-09-14 08:30:00', Clock::sql());
        self::assertSame('2026-09-14 08:30:00', Clock::sql(), 'and it does not move');
    }

    public function testBareTimesAreReadInTheLabTimezone(): void
    {
        // 09:00 in Amsterdam in September is 07:00 UTC.
        $instant = Clock::parseInstant('2026-09-14 09:00');

        self::assertNotNull($instant);
        self::assertSame('2026-09-14 07:00:00', Clock::sql($instant));
    }

    public function testTimesWithAnOffsetAreTakenAtFaceValue(): void
    {
        self::assertSame('2026-09-14 07:00:00', Clock::sql(Clock::parseInstant('2026-09-14T09:00:00+02:00')));
        self::assertSame('2026-09-14 09:00:00', Clock::sql(Clock::parseInstant('2026-09-14T09:00:00Z')));
    }

    public function testOffsetWithoutAColonIsAlsoUnderstood(): void
    {
        self::assertSame('2026-09-14 07:00:00', Clock::sql(Clock::parseInstant('2026-09-14T09:00:00+0200')));
    }

    public function testRubbishIsRejectedRatherThanGuessed(): void
    {
        self::assertNull(Clock::parseInstant(''));
        self::assertNull(Clock::parseInstant('   '));
        self::assertNull(Clock::parseInstant('not a date'));
    }

    public function testLocalRendersInTheLabTimezone(): void
    {
        $instant = Clock::fromSql('2026-09-14 07:00:00');

        self::assertSame('2026-09-14 09:00', Clock::local($instant));
        self::assertSame('09:00', Clock::local($instant, 'H:i'));
    }

    public function testWinterTimeRendersAnHourDifferently(): void
    {
        // Same UTC hour, but CET rather than CEST.
        self::assertSame('08:00', Clock::local(Clock::fromSql('2026-12-14 07:00:00'), 'H:i'));
    }

    public function testDurationsReadAsWords(): void
    {
        self::assertSame('30 minutes', Clock::humanDuration(30));
        self::assertSame('1 hour', Clock::humanDuration(60));
        self::assertSame('4 hours', Clock::humanDuration(240));
        self::assertSame('2 hours 30 minutes', Clock::humanDuration(150));
        self::assertSame('1 day', Clock::humanDuration(24 * 60));
        self::assertSame('3 days', Clock::humanDuration(3 * 24 * 60));
        // Not a round number of days, so it stays in hours.
        self::assertSame('25 hours', Clock::humanDuration(25 * 60));
    }

    public function testDatesAreReadDayFirstOrAsIso(): void
    {
        foreach (['08-10-2026', '8-10-2026', '08/10/2026', '8.10.2026', '2026-10-08'] as $typed) {
            self::assertSame('2026-10-08', Clock::isoDate($typed), $typed . ' is 8 October');
        }
    }

    public function testImpossibleOrAmbiguousDatesAreRefused(): void
    {
        foreach (['30-02-2025', '10/32/2026', '2026-13-01', '08-10-26', 'tomorrow', ''] as $typed) {
            self::assertNull(Clock::isoDate($typed), var_export($typed, true) . ' is refused');
        }
    }

    public function testIsoDatesAreShownDayFirst(): void
    {
        self::assertSame('08-10-2026', Clock::dmy('2026-10-08'));
    }
}
