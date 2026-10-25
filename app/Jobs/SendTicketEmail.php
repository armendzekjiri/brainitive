<?php

namespace App\Jobs;

use App\Models\Ticket;
use App\Models\TicketReply;
use App\Support\Helpdesks\Mail\MailboxException;
use App\Support\Helpdesks\Mail\TicketMailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Throwable;

/**
 * Sends one ticket email through the helpdesk's mailbox (v1.40.0, BRAI-79): an agent reply
 * ($replyId) or the acknowledgment of an email-created ticket (no reply). The Message-ID was fixed
 * when the job was queued, so a retry sends the same message. Retried 3× with backoff on a real
 * queue; on a sync queue one attempt. A reply that finally fails is marked "failed" with the error
 * (the ticket dialog offers "Retry"). Never throws.
 */
class SendTicketEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $ticketId, public ?int $replyId, public string $messageId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        $ticket = Ticket::find($this->ticketId);
        $reply = $this->replyId ? TicketReply::find($this->replyId) : null;
        if (! $ticket || ($this->replyId && (! $reply || $reply->email_status === 'sent'))) {
            return;
        }

        try {
            TicketMailer::send($ticket, $reply, $this->messageId);
            $reply?->forceFill(['email_status' => 'sent', 'email_error' => null, 'email_sent_at' => now()])->save();
        } catch (Throwable $e) {
            $error = MailboxException::from($e)->getMessage();
            $final = $this->job === null || $this->job instanceof SyncJob || $this->attempts() >= $this->tries;
            if (! $final) {
                $reply?->forceFill(['email_error' => $error])->save();
                $this->release($this->backoff()[$this->attempts() - 1] ?? 300);

                return;
            }
            $reply?->forceFill(['email_status' => 'failed', 'email_error' => $error])->save();
            if (! $reply) {
                report($e);
            }
        }
    }
}
