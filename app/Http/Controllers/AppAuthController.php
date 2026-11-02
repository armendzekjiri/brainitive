<?php

namespace App\Http\Controllers;

use App\Models\AppDevice;
use App\Support\AppApi\AppAuthCodes;
use App\Support\AppApi\AppClients;
use App\Support\AppApi\AppWebSessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The browser half of the native sign-in (BRAI-355 / BRAI-383, contract in
 * docs/ai/tasks/release-v1.49.0.md). Routes: routes/app-auth.php.
 *  - /app/authorize: the system browser, signed in (any login method returns here through
 *    url.intended), asks "Sign in to Brainitive Desktop as …?"; Continue sends a 60-second,
 *    PKCE-bound code to the app's redirect (brainitive://auth/callback), Cancel sends
 *    `error=access_denied`. Invalid parameters never redirect to the app.
 *  - /app/session/{code}: the app's webview turns a one-time code (POST /api/v1/auth/web-session)
 *    into a remembered web session, bound to the device; a confirm page whenever that would replace
 *    another account or happen in a plain browser (BRAI-377).
 */
class AppAuthController extends Controller
{
    private const PAGE = 'AppAuth/Authorize';

    private const SESSION_PAGE = 'AppAuth/Session';

    /** Session key of a webview sign-in waiting for its confirm click. */
    private const PENDING = 'app_session.pending';

    /** How long the confirm page of a webview link stays valid (the link itself: 60 s, single use). */
    private const PENDING_TTL = 5 * 60;

    public function show(Request $request): Response
    {
        $params = $this->params($request->query());
        if (is_string($params)) {
            return $this->invalid($request, $params);
        }

        return Inertia::render(self::PAGE, [
            'request' => $params,
            'client' => ['name' => $this->client($params['client'])['name']],
            'email' => $request->user()->email,
        ])->toResponse($request);
    }

