<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Push browser reminders for upcoming Google Calendar events / Tasks.
Schedule::command('app:send-calendar-reminders')
    ->everyFiveMinutes()
    ->withoutOverlapping();
