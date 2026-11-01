<?php

namespace App\Http\Controllers;

use App\Models\AppDevice;
use App\Support\AppApi\AppAuthCodes;
use App\Support\AppApi\AppClients;
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
 *    into a remembered web session.
 */
class AppAuthController extends Controller
{
    private const PAGE = 'AppAuth/Authorize';

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

    /** The webview's one-time link: sign that user into the web guard, remembered. */
    public function session(Request $request, string $code): RedirectResponse
    {
        $payload = AppAuthCodes::consume(AppAuthCodes::WEB_SESSION, $code);

        $device = $payload === null ? null : AppDevice::query()->active()
            ->whereKey((int) ($payload['device_id'] ?? 0))
            ->where('user_id', (int) ($payload['user_id'] ?? 0))
            ->with('user')
            ->first();
        $user = $device?->user;

        if (! $user || $user->isSuspended()) {
            return redirect()->route('login')->with('status', 'This sign-in link has expired or was already used. Sign in again from the app.');
        }

        $current = Auth::guard('web')->user();
        if ($current && ! $current->is($user)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        // remember-me: the webview keeps the session across app restarts (BRAI-357)
        Auth::guard('web')->login($user, true);
        $request->session()->regenerate();

        return redirect($this->safeTarget($request->query('to')));
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
