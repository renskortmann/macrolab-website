<?php

declare(strict_types=1);

/**
 * One half of the double-booking test: attempt a booking for a fixed slot and
 * report what happened. Two of these run at once - see concurrency.sh - and
 * exactly one must succeed.
 *
 * This exercises the row lock in BookingService directly, from two separate
 * processes with separate connections, which is the situation the lock exists
 * for. It does not need a web server.
 *
 *   php tests/concurrency.php <netid>
 */

use Macrolab\Actor;
use Macrolab\Booking\BookingException;
use Macrolab\Config;
use Macrolab\Db;
use Macrolab\Booking\Equipment;
use Macrolab\Users;

require dirname(__DIR__) . '/vendor/autoload.php';

Config::set(require __DIR__ . '/test-config.php');
date_default_timezone_set('UTC');
Db::init((array) Config::get('db'));

$netid = $argv[1] ?? 'alice';
$user = Users::findByNetid($netid);

if ($user === null) {
    fwrite(STDERR, "ERROR unknown netid {$netid}\n");
    exit(2);
}

$start = new DateTimeImmutable('2026-09-14 09:00:00', new DateTimeZone('UTC'));
$end = new DateTimeImmutable('2026-09-14 10:00:00', new DateTimeZone('UTC'));

try {
    $booking = \Macrolab\Booking\BookingService::create(
        Actor::forAdmin(),      // the admin path skips the rule checks, not the overlap check
        Equipment::primaryId(),
        $start,
        $end,
        'concurrency probe',
        $user->id,
    );

    echo "OK {$booking->id}\n";
    exit(0);
} catch (BookingException $e) {
    echo "CONFLICT {$e->getMessage()}\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR ' . $e::class . ': ' . $e->getMessage() . "\n");
    exit(1);
}
