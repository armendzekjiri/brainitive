<?php

namespace App\Console\Commands;

use App\Support\Automation\DueDateTrigger;
use Illuminate\Console\Command;

/**
 * Starts the "Due date passed" automation rules (v1.40.0, BRAI-63). Safe to run by hand and as often
 * as you like: each issue starts a rule once per due date (see DueDateTrigger).
 */
class RunDueDateAutomations extends Command
{
    protected $signature = 'app:run-due-date-automations';

    protected $description = 'Run "Due date passed" automation rules for issues whose due date has passed';

    public function handle(): int
    {
        $created = DueDateTrigger::run();
        $this->info("Started {$created} automation ".($created === 1 ? 'run' : 'runs').'.');

        return self::SUCCESS;
    }
}
