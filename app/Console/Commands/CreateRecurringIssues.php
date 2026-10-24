<?php

namespace App\Console\Commands;

use App\Models\RecurringIssue;
use App\Support\RecurringIssues\RecurringIssueRunner;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Creates the due occurrences of recurring issues (v1.40.0, BRAI-123). Safe to run by hand and as
 * often as you like: idempotent per (template, occurrence date), and after downtime only the latest
 * missed occurrence of each template is created. Rules in RecurringIssueRunner.
 */
class CreateRecurringIssues extends Command
{
    protected $signature = 'app:create-recurring-issues {--template= : Only this template (id)}';

    protected $description = 'Create the issues of recurring issue templates that are due';

    public function handle(): int
    {
        $now = now();
        // the local date is at most one day ahead of UTC (UTC+14): anything due later can wait
        $horizon = $now->copy()->utc()->addDay()->toDateString();
        $created = 0;
        $failed = 0;

        RecurringIssue::query()
            ->whereNull('paused_at')
            ->whereNotNull('next_occurrence_date')
            ->whereDate('next_occurrence_date', '<=', $horizon)
            ->when($this->option('template'), fn ($q, $id) => $q->whereKey((int) $id))
            ->chunkById(100, function (Collection $templates) use ($now, &$created, &$failed) {
                foreach ($templates as $template) {
                    try {
                        if (RecurringIssueRunner::run($template, $now)) {
                            $created++;
                        }
                    } catch (\Throwable $e) {
                        // one broken template must not stop the others
                        report($e);
                        $failed++;
                        RecurringIssue::whereKey($template->id)->update(['last_error' => Str::limit($e->getMessage(), 490)]);
                    }
                }
            });

        $this->info("Created {$created} recurring issue(s).".($failed ? " {$failed} failed." : ''));

        return self::SUCCESS;
    }
}
