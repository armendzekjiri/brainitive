<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Support\Push\DeviceLabel;
use App\Support\Push\PushTester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    /**
     * Store (or refresh) the browser's push subscription for the current user.
     * Called by the frontend after PushManager.subscribe() succeeds.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048', function (string $attribute, mixed $value, \Closure $fail) {
                if (! self::isPushService((string) $value)) {
                    $fail('Unsupported push service.');
                }
            }],
            'keys.p256dh' => 'required|string|max:255',
            'keys.auth' => 'required|string|max:255',
            'contentEncoding' => 'nullable|in:aes128gcm,aesgcm',
        ]);

        $subscription = $request->user()->updatePushSubscription(
            $data['endpoint'],
            $data['keys']['p256dh'],
            $data['keys']['auth'],
            $data['contentEncoding'] ?? null,
        );

        // BRAI-154: remember which browser this is; a (re-)registration clears an old expiry flag
        $userAgent = mb_substr((string) $request->userAgent(), 0, 255);
        $subscription->forceFill([
            'user_agent' => $userAgent !== '' ? $userAgent : null,
            'device_label' => mb_substr((string) DeviceLabel::label($userAgent), 0, 100) ?: null,
            'expired_at' => null,
        ])->save();

        return response()->json(['ok' => true]);
    }

    /**
     * Profile → Notifications device list (BRAI-154): the current user's subscriptions, newest
     * first. Endpoints and keys never leave the server; `endpoint_hash` (SHA-256 of the endpoint)
     * lets the browser recognise its own row ("This device").
     */
    public function index(Request $request): JsonResponse
    {
        $devices = $request->user()->pushSubscriptions()->latest('id')->get()
            ->map(fn (PushSubscription $subscription) => self::present($subscription))
            ->values();

        return response()->json(['data' => $devices]);
    }

    /** Remove one of the current user's devices by id (another user's id is a 404). */
    public function remove(Request $request, int $subscription): JsonResponse
    {
        $request->user()->pushSubscriptions()->whereKey($subscription)->firstOrFail()->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Send a test push now (not queued) to exactly one of the current user's devices and return
     * what the push service answered (`PushTester` / `PushOutcome`) with the refreshed device.
     */
    public function test(Request $request, int $subscription, PushTester $tester): JsonResponse
    {
        /** @var PushSubscription $device */
        $device = $request->user()->pushSubscriptions()->whereKey($subscription)->firstOrFail();

        $outcome = $tester->send($device);

        return response()->json([
            'result' => $outcome->toArray(),
            'device' => self::present($device->refresh()),
        ]);
    }

    /** @return array<string, mixed> */
    public static function present(PushSubscription $subscription): array
    {
        $parsed = DeviceLabel::parse($subscription->user_agent);
        $service = DeviceLabel::service($subscription->endpoint);

        return [
            'id' => $subscription->id,
            'endpoint_hash' => hash('sha256', $subscription->endpoint),
            'label' => $subscription->device_label ?: 'Unknown browser',
            'browser' => $parsed['browser'],
            'os' => $parsed['os'],
            'platform' => $parsed['platform'],
            'service' => $service['name'],
            'service_host' => $service['host'],
            'created_at' => $subscription->created_at?->toIso8601String(),
            'last_success_at' => $subscription->last_success_at?->toIso8601String(),
            'last_error' => $subscription->last_error,
            'last_error_at' => $subscription->last_error_at?->toIso8601String(),
            'expired_at' => $subscription->expired_at?->toIso8601String(),
        ];
    }

    /**
     * Remove a push subscription (user toggled browser notifications off, or
     * the browser reported the subscription is no longer valid).
     */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => 'required|string',
        ]);

        $request->user()->deletePushSubscription($data['endpoint']);

        return response()->json(['ok' => true]);
    }

    /** Browser push services (Chrome/Edge FCM, Firefox, Safari, Windows). Anything else is refused. */
    public const PUSH_HOSTS = ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com', '*.push.apple.com', '*.notify.windows.com', 'android.googleapis.com'];

    public static function isPushService(string $url): bool
    {
        $parts = parse_url($url);
        if (! $parts || strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['port'])) {
            return false;
        }
        $host = strtolower($parts['host'] ?? '');
        foreach (self::PUSH_HOSTS as $allowed) {
            if ($host === $allowed || (str_starts_with($allowed, '*.') && str_ends_with($host, substr($allowed, 1)))) {
                return true;
            }
        }

        return false;
    }
}
