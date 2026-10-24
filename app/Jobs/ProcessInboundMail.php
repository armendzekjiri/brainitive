<?php

namespace App\Jobs;

use App\Models\Helpdesk;
use App\Support\Helpdesks\Inbound\InboundMail;
use App\Support\Helpdesks\Inbound\InboundMessage;
use App\Support\Helpdesks\Inbound\InboundTempFiles;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hands one mail received on a forwarding address (BRAI-124) to the shared pipeline,
 * InboundMail::receive() (dedupe, threading, ticket / reply), then removes its temp attachments.
 * Queued so the provider's webhook gets its answer at once; runs inline on a sync queue.
 * A retry is safe: receive() dedupes on (helpdesk, Message-ID).
 */
class ProcessInboundMail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(
        public int $helpdeskId,
        public InboundMessage $message,
        public ?string $tempDir = null,
    ) {}

    public function handle(): void
    {
        $helpdesk = Helpdesk::find($this->helpdeskId);
        if ($helpdesk) {
            $result = InboundMail::receive($helpdesk, $this->message);
            Log::info('Inbound mail received', ['transport' => 'forwarding', 'helpdesk' => $helpdesk->id, 'result' => $result]);
        }

        InboundTempFiles::delete($this->tempDir);
    }

    public function failed(?Throwable $exception): void
    {
        InboundTempFiles::delete($this->tempDir);
    }
}
