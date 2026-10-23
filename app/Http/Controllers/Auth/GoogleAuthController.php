<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Support\SignOutEverywhere;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    /**
     * Redirect the user to Google.
     */
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * Handle the callback from Google.
     */
    public function callback(Request $request): RedirectResponse
    {
        if ($request->has('error')) {
            $message = $request->get('error') === 'access_denied'
                ? 'You cancelled signing in with Google.'
                : 'Something went wrong signing in with Google. Please try again.';

            return redirect()->route('login')->with('status', $message);
        }

        $googleUser = Socialite::driver('google')->user();

        // Only an address Google has verified may sign in to / create an account by email
        $raw = $googleUser->user ?? [];
        if (! filter_var($raw['email_verified'] ?? $raw['verified_email'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return redirect()->route('login')->with('status', 'Your Google email address is not verified. Verify it with Google, or sign up with email and password.');
        }

        $user = User::query()->where('google_id', $googleUser->getId())->first();

        if ($user) {
            // Already linked; optional: update name/avatar from Google
        } else {
            $user = User::query()->where('email', $googleUser->getEmail())->first();

            if ($user) {
                // Someone may have registered this address without owning it: the provider
                // proves the email, so the never-verified account is reset before linking
                if ($user->email_verified_at === null) {
                    $user->forceFill(['password' => bcrypt(str()->random(64))])->save();
                    SignOutEverywhere::run($user);
                }
                $user->update([
                    'google_id' => $googleUser->getId(),
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ]);
                WorkspaceInvitation::acceptPendingForUser($user);
            } else {
                if (! WorkspaceInvitation::canSignUp($googleUser->getEmail())) {
                    return redirect()->route('login')->with('status', 'Sign-up is by invitation only. Ask a workspace admin to invite this email address.');
                }

                $user = User::query()->create([
                    'name' => $googleUser->getName() ?? $googleUser->getEmail(),
                    'email' => $googleUser->getEmail(),
                    'google_id' => $googleUser->getId(),
                    'email_verified_at' => now(),
                    'password' => bcrypt(str()->random(64)),
                ]);

                WorkspaceInvitation::acceptPendingForUser($user);
            }
        }

        Auth::login($user, true);

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
