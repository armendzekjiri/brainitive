<?php

namespace App\Jobs;

use App\Models\AutomationRun;
use App\Support\Automation\AutomationRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one automation rule for one event (v1.40.0, BRAI-63). Dispatched after the change committed;
 * on the sync queue (dev, e2e, small installs) it runs inline at the end of the request. A retry
 * continues where the crashed attempt stopped (AutomationRunner skips finished actions).
 */
class RunAutomation implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $runId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(): void
    {
        if (! AutomationRunner::run($this->runId) && $this->job) {
            $this->release(AutomationRunner::LOCK_SECONDS); // another worker holds this run
        }
    }

    public function failed(?Throwable $e): void
    {
        $run = AutomationRun::find($this->runId);
        if ($run && ! $run->isFinished()) {
            AutomationRunner::finish($run, AutomationRun::ERROR, 'The run stopped unexpectedly. Actions that finished are listed; the rest did not run.');
        }
    }
}
