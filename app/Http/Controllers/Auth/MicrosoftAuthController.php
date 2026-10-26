<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\InstanceSettings;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Support\Auth\MicrosoftLogin;
use App\Support\Auth\MicrosoftLoginFailed;
use App\Support\SignOutEverywhere;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Sign in with Microsoft (BRAI-148) — the same account rules as Google / GitHub: a linked account
 * signs in; a provider-verified email links an existing account (a never-verified one is reset
 * first) or creates one when sign-up is open / invited. Which Microsoft emails count as verified:
 * see MicrosoftLogin.
 */
class MicrosoftAuthController extends Controller
{
    private const SESSION_KEY = 'auth_microsoft_oauth';

    private const SUSPENDED = 'Your account has been suspended. Contact your administrator.';

    public function redirect(Request $request, MicrosoftLogin $microsoft): RedirectResponse
    {
        abort_unless(MicrosoftLogin::available(), 404);

        $state = Str::random(40);
        $nonce = Str::random(40);
        $verifier = Str::random(96); // PKCE: 43–128 unreserved characters
        $request->session()->put(self::SESSION_KEY, ['state' => $state, 'nonce' => $nonce, 'verifier' => $verifier]);

        return redirect()->away($microsoft->authorizeUrl($state, $nonce, $verifier));
    }

    public function callback(Request $request, MicrosoftLogin $microsoft): RedirectResponse
    {
        abort_unless(MicrosoftLogin::available(), 404);

        // One-time: a replayed or second callback finds nothing.
        $flow = $request->session()->pull(self::SESSION_KEY);

        if ($request->filled('error')) {
            return $this->fail($request->query('error') === 'access_denied'
                ? 'You cancelled signing in with Microsoft.'
                : 'Something went wrong signing in with Microsoft. Please try again.');
        }

        $state = $request->query('state');
        $code = $request->query('code');
        if (! is_array($flow) || ! is_string($flow['state'] ?? null) || ! is_string($state) || ! hash_equals($flow['state'], $state)
            || ! is_string($code) || $code === '' || strlen($code) > 4096) {
            return $this->fail('The Microsoft sign-in expired. Please try again.');
        }

        try {
            $identity = $microsoft->identity($code, (string) ($flow['verifier'] ?? ''), (string) ($flow['nonce'] ?? ''));
        } catch (MicrosoftLoginFailed $e) {
            return $this->fail($e->getMessage());
        }

        $user = User::query()->where('microsoft_id', $identity['id'])->first();

        if (! $user) {
            $email = $identity['email'];
            if ($email === null) {
                return $this->fail('We couldn’t confirm the email address of this Microsoft account. Sign in with your email and password, or ask your Microsoft 365 admin to verify your email domain.');
            }

            // case-insensitive: older Google / GitHub sign-ups may have stored a mixed-case address
            $user = User::query()->whereRaw('lower(email) = ?', [$email])->orderBy('id')->first();

            if ($user) {
                if ($user->isSuspended()) {
                    return $this->fail(self::SUSPENDED);
                }

                // Someone may have registered this address without owning it: Microsoft proves
                // the email, so the never-verified account is reset before linking
                if ($user->email_verified_at === null && InstanceSettings::requiresEmailVerification()) {
                    $user->forceFill(['password' => bcrypt(Str::random(64))])->save();
                    SignOutEverywhere::run($user);
                }
                $user->update([
                    'microsoft_id' => $identity['id'],
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ]);
                WorkspaceInvitation::acceptPendingForUser($user);
            } else {
                if (! WorkspaceInvitation::canSignUp($email)) {
                    return $this->fail('Sign-up is by invitation only. Ask a workspace admin to invite this email address.');
                }

                $user = User::query()->create([
                    'name' => $identity['name'] ?? $email,
                    'email' => $email,
                    'microsoft_id' => $identity['id'],
                    'email_verified_at' => now(),
                    'password' => bcrypt(Str::random(64)),
                ]);

                WorkspaceInvitation::acceptPendingForUser($user);
            }
        }

        if ($user->isSuspended()) {
            return $this->fail(self::SUSPENDED);
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function fail(string $message): RedirectResponse
    {
        return redirect()->route('login')->with('status', $message);
    }
}
