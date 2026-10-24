<?php

use App\Models\GithubDelivery;
use App\Models\InboxNotification;
use App\Models\LeadFormEvent;
use App\Models\McpRequest;
use App\Models\WebhookDelivery;
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

// Push bell + browser reminders for board / task-list items reaching their start time.
Schedule::command('app:send-item-reminders')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Morning summary (from 08:00 in each user's time zone) of list tasks due today / overdue.
Schedule::command('app:send-task-digest')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Forget MCP idempotency keys after 7 days; webhook deliveries after 30.
Schedule::command('model:prune', ['--model' => [McpRequest::class, GithubDelivery::class, LeadFormEvent::class, WebhookDelivery::class]])->daily();

// Inbox (BRAI-102): archived notifications after 90 days, every notification after 180 days.
Schedule::command('model:prune', ['--model' => [InboxNotification::class]])->dailyAt('03:30');
