<?php

namespace App\Http\Controllers;

use App\Models\CalendarConnection;
use App\Support\Calendar\CalendarProviderException;
use App\Support\Calendar\PendingConnection;
use App\Support\Calendar\Providers\OutlookCalendarProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Connect Outlook.com / Microsoft 365 calendars (BRAI-140): OAuth 2.0 authorization code with
 * PKCE against the Microsoft identity platform. `state` and the PKCE verifier live in the
 * session and are used once; the callback lists the account's calendars and hands over to the
 * shared picker (PendingConnection → calendar.connections.store creates the rows).
 */
class OutlookCalendarConnectController extends Controller
{
    private const SESSION_KEY = 'calendar_outlook_oauth';

    public function redirect(Request $request, OutlookCalendarProvider $outlook): RedirectResponse
    {
        abort_unless($outlook->available(), 404);

        $state = Str::random(40);
        $verifier = Str::random(96); // PKCE: 43–128 unreserved characters
        $request->session()->put(self::SESSION_KEY, ['state' => $state, 'verifier' => $verifier]);

        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return redirect()->away($outlook->authorizeUrl($state, $challenge));
    }

    public function callback(Request $request, OutlookCalendarProvider $outlook): RedirectResponse
    {
        abort_unless($outlook->available(), 404);

        // One-time: a replayed or second callback finds nothing.
        $flow = $request->session()->pull(self::SESSION_KEY);

        if ($request->filled('error')) {
            return $this->back('Connecting your Outlook calendar was cancelled.');
        }

        $state = $request->query('state');
        $code = $request->query('code');
        if (! is_array($flow) || ! is_string($flow['state'] ?? null) || ! is_string($state) || ! hash_equals($flow['state'], $state)
            || ! is_string($code) || $code === '' || strlen($code) > 4096) {
            return $this->back('The Microsoft sign-in expired. Try connecting again.');
        }

        try {
            $tokens = $outlook->exchangeCode($code, (string) $flow['verifier']);

            // An unsaved connection holding the fresh tokens, to ask Graph who this is and
            // which calendars it has. Nothing is stored until the user picks calendars.
            $probe = new CalendarConnection([
                'provider' => 'outlook',
                'kind' => 'calendar',
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'token_expires_at' => now()->addSeconds(max(60, $tokens['expires_in'] - 60)),
                'is_active' => true,
            ]);
            $account = $outlook->account($probe);
            $calendars = $outlook->listCalendars($probe);
        } catch (CalendarProviderException $e) {
            return $this->back($e->getMessage());
        }

        (new PendingConnection(
            provider: 'outlook',
            accountEmail: $account['email'],
            accountName: $account['name'],
            calendars: $calendars,
            secrets: [
                // the probe may have refreshed them while listing
                'access_token' => $probe->access_token,
                'refresh_token' => $probe->refresh_token,
                'expires_in' => $tokens['expires_in'],
            ],
        ))->put();

        return redirect()->route('calendar.index', ['picking' => 'outlook']);
    }

    private function back(string $message): RedirectResponse
    {
        return redirect()->route('calendar.index')->with('error', $message);
    }
}
