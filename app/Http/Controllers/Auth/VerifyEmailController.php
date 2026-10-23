<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VerifyEmailController extends Controller
{
    /**
     * Mark the user's email as verified. The route only accepts a valid signed link
     * (`signed:relative`); the hash must still match the current email. The link proves the
     * address, not the person: it never signs anyone in — a guest goes on to the login page.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $user = User::find($request->route('id'));

        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), (string) $request->route('hash'))) {
            return redirect()->route('login')->with('status', 'Invalid verification link.');
        }

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        if ($request->user()?->is($user)) {
            return redirect()->intended(route('dashboard', absolute: false).'?verified=1');
        }

        return redirect()->route('login')->with('status', 'Your email is verified. Log in to continue.');
    }
}
