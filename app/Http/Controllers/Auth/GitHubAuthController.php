<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class GitHubAuthController extends Controller
{
    /**
     * Redirect the user to GitHub.
     */
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('github')
            ->scopes(['read:user', 'user:email'])
            ->redirect();
    }

    /**
     * Handle the callback from GitHub.
     */
    public function callback(Request $request): RedirectResponse
    {
        if ($request->has('error')) {
            $message = $request->get('error') === 'access_denied'
                ? 'You cancelled signing in with GitHub.'
                : 'Something went wrong signing in with GitHub. Please try again.';

            return redirect()->route('login')->with('status', $message);
        }

        $githubUser = Socialite::driver('github')->user();

        $email = $githubUser->getEmail();
        if (! $email) {
            return redirect()->route('login')->with('status', 'We need your email from GitHub. Please add a public email in your GitHub profile or grant email access, then try again.');
        }

        $user = User::query()->where('github_id', $githubUser->getId())->first();

        if ($user) {
            // Already linked
        } else {
            $user = User::query()->where('email', $email)->first();

            if ($user) {
                $user->update([
                    'github_id' => $githubUser->getId(),
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ]);
            } else {
                if (! WorkspaceInvitation::canSignUp($email)) {
                    return redirect()->route('login')->with('status', 'Sign-up is by invitation only. Ask a workspace admin to invite this email address.');
                }

                $user = User::query()->create([
                    'name' => $githubUser->getName() ?? $githubUser->getNickname() ?? $email,
                    'email' => $email,
                    'github_id' => $githubUser->getId(),
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
