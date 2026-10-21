<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Restart any dead copy-trade listeners every minute.
// This is the self-healing mechanism — if a listener job crashes or the queue
// worker was restarted, this ensures it comes back within 60 seconds.
Schedule::command('deriv:ensure-listeners')->everyMinute()->withoutOverlapping();

// Renew Deriv OAuth tokens before their one-hour lifetime runs out.
Schedule::command('deriv:refresh-tokens')->everyFiveMinutes()->withoutOverlapping(10);

// Settle copy trades SettleCopyTradeJob could not, e.g. contracts longer than its retries.
Schedule::command('copy-trades:settle')->everyMinute()->withoutOverlapping(5);
