<?php

namespace App\Jobs;

use App\Models\AppDevice;
use App\Models\User;
use App\Support\Notifications\NotificationInbox;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends one notification to ≤ 100 mobile app installs through the Expo push service (BRAI-448).
 * Dispatched by MobilePushChannel with device ids only — tokens stay encrypted in app_devices and are
 * NEVER logged (Expo's error messages quote them, so they are redacted).
 *
 * - The devices are loaded again (still active, `mobile`, with a token): a sign-out meanwhile wins.
 * - Message: {to, title, body, sound: default, badge: unread inbox count, data: {url, notification_id}}.
 * - 429, 5xx and network errors: released with backoff (30 s, 2 min, 10 min) on a real queue; a sync
 *   job gets one attempt. Other HTTP errors are logged and dropped. Never throws.
 * - Tickets: `DeviceNotRegistered` (app uninstalled, token expired) clears that device's push_token,
 *   only while it is still the token that was sent; other ticket errors are logged.
 */
class SendMobilePush implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 30;

    /**
     * @param  list<int>  $deviceIds
     */
    public function __construct(
        public int $userId,
        public array $deviceIds,
        public string $title,
        public string $body,
        public ?string $url,
        public ?string $notificationId,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(): void
    {
        $user = User::find($this->userId);
        if (! $user || ! config('app-api.push.enabled')) {
            return;
        }

        $devices = AppDevice::query()->active()
            ->where('user_id', $user->id)
            ->where('client', 'mobile')
            ->whereNotNull('push_token')
            ->whereIn('id', $this->deviceIds)
            ->orderBy('id')
            ->get()
            ->filter(fn (AppDevice $device) => self::isExpoToken($device->push_token))
            ->values();
        if ($devices->isEmpty()) {
            return;
        }

        $badge = NotificationInbox::unread($user);
        $messages = $devices->map(fn (AppDevice $device) => array_filter([
            'to' => $device->push_token,
            'title' => $this->title,
            'body' => $this->body,
            'sound' => 'default',
            'badge' => $badge,
            'priority' => 'high',
            'data' => ['url' => $this->url, 'notification_id' => $this->notificationId],
        ], fn ($value) => $value !== '' && $value !== null))->all();

        try {
            $response = $this->request()->post((string) config('app-api.push.expo_url'), $messages);
        } catch (ConnectionException $e) {
            $this->retryOrGiveUp('connection failed: '.self::redact($e->getMessage()));

            return;
        }

        if ($response->status() === 429 || $response->serverError()) {
            $this->retryOrGiveUp('HTTP '.$response->status());

            return;
        }
        if (! $response->successful()) {
            Log::warning('Mobile push: Expo refused the request', [
                'status' => $response->status(),
                'errors' => self::errorCodes($response),
                'user_id' => $user->id,
            ]);

            return;
        }

        $this->handleTickets($devices->all(), $response);
    }

    /**
     * Tickets come back in the order of the messages.
     *
     * @param  list<AppDevice>  $devices
     */
    private function handleTickets(array $devices, Response $response): void
    {
        $tickets = $response->json('data');
        if (! is_array($tickets)) {
            return;
        }

        foreach ($devices as $i => $device) {
            $ticket = $tickets[$i] ?? null;
            if (! is_array($ticket) || ($ticket['status'] ?? null) !== 'error') {
                continue;
            }
            $error = is_string($ticket['details']['error'] ?? null) ? $ticket['details']['error'] : null;

            if ($error === 'DeviceNotRegistered') {
                $sent = $device->push_token;
                $device->refresh();
                if ($device->push_token === $sent) {
                    $device->forceFill(['push_token' => null])->save();
                }
                Log::info('Mobile push: device not registered any more, push token cleared', ['device_id' => $device->id]);

                continue;
            }

            Log::warning('Mobile push: Expo rejected a message', [
                'device_id' => $device->id,
                'error' => $error,
                'message' => self::redact((string) ($ticket['message'] ?? '')),
            ]);
        }
    }

    private function request(): PendingRequest
    {
        $request = Http::acceptJson()
            ->asJson()
            ->withHeaders(['Accept-Encoding' => 'gzip, deflate'])
            ->timeout((int) config('app-api.push.timeout', 10))
            ->connectTimeout((int) config('app-api.push.connect_timeout', 5));

        $token = config('app-api.push.expo_access_token');

        return is_string($token) && $token !== '' ? $request->withToken($token) : $request;
    }

    private function retryOrGiveUp(string $reason): void
    {
        $queued = $this->job !== null && ! $this->job instanceof SyncJob;
        $attempt = $this->attempts();

        if ($queued && $attempt < $this->tries) {
            $this->release($this->backoff()[$attempt - 1] ?? 600);

            return;
        }

        Log::warning('Mobile push: not delivered', ['reason' => $reason, 'user_id' => $this->userId, 'attempts' => $attempt]);
    }

    public static function isExpoToken(?string $token): bool
    {
        return is_string($token) && preg_match('/^Expo(nent)?PushToken\[[^\]]+\]$/', $token) === 1;
    }

    /** Expo quotes the token in its messages; a token is a secret. */
    public static function redact(string $text): string
    {
        return (string) preg_replace('/Expo(nent)?PushToken\[[^\]]*\]/', '[push token]', $text);
    }

    /**
     * @return list<string>
     */
    private static function errorCodes(Response $response): array
    {
        $errors = $response->json('errors');

        return is_array($errors)
            ? array_values(array_filter(array_map(fn ($e) => is_array($e) && is_string($e['code'] ?? null) ? $e['code'] : null, $errors)))
            : [];
    }
}
