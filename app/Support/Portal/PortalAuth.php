<?php

namespace App\Support\Portal;

use App\Models\PortalIdentity;
use App\Models\PortalLoginToken;
use App\Models\User;
use App\Notifications\PortalLoginLinkNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Who is looking at a portal page (v1.42.0 foundation; contract: docs/ai/tasks/release-v1.42.0.md).
 *
 * - A signed-in, verified Brainitive user is always "themselves": their PortalIdentity is created or
 *   linked by email on first use (the account proved the email already).
 * - Anyone else signs in with a one-time link mailed to them (sendLink → GET confirm page → POST
 *   consume). The identity id then lives in the normal web session under SESSION_KEY.
 *
 * Signed in only means "this email is theirs". Each portal decides what that person may see:
 * BRAI-60 lets any verified identity vote; BRAI-73 also requires an active client invitation.
 */
final class PortalAuth
{
    public const SESSION_KEY = 'portal_identity_id';

    public const LINK_MINUTES = 30;

    /** sign-in mails per address in 15 minutes (the route also throttles per IP) */
    public const LINKS_PER_EMAIL = 5;

    /** Paths a sign-in link may return to; anything else falls back to "/". */
    public const RETURN_PREFIXES = ['/feedback/', '/client/'];

    public static function current(Request $request): ?PortalIdentity
    {
        $user = $request->user();
        if ($user instanceof User && $user->hasVerifiedEmail()) {
            return self::identityForUser($user);
        }

        $id = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;
        if (! $id) {
            return null;
        }

        $identity = PortalIdentity::find($id);
        if (! $identity?->isVerified()) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        if (! $identity->last_seen_at || $identity->last_seen_at->lt(now()->subMinutes(5))) {
            $identity->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $identity;
    }

    /** The identity a Brainitive account acts through; links an earlier guest identity with the same email. */
    public static function identityForUser(User $user): PortalIdentity
    {
        if ($linked = PortalIdentity::where('user_id', $user->id)->first()) {
            return $linked;
        }

        return DB::transaction(function () use ($user) {
            $identity = PortalIdentity::where('email', PortalIdentity::normalizeEmail($user->email))->lockForUpdate()->first()
                ?? new PortalIdentity(['email' => $user->email]);

            $identity->fill([
                'user_id' => $user->id,
                'name' => $identity->name ?: $user->name,
                'email_verified_at' => $identity->email_verified_at ?? now(),
            ])->save();

            return $identity;
        });
    }

    /**
     * Mail a sign-in link. Says nothing about whether the address was known (callers always show
     * "check your email"); silently skips when the address hit its limit.
     */
    public static function sendLink(string $email, ?string $name, ?string $returnTo): void
    {
        $email = PortalIdentity::normalizeEmail($email);
        $key = 'portal-link:'.sha1($email);
        if (RateLimiter::tooManyAttempts($key, self::LINKS_PER_EMAIL)) {
            return;
        }
        RateLimiter::hit($key, 15 * 60);

        $identity = PortalIdentity::firstOrCreate(['email' => $email]);
        $name = $name !== null ? trim(strip_tags($name)) : '';
        if ($name !== '' && ! $identity->name) {
            $identity->forceFill(['name' => Str::limit($name, 120, '')])->save();
        }

        $token = Str::random(48);
        $identity->loginTokens()->create([
            'token_hash' => hash('sha256', $token),
            'return_to' => self::safeReturnTo($returnTo),
            'expires_at' => now()->addMinutes(self::LINK_MINUTES),
        ]);

        $identity->notify(new PortalLoginLinkNotification($token));
    }

    /** The usable token for an emailed value (no side effects; the confirm page uses it). */
    public static function findToken(string $token): ?PortalLoginToken
    {
        $row = PortalLoginToken::with('identity')->where('token_hash', hash('sha256', $token))->first();

        return $row?->isUsable() ? $row : null;
    }

    /** Spend a token and sign its identity in. Returns where to go next, or null when the link is no good. */
    public static function consume(Request $request, string $token): ?string
    {
        $row = DB::transaction(function () use ($token) {
            $row = PortalLoginToken::where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if (! $row?->isUsable()) {
                return null;
            }
            $row->forceFill(['used_at' => now()])->save();
            $row->identity->forceFill([
                'email_verified_at' => $row->identity->email_verified_at ?? now(),
                'last_seen_at' => now(),
            ])->save();

            return $row;
        });

        if (! $row) {
            return null;
        }

        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, $row->portal_identity_id);

        return $row->return_to ?: '/';
    }

    public static function logout(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
        $request->session()->regenerateToken();
    }

    /** A local portal path, or "/" (no hosts, no "//", no backslashes, no control characters). */
    public static function safeReturnTo(?string $path): string
    {
        if (! is_string($path) || $path === '' || ! str_starts_with($path, '/') || str_starts_with($path, '//')
            || str_contains($path, '\\') || preg_match('/[\x00-\x1F\x7F]/', $path) || strlen($path) > 500) {
            return '/';
        }

        foreach (self::RETURN_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $path;
            }
        }

        return '/';
    }

    /** Props for PortalLayout: the viewer, or null. */
    public static function shared(Request $request): ?array
    {
        return self::current($request)?->toPortalArray();
    }
}
