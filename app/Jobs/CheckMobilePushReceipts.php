<?php

namespace App\Jobs;

use App\Models\AppDevice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Log;

/**
 * Fetches the Expo push receipts of a SendMobilePush batch (BRAI-448), dispatched ~15 min after it: a
 * ticket only says Expo accepted the message, the receipt says what APNs / FCM made of it.
 *
 * - POST {ids} to `app-api.push.receipts_url` → `{data: {<ticket id>: {status: ok|error, message, details}}}`.
 *   Ids without a receipt (not ready yet, or older than 24 h) are ignored.
 * - `DeviceNotRegistered` clears the device's push_token, only while it is still the token that was sent
 *   (compared by SHA-256: the payload never carries a token); other errors are logged with the device id
 *   and Expo's code, the message redacted. Tokens are NEVER logged.
 * - 429, 5xx and network errors: released with backoff (1 min, 5 min, 15 min); a sync job gets one
 *   attempt. Other HTTP errors are logged and dropped. Never throws.
 */
class CheckMobilePushReceipts implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 30;

    /**
     * @param  array<string, array{device_id: int, token_hash: string}>  $tickets  ticket id → device + token hash
     */
    public function __construct(public array $tickets) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        if ($this->tickets === []) {
            return;
        }

        try {
            $response = SendMobilePush::expoRequest()->post((string) config('app-api.push.receipts_url'), [
                'ids' => array_map('strval', array_keys($this->tickets)),
            ]);
        } catch (ConnectionException $e) {
            $this->retryOrGiveUp('connection failed: '.SendMobilePush::redact($e->getMessage()));

            return;
        }

        if ($response->status() === 429 || $response->serverError()) {
            $this->retryOrGiveUp('HTTP '.$response->status());

            return;
        }
        $receipts = $response->json('data');
        if (! $response->successful() || ! is_array($receipts)) {
            Log::warning('Mobile push receipts: Expo refused the request', ['status' => $response->status()]);

            return;
        }

        foreach ($this->tickets as $ticketId => $sent) {
            $receipt = $receipts[$ticketId] ?? null;
            if (! is_array($receipt) || ($receipt['status'] ?? null) !== 'error') {
                continue;
            }
            $error = is_string($receipt['details']['error'] ?? null) ? $receipt['details']['error'] : null;

            if ($error === 'DeviceNotRegistered') {
                $device = AppDevice::query()->find($sent['device_id']);
                if ($device && is_string($device->push_token)
                    && hash_equals($sent['token_hash'], SendMobilePush::tokenHash($device->push_token))) {
                    $device->forceFill(['push_token' => null])->save();
                    Log::info('Mobile push: receipt says the device is not registered any more, push token cleared', ['device_id' => $device->id]);
                }

                continue;
            }

            Log::warning('Mobile push: delivery failed (receipt)', [
                'device_id' => $sent['device_id'],
                'error' => $error,
                'message' => SendMobilePush::redact(substr((string) ($receipt['message'] ?? ''), 0, 300)),
            ]);
        }
    }

    private function retryOrGiveUp(string $reason): void
    {
        $queued = $this->job !== null && ! $this->job instanceof SyncJob;
        $attempt = $this->attempts();

        if ($queued && $attempt < $this->tries) {
            $this->release($this->backoff()[$attempt - 1] ?? 900);

            return;
        }

        Log::warning('Mobile push receipts: not fetched', ['reason' => $reason, 'tickets' => count($this->tickets), 'attempts' => $attempt]);
    }
}
