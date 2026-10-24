<?php

namespace App\Console\Commands;

use App\Support\Billing\LegacyTimeBackfill;
use Illuminate\Console\Command;

/**
 * Turns work logged before the time ledger (v1.38.0) into `legacy` time entries. Runs once from
 * the 2026_10_27_120000 migration; safe to run again (it only adds what is missing).
 */
class BackfillLegacyTimeEntries extends Command
{
    protected $signature = 'time-entries:backfill-legacy';

    protected $description = 'Create legacy time entries for work logged before the time ledger existed (idempotent)';

    public function handle(): int
    {
        $created = LegacyTimeBackfill::run();
        $this->info($created === 0 ? 'Nothing to backfill.' : "Created {$created} legacy time entries.");

        return self::SUCCESS;
    }
}
