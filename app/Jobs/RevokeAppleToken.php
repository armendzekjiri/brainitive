<?php

namespace App\Jobs;

use App\Support\Auth\AppleLogin;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Revokes a deleted account's Sign in with Apple authorization (App Store guideline 5.1.1(v)):
 * POST https://appleid.apple.com/auth/revoke with the Services ID, the ES256 client secret
 * (AppleLogin::clientSecret) and the refresh token stored at sign-in. Dispatched by
 * UserDeletion::delete after the user row is gone, so the token travels in the payload —
 * ShouldBeEncrypted keeps it encrypted in the queue. The token and the secret are NEVER logged.
 *
 * - 200: revoked. 429, 5xx and network errors: released with backoff (1 min, 5 min, 30 min, 2 h) on
 *   a real queue; a sync job gets one attempt. Other answers (invalid_grant: already revoked or
 *   expired) and an unconfigured / broken Apple setup: logged and dropped. Never throws — the
 *   account is deleted either way.
 */
class RevokeAppleToken implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(
        public int $userId,
        #[\SensitiveParameter] public string $refreshToken,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 1800, 7200];
    }

    public function handle(AppleLogin $apple): void
    {
        if (! AppleLogin::available()) {
            Log::warning('Apple revoke skipped: Sign in with Apple is not configured any more', ['user_id' => $this->userId]);

            return;
        }

        try {
            $secret = $apple->clientSecret();
        } catch (Throwable) {
            Log::error('Apple revoke skipped: the client secret could not be signed (check APPLE_PRIVATE_KEY / APPLE_KEY_ID)', ['user_id' => $this->userId]);

            return;
        }

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(20)
                ->post(AppleLogin::REVOKE_URL, [
                    'client_id' => (string) config('services.apple.client_id'),
                    'client_secret' => $secret,
                    'token' => $this->refreshToken,
                    'token_type_hint' => 'refresh_token',
                ]);
        } catch (ConnectionException) {
            $this->retryOrGiveUp('connection failed');

            return;
        }

        if ($response->status() === 429 || $response->serverError()) {
            $this->retryOrGiveUp('HTTP '.$response->status());

            return;
        }

        if (! $response->successful()) {
            $error = $response->json('error');
            Log::warning('Apple revoke refused', [
                'user_id' => $this->userId,
                'status' => $response->status(),
                'error' => is_string($error) ? substr($error, 0, 80) : null,
            ]);

            return;
        }

        Log::info('Apple authorization revoked for a deleted account', ['user_id' => $this->userId]);
    }

    private function retryOrGiveUp(string $reason): void
    {
        $queued = $this->job !== null && ! $this->job instanceof SyncJob;
        $attempt = $this->attempts();

        if ($queued && $attempt < $this->tries) {
            $this->release($this->backoff()[$attempt - 1] ?? 7200);

            return;
        }

        Log::warning('Apple revoke failed', ['reason' => $reason, 'user_id' => $this->userId, 'attempts' => $attempt]);
    }
}
