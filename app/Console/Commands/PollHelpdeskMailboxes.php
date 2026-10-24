<?php

namespace App\Console\Commands;

use App\Models\HelpdeskMailbox;
use App\Support\Helpdesks\Mail\MailboxPoller;
use Illuminate\Console\Command;

/**
 * Imports unseen mail of every enabled helpdesk mailbox (v1.40.0, BRAI-79). Scheduled every minute
 * (routes/console.php); safe to run by hand and idempotent (dedupe per helpdesk + Message-ID, a lock
 * per mailbox). Mailboxes that keep failing are retried with a growing pause (MailboxPoller::due).
 */
class PollHelpdeskMailboxes extends Command
{
    protected $signature = 'app:poll-helpdesk-mailboxes {--helpdesk= : Only this helpdesk id} {--force : Ignore the backoff after failures}';

    protected $description = 'Import new email from the helpdesks\' connected mailboxes as tickets and replies';

    public function handle(MailboxPoller $poller): int
    {
        $mailboxes = HelpdeskMailbox::where('enabled', true)
            ->whereHas('helpdesk')   // not soft-deleted
            ->when($this->option('helpdesk'), fn ($q, $id) => $q->where('helpdesk_id', (int) $id))
            ->orderBy('id')
            ->get();

        foreach ($mailboxes as $mailbox) {
            if (! $this->option('force') && ! MailboxPoller::due($mailbox)) {
                continue;
            }

            $summary = $poller->poll($mailbox);
            $results = collect($summary['results'])->map(fn ($n, $r) => "{$r}: {$n}")->implode(', ');
            $this->line("Helpdesk {$mailbox->helpdesk_id}: {$summary['processed']} message(s)".($results ? " ({$results})" : '').($summary['error'] ? " — error: {$summary['error']}" : ''));
        }

        return self::SUCCESS;
    }
}
