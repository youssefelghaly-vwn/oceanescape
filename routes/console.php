<?php

use App\Console\Commands\ExpireStalePayments;
use App\Console\Commands\SendBalancePaymentLinks;
use Illuminate\Support\Facades\Schedule;

/*
 * NOTE: this file previously scheduled App\Console\Commands\SyncCottageAvailability,
 * a class that does not exist in the repository. Because console routes are loaded on
 * every artisan invocation, that reference broke `migrate`, `test`, `schedule:run` and
 * `config:cache` outright. It has been removed rather than stubbed — see
 * Docs/01-architecture.md D1/D2 for the availability-sync work it belonged to, which is
 * tracked separately and is not part of the payments feature.
 */

/*
 * Balance payment links.
 *
 * Hourly rather than daily so a booking made inside the balance window still gets its
 * link promptly, and so a failed run is retried within the hour instead of the day.
 * The command is idempotent — it only picks up bookings whose balance link has not
 * been sent — so overlapping or repeated runs cannot double-send.
 */
Schedule::command(SendBalancePaymentLinks::class)
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Expire abandoned Stripe checkout sessions so a stale link cannot be paid weeks later
 * against a price we no longer honour.
 */
Schedule::command(ExpireStalePayments::class)
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->onOneServer();
