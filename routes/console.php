<?php

use App\Jobs\RunImport;
use App\Models\AutomationRun;
use App\Models\DataExport;
use App\Models\GithubAppDelivery;
use App\Models\GithubDelivery;
use App\Models\HelpdeskInboundEmail;
use App\Models\ImportRun;
use App\Models\InboxNotification;
use App\Models\LeadFormEvent;
use App\Models\McpRequest;
use App\Models\OfflineMutation;
use App\Models\PortalLoginToken;
use App\Models\ProjectExport;
use App\Models\PushSubscription;
use App\Models\WebhookDelivery;
use App\Support\GithubStars;
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

// Booking pages (BRAI-158): email guests a reminder about a day before their booking.
Schedule::command('app:send-booking-reminders')
    ->everyFifteenMinutes()
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
Schedule::command('model:prune', ['--model' => [McpRequest::class, GithubDelivery::class, GithubAppDelivery::class, LeadFormEvent::class, WebhookDelivery::class]])->daily();

// Inbox (BRAI-102): archived notifications after 90 days, every notification after 180 days.
Schedule::command('model:prune', ['--model' => [InboxNotification::class]])->dailyAt('03:30');

// ── v1.40.0: one block per ticket (docs/ai/tasks/release-v1.40.0.md) ─────────
// Ticket 1 (automation): scheduled triggers such as "due date passed".
Schedule::command('app:run-due-date-automations')
    ->everyFiveMinutes()
    ->withoutOverlapping();
// The automation run log keeps 30 days.
Schedule::command('model:prune', ['--model' => [AutomationRun::class]])
    ->dailyAt('03:40')
    ->withoutOverlapping();

// Ticket 2 (recurring issues): create the issues that are due.
// Occurrences are due from local midnight in each template's time zone; idempotent, catch-up = latest only.
Schedule::command('app:create-recurring-issues')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Ticket 3 (email → helpdesk, IMAP): poll connected mailboxes; forget inbound dedupe rows after 180 days.
Schedule::command('app:poll-helpdesk-mailboxes')
    ->everyMinute()
    ->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [HelpdeskInboundEmail::class]])->dailyAt('03:45');

// Ticket 5 (helpdesk SLA): warn before and on breaches.
Schedule::command('app:check-helpdesk-sla')
    ->everyMinute()
    ->withoutOverlapping();

// Bring your data (v1.41.0): uploaded import files are kept 7 days (enough to retry), runs 180 days.
Schedule::call(function () {
    ImportRun::query()->whereNotNull('file_path')->whereNotIn('status', [ImportRun::QUEUED, ImportRun::RUNNING])
        ->where('updated_at', '<', now()->subDays(7))
        ->each(function (ImportRun $run) {
            RunImport::deleteFiles($run);
            $run->forceFill(['file_path' => null])->save();
        });
})->name('imports:cleanup-files')->dailyAt('04:10')->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [ImportRun::class]])->dailyAt('04:20');

// Project exports (BRAI-81): the ZIP can be downloaded for 24 h, then it's deleted; rows go after 30 days.
Schedule::call(function () {
    ProjectExport::query()->whereNotNull('file_path')->where('expires_at', '<', now())->each(fn (ProjectExport $export) => $export->expire());
    // an export that never finished (a lost worker) leaves its work folder behind
    ProjectExport::query()->whereIn('status', [ProjectExport::QUEUED, ProjectExport::RUNNING])->where('updated_at', '<', now()->subDay())
        ->each(fn (ProjectExport $export) => $export->expire());
    // "Download my data" (BRAI-156): the same 24 h life
    // lazyById: expire() changes the filtered columns, an offset walk would skip rows
    DataExport::query()->whereNotNull('file_path')->where('expires_at', '<', now())->lazyById()->each(fn (DataExport $export) => $export->expire());
    DataExport::query()->whereIn('status', [DataExport::QUEUED, DataExport::RUNNING])->where('updated_at', '<', now()->subDay())
        ->lazyById()->each(fn (DataExport $export) => $export->expire());
})->name('exports:cleanup')->hourly()->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [ProjectExport::class, DataExport::class]])->dailyAt('04:25');

// v1.42.0: spent / expired guest sign-in links
Schedule::command('model:prune', ['--model' => [PortalLoginToken::class]])->dailyAt('04:30');

// v1.42.0: the repository's star count for the "Star on GitHub" buttons (cache only; pages never call GitHub)
Schedule::call(fn () => GithubStars::refresh())->hourly()->name('github-stars')->withoutOverlapping();

// Offline sync (BRAI-82): forget the outcome of a device's change after 30 days.
Schedule::command('model:prune', ['--model' => [OfflineMutation::class]])->dailyAt('04:35');
// Web push (BRAI-154): forget subscriptions the push service reported as expired 30 days ago.
Schedule::command('model:prune', ['--model' => [PushSubscription::class]])->dailyAt('04:40');
// Retention (BRAI-156, docs/legal/data-inventory.md): expired password-reset tokens, revoked / expired
// OAuth tokens and codes (after Passport's 7-day grace), failed queue jobs (which hold payloads) after 30 days.
Schedule::command('auth:clear-resets')->dailyAt('04:45');
Schedule::command('passport:purge')->dailyAt('04:50');
Schedule::command('queue:prune-failed', ['--hours' => 720])->dailyAt('04:55');
// v1.43.0 (BRAI-55): build the search index in the background when this index version was never
// built (fresh install, update) — nobody runs search:reindex by hand. One small query otherwise.
Schedule::command('search:ensure')->everyFiveMinutes()->withoutOverlapping()->runInBackground();
// v1.46.0 (Cloud plans, BRAI-160): measure what each workspace uses (content / files for LimitGate and
// the usage bars), then prune doc / plan versions past the plan's history. Both do nothing self-hosted.
Schedule::command('usage:measure')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('versions:prune')->dailyAt('02:50')->withoutOverlapping();
