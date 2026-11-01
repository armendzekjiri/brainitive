<?php

namespace App\Jobs;

use App\Models\PendingNotificationEmail;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\NotificationDigestNotification;
use App\Support\Notifications\MailDigest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends the notification email digest of one person + workspace once its window has ended (BRAI-429).
 * Queued with a delay by MailDigest::capture() when the window opens.
 *
 * - One sender at a time per person + workspace (cache lock); a busy lock puts the job back.
 * - Claims the due rows atomically with its own token (`UPDATE … WHERE claim_token IS NULL`). The token
 *   is made when the job is queued, so a retry picks up what this job claimed before and no other job
 *   can take those rows: nothing is lost, nothing goes out from two workers.
 * - Rows are deleted only after the mail was handed over. A crash in between can resend on retry
 *   (at-least-once); failing every try releases the claim and the rows go out with the next digest.
 */
class SendNotificationDigest implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public string $claimToken;

    public function __construct(public int $userId, public int $workspaceId)
    {
        $this->claimToken = (string) Str::uuid();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(): void
    {
        $lock = Cache::lock("mail-digest:send:{$this->userId}:{$this->workspaceId}", 120);
        if (! $lock->get()) {
            $this->release(10); // another digest of this person + workspace is being sent

            return;
        }

        try {
            $this->send();
        } finally {
            $lock->release();
        }
    }

    private function send(): void
    {
        $now = now();
        PendingNotificationEmail::query()
            ->where('user_id', $this->userId)
            ->where('workspace_id', $this->workspaceId)
            ->whereNull('claim_token')
            ->where('due_at', '<=', $now)
            ->update(['claim_token' => $this->claimToken, 'claimed_at' => $now]);

        $rows = PendingNotificationEmail::query()->where('claim_token', $this->claimToken)->orderBy('id')->get();
        if ($rows->isEmpty()) {
            $this->waitForWindow();

            return;
        }

        $user = User::find($this->userId);
        $workspace = Workspace::find($this->workspaceId);
        $lines = MailDigest::deliverable($user, $workspace, $rows);

        if ($lines !== [] && $user && $workspace) {
            try {
                $user->notifyNow(new NotificationDigestNotification($workspace, $lines), ['mail']);
            } catch (Throwable $e) {
                Log::warning('Notification email digest could not be sent; it will be retried.', [
                    'user_id' => $this->userId,
                    'workspace_id' => $this->workspaceId,
                    'lines' => count($lines),
                    'attempt' => $this->attempts(),
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }

        PendingNotificationEmail::query()->where('claim_token', $this->claimToken)->delete();
    }

    /**
     * Nothing due yet (the queue ran the job early, e.g. a driver that rounds delays): come back when
     * the window of this person + workspace ends. Never more than one window ahead.
     */
    private function waitForWindow(): void
    {
        $next = PendingNotificationEmail::query()
            ->where('user_id', $this->userId)
            ->where('workspace_id', $this->workspaceId)
            ->whereNull('claim_token')
            ->min('due_at');

        if ($next !== null && $this->job !== null) {
            $this->release(max(1, (int) ceil(now()->diffInSeconds($next, false))));
        }
    }

    public function failed(?Throwable $e): void
    {
        $released = PendingNotificationEmail::query()
            ->where('claim_token', $this->claimToken)
            ->update(['claim_token' => null, 'claimed_at' => null]);

        Log::error('Notification email digest failed; its lines wait for the next digest.', [
            'user_id' => $this->userId,
            'workspace_id' => $this->workspaceId,
            'lines' => $released,
            'error' => $e?->getMessage(),
        ]);
    }
}