    /** Continue / Cancel. Inertia::location: the browser itself navigates to the custom scheme. */
    public function store(Request $request): Response
    {
        $params = $this->params($request->except('decision'));
        if (is_string($params)) {
            return $this->invalid($request, $params);
        }

        if ($request->input('decision') === 'approve') {
            $code = AppAuthCodes::issue(AppAuthCodes::EXCHANGE, [
                'user_id' => $request->user()->id,
                'client' => $params['client'],
                'code_challenge' => $params['code_challenge'],
                'device_name' => $params['device_name'] ?? null,
            ]);
            $query = ['code' => $code, 'state' => $params['state']];
        } else {
            $query = ['error' => 'access_denied', 'state' => $params['state']];
        }

        $redirect = (string) $this->client($params['client'])['redirect'];

        return Inertia::location($redirect.(str_contains($redirect, '?') ? '&' : '?').http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    /**
     * The webview's one-time link (BRAI-355; login CSRF / account swap fixed in BRAI-377).
     *
     * The code is consumed here, once, and the sign-in it carries is bound to this browser session
     * (`app_session.pending`, PENDING_TTL to confirm). Then:
     *  - the same user is already signed in → continue at once;
     *  - nobody is signed in and the request comes from the desktop app's webview (user agent with
     *    ` BrainitiveDesktop/`) → sign in at once, as before;
     *  - anyone else is signed in, or it is a plain browser → a confirm page ("Continue as B?" /
     *    "Sign in as B on this browser?"); only its POST (CSRF) signs in. Nobody is logged out silently.
     * Consuming on the GET (not on the POST) keeps the link single-use from the first open: a copy of the
     * URL (history, a shared screen) is dead even while the confirm page is open, and the POST carries no
     * code at all — only this session's CSRF token, which a forged form can't have.
     */
    public function session(Request $request, string $code): Response
    {
        $payload = AppAuthCodes::consume(AppAuthCodes::WEB_SESSION, $code);
        $device = $payload === null ? null : $this->activeDevice((int) ($payload['device_id'] ?? 0), (int) ($payload['user_id'] ?? 0));
        $user = $device?->user;

        if (! $user || ! $device) {
            return $this->expiredLink();
        }

        $current = Auth::guard('web')->user();
        $to = is_string($request->query('to')) ? $request->query('to') : null;

        if (($current && $current->is($user)) || (! $current && $this->fromDesktopWebview($request))) {
            return $this->signIn($request, $device, $to);
        }

        $request->session()->put(self::PENDING, [
            'device_id' => $device->id,
            'user_id' => $user->id,
            'to' => $to,
            'expires_at' => now()->addSeconds(self::PENDING_TTL)->getTimestamp(),
        ]);

        return Inertia::render(self::SESSION_PAGE, [
            'user' => ['name' => $user->name, 'email' => $user->email],
            'current' => $current ? ['name' => $current->name, 'email' => $current->email] : null,
        ])->toResponse($request);
    }

    /** The confirm page's decision (POST + CSRF): Continue signs the pending user in, Cancel drops it. */
    public function confirmSession(Request $request): Response
    {
        $request->validate(['decision' => ['required', 'string', 'in:continue,cancel']]);
        $pending = $request->session()->pull(self::PENDING);

        $device = is_array($pending) && (int) ($pending['expires_at'] ?? 0) > now()->getTimestamp()
            ? $this->activeDevice((int) ($pending['device_id'] ?? 0), (int) ($pending['user_id'] ?? 0))
            : null;
        if (! $device) {
            return $this->expiredLink();
        }

        if ($request->input('decision') !== 'continue') {
            return Auth::guard('web')->check()
                ? redirect()->route('dashboard')
                : redirect()->route('login')->with('status', 'Sign-in cancelled.');
        }

        return $this->signIn($request, $device, is_string($pending['to'] ?? null) ? $pending['to'] : null);
    }

    /** Sign the device's user into the web guard, remembered, and bind the session to the device. */
    private function signIn(Request $request, AppDevice $device, ?string $to): RedirectResponse
    {
        $user = $device->user;
        $current = Auth::guard('web')->user();
        if ($current && ! $current->is($user)) {
            // only after the user confirmed (confirmSession): end the other account's session first
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        // remember-me: the webview keeps the session across app restarts (BRAI-357)
        Auth::guard('web')->login($user, true);
        $request->session()->regenerate();
        // revoking this device ends this web session too (EndRevokedAppDeviceSessions, BRAI-377)
        $request->session()->put(AppWebSessions::SESSION_KEY, $device->id);

        return redirect($this->safeTarget($to));
    }

    private function activeDevice(int $deviceId, int $userId): ?AppDevice
    {
        $device = AppDevice::query()->active()
            ->whereKey($deviceId)
            ->where('user_id', $userId)
            ->with('user')
            ->first();

        return $device && $device->user && ! $device->user->isSuspended() ? $device : null;
    }

    private function expiredLink(): RedirectResponse
    {
        return redirect()->route('login')->with('status', 'This sign-in link has expired or was already used. Sign in again from the app.');
    }

    /**
     * The desktop app's webview: its user agent ends in ` BrainitiveDesktop/<version>` (resources/js/lib/desktop.ts).
     * A web page can't set another browser's user agent, so a link planted in someone's normal browser never
     * takes this path (KNOWLEDGE → App API security review).
     */
    private function fromDesktopWebview(Request $request): bool
    {
        return str_contains((string) $request->userAgent(), ' BrainitiveDesktop/');
    }

    /**
     * The validated authorize parameters, or the reason they are invalid.
     *
     * @param  array<string, mixed>  $input
     * @return array{client: string, code_challenge: string, code_challenge_method: string, state: string, device_name?: string|null}|string
     */
    private function params(array $input): array|string
    {
        $validator = Validator::make($input, [
            // only clients that sign in through the browser (the TV uses the device code, /app/device)
            'client' => ['required', 'string', Rule::in(AppClients::using(AppClients::AUTHORIZATION_CODE))],
            'code_challenge' => ['required', 'string', 'size:43', 'regex:/^[A-Za-z0-9_-]{43}$/'],
            'code_challenge_method' => ['required', 'string', Rule::in(['S256'])],
            'state' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        if ($validator->fails()) {
            return $validator->errors()->first();
        }

        /** @var array{client: string, code_challenge: string, code_challenge_method: string, state: string, device_name?: string|null} */
        return $validator->validated();
    }

    /** @return array{name: string, redirect: string} */
    private function client(string $client): array
    {
        return (array) config('app-api.clients.'.$client);
    }

    private function invalid(Request $request, string $reason): Response
    {
        return Inertia::render(self::PAGE, ['error' => $reason])
            ->toResponse($request)
            ->setStatusCode(400);
    }

    /** Only a path on this site: "/…", never "//host" or "/\host" (open redirect). */
    private function safeTarget(mixed $to): string
    {
        if (is_string($to) && strlen($to) <= 2000 && str_starts_with($to, '/')
            && ! str_starts_with($to, '//') && ! str_contains($to, '\\') && ! preg_match('/[\x00-\x1F\x7F]/', $to)) {
            return $to;
        }

        return route('dashboard', absolute: false);
    }
}
