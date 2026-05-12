<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Audit partition rollover — extends the rolling window monthly. With
// --months=3 default lookahead, two consecutive failures still leave one
// month of headroom before audit_logs INSERTs start failing for new rows.
Schedule::command('audit:partitions:rollover')
    ->monthlyOn(25, '02:00')
    ->onOneServer()
    ->withoutOverlapping();
