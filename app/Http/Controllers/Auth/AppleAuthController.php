<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\InstanceSettings;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Support\Auth\AppleLogin;
use App\Support\Auth\AppleLoginFailed;
use App\Support\Legal\LegalInfo;
use App\Support\SignOutEverywhere;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Sign in with Apple (BRAI-449) — the same account rules as Google / GitHub / Microsoft: a linked
 * account signs in; a verified email (private relay addresses too) links an existing account (a
 * never-verified one is reset first) or creates one when sign-up is open / invited.
 *
 * Apple answers with `response_mode=form_post`: a cross-site POST, which doesn't carry the
 * SameSite=Lax session cookie — and a session started there would replace the user's cookie. So:
 *  1. `callback` (POST, no web middleware: no session, no CSRF) stashes Apple's answer in the
 *     cache under a random one-time key and 303-redirects to
 *  2. `finish` (GET, same-site again, with the session), which checks the state and signs in.
 */
class AppleAuthController extends Controller
{
    private const SESSION_KEY = 'auth_apple_oauth';

    private const STASH = 'auth:apple:callback:';

    private const SUSPENDED = 'Your account has been suspended. Contact your administrator.';

    public function redirect(Request $request, AppleLogin $apple): RedirectResponse
    {
        abort_unless(AppleLogin::available(), 404);

        $state = Str::random(40);
        $nonce = Str::random(40);
        $request->session()->put(self::SESSION_KEY, ['state' => $state, 'nonce' => $nonce]);

        return redirect()->away($apple->authorizeUrl($state, $nonce));
    }

    /** Apple's form_post: keep the answer for a moment, continue on a same-site GET. */
    public function callback(Request $request): RedirectResponse
    {
        abort_unless(AppleLogin::available(), 404);

        $field = fn (string $name, int $max) => is_string($value = $request->input($name)) && $value !== '' && strlen($value) <= $max ? $value : null;

        $key = Str::random(40);
        Cache::put(self::STASH.$key, [
            'code' => $field('code', 4096),
            'state' => $field('state', 255),
            'error' => $field('error', 100),
            'name' => AppleLogin::nameFromUserField($request->input('user')),
        ], now()->addMinutes(5));

        return redirect()->route('login.apple.finish', ['key' => $key], 303);
    }

    public function finish(Request $request, AppleLogin $apple): RedirectResponse
    {
        abort_unless(AppleLogin::available(), 404);

        // One-time: a replayed or second callback finds nothing.
        $flow = $request->session()->pull(self::SESSION_KEY);
        $key = $request->query('key');
        $answer = is_string($key) && preg_match('/^[A-Za-z0-9]{40}$/', $key) ? Cache::pull(self::STASH.$key) : null;

        if (is_array($answer) && $answer['error'] !== null) {
            return $this->fail($answer['error'] === 'user_cancelled_authorize'
                ? 'You cancelled signing in with Apple.'
                : 'Something went wrong signing in with Apple. Please try again.');
        }

        if (! is_array($flow) || ! is_string($flow['state'] ?? null) || ! is_array($answer)
            || ! is_string($answer['state']) || ! hash_equals($flow['state'], $answer['state']) || ! is_string($answer['code'])) {
            return $this->fail('The Apple sign-in expired. Please try again.');
        }

        try {
            $identity = $apple->identity($answer['code'], (string) ($flow['nonce'] ?? ''));
        } catch (AppleLoginFailed $e) {
            return $this->fail($e->getMessage());
        }

        $user = User::query()->where('apple_id', $identity['id'])->first();

        if (! $user) {
            $email = $identity['email'];
            if ($email === null) {
                return $this->fail('We couldn’t confirm the email address of this Apple ID. Sign in with your email and password, or try again and share your email with Brainitive.');
            }

            // case-insensitive: older sign-ups may have stored a mixed-case address
            $user = User::query()->whereRaw('lower(email) = ?', [$email])->orderBy('id')->first();

            if ($user) {
                if ($user->isSuspended()) {
                    return $this->fail(self::SUSPENDED);
                }

                // Someone may have registered this address without owning it: Apple proves the
                // email, so the never-verified account is reset before linking
                if ($user->email_verified_at === null && InstanceSettings::requiresEmailVerification()) {
                    $user->forceFill(['password' => bcrypt(Str::random(64))])->save();
                    SignOutEverywhere::run($user, appReason: 'account_takeover');
                }
                $user->update([
                    'apple_id' => $identity['id'],
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ]);
                WorkspaceInvitation::acceptPendingForUser($user);
            } else {
                if (! WorkspaceInvitation::canSignUp($email)) {
                    return $this->fail('Sign-up is by invitation only. Ask a workspace admin to invite this email address.');
                }

                $user = User::query()->create([
                    // Apple shares the name only on the first sign-in; without it, the email's
                    // local part (a relay address's is random, hence "Apple user")
                    'name' => $answer['name'] ?? ($identity['private_email'] ? 'Apple user' : Str::before($email, '@')),
                    'email' => $email,
                    'apple_id' => $identity['id'],
                    'email_verified_at' => now(),
                    'password' => bcrypt(Str::random(64)),
                    // the sign-in page says that continuing accepts the Terms (BRAI-156)
                    ...LegalInfo::termsAcceptance(),
                ]);

                WorkspaceInvitation::acceptPendingForUser($user);
            }
        }

        if ($user->isSuspended()) {
            return $this->fail(self::SUSPENDED);
        }

        // the newest refresh token, only to revoke the authorization when the account is deleted
        if ($identity['refresh_token'] !== null) {
            $user->forceFill(['apple_refresh_token' => $identity['refresh_token']])->save();
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
