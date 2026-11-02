<?php

namespace App\Http\Controllers;

use App\Support\AppApi\AppClients;
use App\Support\AppApi\AppDeviceCodes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * /app/device (BRAI-438): the user confirms the code a TV shows (RFC 8628 device-code sign-in;
 * the JSON half is App\Http\Controllers\AppApi\DeviceAuthController). Signed-in, verified web
 * session; the same consent look as /app/authorize.
 *  - GET without a code: a field to type it. GET `?code=` (typed, or the TV's QR =
 *    `verification_uri_complete`): the code, device name, client and platform, with Approve / Deny.
 *    A GET never approves anything.
 *  - POST {user_code, decision}: the click. Approve or deny once; the code is then gone.
 * Brute force: `throttle:app-device-entry` on both, plus at most 10 wrong codes per user in 15 minutes.
 */
class AppDeviceApprovalController extends Controller
{
    private const PAGE = 'AppAuth/Device';

    private const MAX_FAILURES = 10;

    private const FAILURE_DECAY = 15 * 60;

    private const PLATFORM_LABELS = ['android_tv' => 'Android TV'];

    public function show(Request $request): Response
    {
        $code = $request->query('code');
        if (! is_string($code) || trim($code) === '') {
            return $this->render($request, ['stage' => 'enter']);
        }

        if ($blocked = $this->blocked($request, $code)) {
            return $blocked;
        }

        $payload = AppDeviceCodes::pending($code);
        if ($payload === null) {
            return $this->wrongCode($request, $code);
        }

        return $this->render($request, [
            'stage' => 'confirm',
            'code' => AppDeviceCodes::format($payload['user_code']),
            'client' => ['name' => AppClients::name($payload['client'])],
            'device' => [
                'name' => $payload['device']['name'],
                'platform' => self::PLATFORM_LABELS[$payload['device']['platform']] ?? $payload['device']['platform'],
            ],
            // where and when the TV asked (BRAI-377): a code from a stranger's device stands out
            'origin' => [
                'ip' => AppDeviceCodes::coarseIp(is_string($payload['ip'] ?? null) ? $payload['ip'] : null),
                'minutes_ago' => is_int($payload['created_at'] ?? null) ? max(0, intdiv(now()->getTimestamp() - $payload['created_at'], 60)) : null,
            ],
        ]);
    }

    public function store(Request $request): Response
    {
        $validated = $request->validate([
            'user_code' => ['required', 'string', 'max:32'],
            'decision' => ['required', 'string', 'in:approve,deny'],
        ]);

        if ($blocked = $this->blocked($request, $validated['user_code'])) {
            return $blocked;
        }

        $approve = $validated['decision'] === 'approve';
        $payload = AppDeviceCodes::decide($validated['user_code'], $request->user(), $approve);
        if ($payload === null) {
            return $this->wrongCode($request, $validated['user_code']);
        }

        return $this->render($request, [
            'stage' => $approve ? 'approved' : 'denied',
            'client' => ['name' => AppClients::name($payload['client'])],
            'device' => ['name' => $payload['device']['name'], 'platform' => null],
        ]);
    }

    /** Too many wrong codes from this user: a 429 page instead of another lookup. */
    private function blocked(Request $request, string $code): ?Response
    {
        if (! RateLimiter::tooManyAttempts($this->failureKey($request), self::MAX_FAILURES)) {
            return null;
        }

        $minutes = max(1, (int) ceil(RateLimiter::availableIn($this->failureKey($request)) / 60));

        return $this->render($request, [
            'stage' => 'enter',
            'typed' => $code,
            'error' => "Too many wrong codes. Try again in {$minutes} minute".($minutes === 1 ? '' : 's').'.',
        ], 429);
    }

    private function wrongCode(Request $request, string $code): Response
    {
        RateLimiter::hit($this->failureKey($request), self::FAILURE_DECAY);

        return $this->render($request, [
            'stage' => 'enter',
            'typed' => $code,
            'error' => 'This code is not valid. It may have expired or already been used: check the code on your TV, or start signing in there again.',
        ], 422);
    }

    private function failureKey(Request $request): string
    {
        return 'app-device-failures:'.$request->user()->id;
    }

    /** @param  array<string, mixed>  $props */
    private function render(Request $request, array $props, int $status = 200): Response
    {
        return Inertia::render(self::PAGE, $props + ['email' => $request->user()->email])
            ->toResponse($request)
            ->setStatusCode($status);
    }
}
