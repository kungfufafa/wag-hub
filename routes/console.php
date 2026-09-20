<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('gateway:recover-stale --minutes=10 --limit=100')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('gateway:cleanup-attachments')
    ->daily()
    ->withoutOverlapping();

Schedule::command('engine:control-tick')->everyFifteenSeconds()->withoutOverlapping();
Schedule::command('engine:transport-tick')->everyFiveSeconds()->withoutOverlapping();
Schedule::command('engine:transport-tick --cleanup')->daily()->withoutOverlapping();
