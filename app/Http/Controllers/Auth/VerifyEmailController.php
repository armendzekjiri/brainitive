<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VerifyEmailController extends Controller
{
    /**
     * Mark the user's email as verified. Works when the user is logged in or when
     * they click the link from email (guest). Validates id + hash + optional expiry only (no signed URL).
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $userId = $request->route('id');
        $hash = $request->route('hash');
        $expires = $request->query('expires');

        if ($expires && (int) $expires < time()) {
            return redirect()->route('login')->with('status', 'Verification link has expired. Please request a new one.');
        }

        $user = User::find($userId);
        if (! $user) {
            return redirect()->route('login')->with('status', 'Invalid verification link.');
        }

        $expectedHash = sha1($user->getEmailForVerification());
        if (! hash_equals($expectedHash, $hash)) {
            return redirect()->route('login')->with('status', 'Invalid verification link.');
        }

        if ($user->hasVerifiedEmail()) {
            $this->ensureUserLoggedIn($user);
            return redirect()->intended(route('dashboard', absolute: false).'?verified=1');
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        $this->ensureUserLoggedIn($user);

        return redirect()->intended(route('dashboard', absolute: false).'?verified=1');
    }

    private function ensureUserLoggedIn(User $user): void
    {
        if (! Auth::check() || Auth::id() !== $user->id) {
            Auth::login($user);
        }
    }
}
