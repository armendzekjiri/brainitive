<?php

namespace App\Http\Controllers;

use App\Models\CalendarConnection;
use App\Services\GoogleTasksService;
use App\Support\Calendar\CalendarProviderException;
use App\Support\Calendar\CalendarProviders;
use App\Support\Calendar\PendingConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

class CalendarConnectionController extends Controller
{
    private const GOOGLE_SCOPES = [
        'https://www.googleapis.com/auth/calendar',
        'https://www.googleapis.com/auth/calendar.events',
        'https://www.googleapis.com/auth/tasks',
        'openid',
        'email',
        'profile',
    ];

    /**
     * Start the Google OAuth flow specifically for calendar access. We force
     * `prompt=select_account` so the user can pick which Google account to
     * connect (which may differ from the one they used to log in to Brainitive).
     * We also need `access_type=offline` to get a refresh_token.
     */
    public function redirectToGoogle(): RedirectResponse
    {
        return Socialite::driver('google')
            ->scopes(self::GOOGLE_SCOPES)
            ->with([
                'access_type' => 'offline',
                'prompt' => 'consent select_account',
                'include_granted_scopes' => 'true',
            ])
            ->redirectUrl(route('calendar.connections.google.callback'))
            ->redirect();
    }

    /**
     * OAuth callback. Stash the tokens in the session, fetch the list of
     * available calendars on the chosen account, and bounce back to the
     * calendar page with a "?picking=…" state so the UI can show a picker
     * dialog. Connection is NOT created until the user picks a calendar.
     */
    public function googleCallback(Request $request): RedirectResponse
    {
        if ($request->has('error')) {
            return redirect()->route('calendar.index')->with('error', 'Connecting your Google calendar was cancelled.');
        }

        $googleUser = Socialite::driver('google')
            ->scopes(self::GOOGLE_SCOPES)
            ->redirectUrl(route('calendar.connections.google.callback'))
            ->user();

        $accessToken = $googleUser->token;
        $refreshToken = $googleUser->refreshToken;
        $expiresIn = (int) ($googleUser->expiresIn ?? 3600);

        if (! $refreshToken) {
            // Without a refresh token we can't keep the connection alive past
            // an hour. Most likely the user has previously authorised; we need
            // them to revoke + re-authorise. The `prompt=consent` above usually
            // avoids this, but as a safety net:
            return redirect()->route('calendar.index')->with('error', 'Google didn\'t return a refresh token. Try again.');
        }

        // Fetch this account's calendar list for the picker. We don't create the connection
        // yet — that happens after the user picks which calendar(s) to add.
        $probe = new CalendarConnection([
            'provider' => 'google',
            'kind' => 'calendar',
            'account_email' => (string) $googleUser->getEmail(),
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_expires_at' => now()->addSeconds(max(60, $expiresIn - 60)),
            'is_active' => true,
        ]);
        try {
            $calendars = CalendarProviders::make('google')->listCalendars($probe);
        } catch (CalendarProviderException $e) {
            return redirect()->route('calendar.index')->with('error', $e->getMessage());
        }

        // Same token also gives us the user's Google Tasks lists.
        $taskLists = [];
        $tasksResp = Http::withToken($accessToken)
            ->acceptJson()
            ->get('https://tasks.googleapis.com/tasks/v1/users/@me/lists');
        if ($tasksResp->successful()) {
            $taskLists = collect($tasksResp->json('items', []))
                ->map(fn ($l) => [
                    'id' => $l['id'],
                    'title' => $l['title'] ?? $l['id'],
                ])
                ->values()
                ->all();
        }

        (new PendingConnection(
            provider: 'google',
            accountEmail: (string) $googleUser->getEmail(),
            accountName: $googleUser->getName(),
            calendars: $calendars,
            taskLists: $taskLists,
            secrets: ['access_token' => $accessToken, 'refresh_token' => $refreshToken, 'expires_in' => $expiresIn],
        ))->put();

        return redirect()->route('calendar.index', ['picking' => 'google']);
    }

    /** Return the pending picker state (calendars + task lists) for the UI. Never secrets. */
    public function pendingPicker(Request $request): JsonResponse
    {
        $pending = PendingConnection::current();
        if (! $pending || ! CalendarProviders::has($pending->provider)) {
            return response()->json(['pending' => null]);
        }

        return response()->json([
            'pending' => $pending->forPicker(CalendarProviders::make($pending->provider)->label()),
        ]);
    }

    /**
     * Create CalendarConnection rows for each calendar (and Google Tasks list) chosen in the
     * picker, for any provider: the pending flow says which provider and carries the secrets.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'external_calendar_ids' => 'nullable|array',
            'external_calendar_ids.*' => 'string',
            'external_task_list_ids' => 'nullable|array',
            'external_task_list_ids.*' => 'string',
        ]);

        $pending = PendingConnection::current();
        if (! $pending || ! CalendarProviders::has($pending->provider)) {
            return redirect()->route('calendar.index')->with('error', 'Connection state expired. Please connect again.');
        }

        $userId = (int) Auth::id();
        $shared = $pending->connectionAttributes() + ['sync_error' => null, 'sync_error_at' => null];

        $calendarsById = collect($pending->calendars)->keyBy('id');
        foreach ((array) ($validated['external_calendar_ids'] ?? []) as $calId) {
            $cal = $calendarsById->get($calId);
            if (! $cal) {
                continue;
            }
            // A calendar the account may only read (Google "reader", a read-only CalDAV
            // collection) is marked in credentials; BaseCalendarProvider::isWritable() reads it.
            $credentials = ($shared['credentials'] ?? []) + (! empty($cal['read_only']) ? ['read_only' => true] : []);
            CalendarConnection::upsertFor($userId, $pending->provider, 'calendar', $pending->accountEmail, (string) $cal['id'], array_merge($shared, [
                'external_calendar_name' => $cal['name'],
                'external_calendar_color' => $cal['color'] ?? null,
                'credentials' => $credentials ?: null,
            ]));
        }

        // Google Tasks lists (only the Google flow offers any).
        $tasksById = collect($pending->taskLists)->keyBy('id');
        foreach ((array) ($validated['external_task_list_ids'] ?? []) as $listId) {
            $list = $tasksById->get($listId);
            if (! $list || $pending->provider !== 'google') {
                continue;
            }
            CalendarConnection::upsertFor($userId, $pending->provider, 'tasks', $pending->accountEmail, (string) $list['id'], $shared + [
                'external_calendar_name' => $list['title'],
                'external_calendar_color' => null,
            ]);
        }

        // When the same account is reconnected (refreshed scope, new app password), propagate
        // the new secrets to all of that account's existing rows so they keep working, and
        // revive rows that were switched off because their access had failed. We must iterate
        // and save each model rather than a mass query-builder update(), because the bulk
        // update bypasses the `encrypted` casts and would write plaintext secrets into columns
        // that are read back as encrypted payloads (causing a DecryptException on next access).
        $hasTokens = array_key_exists('access_token', $pending->secrets);
        $newCredentials = $pending->secrets['credentials'] ?? null;
        if ($hasTokens || $newCredentials) {
            $existing = CalendarConnection::where('user_id', $userId)
                ->where('provider', $pending->provider)
                ->where('account_email', $pending->accountEmail)
                ->get();
            foreach ($existing as $row) {
                if ($hasTokens) {
                    $row->access_token = $shared['access_token'];
                    $row->refresh_token = $shared['refresh_token'];
                    $row->token_expires_at = $shared['token_expires_at'];
                }
                if ($newCredentials) {
                    // keep the row's own flags (read_only) next to the new secrets
                    $row->credentials = $newCredentials + array_intersect_key((array) $row->credentials, ['read_only' => true]);
                }
                if ($row->sync_error !== null) {
                    $row->is_active = true;
                    $row->sync_error = null;
                    $row->sync_error_at = null;
                }
                $row->save();
            }
        }

        PendingConnection::forget();

        return redirect()->route('calendar.index')->with('success', 'Connected.');
    }

    /**
     * Connect a 'form' / 'url' provider from the generic dialog (CalDAV server + app password,
     * an ICS link, …): validate the provider's declared fields, then hand them to
     * connectWithForm(). It returns the new connections, or a PendingConnection to pick from.
     */
    public function manualStore(Request $request, string $provider): RedirectResponse
    {
        abort_unless(CalendarProviders::has($provider), 404);
        $impl = CalendarProviders::make($provider);
        abort_unless($impl->available() && in_array($impl->connectMode(), ['form', 'url'], true), 404);

        $rules = [];
        $names = [];
        $flashable = []; // old input kept after an error: never passwords or links (ICS tokens)
        foreach ($impl->formFields() as $field) {
            if (in_array($field['type'] ?? 'text', ['text', 'email'], true)) {
                $flashable[] = $field['name'];
            }
            $rules[$field['name']] = array_merge(
                [($field['required'] ?? true) ? 'required' : 'nullable', 'string', 'max:2048'],
                match ($field['type'] ?? 'text') {
                    'url' => ['url:http,https,webcal'],
                    'email' => ['email'],
                    default => [],
                },
            );
            $names[$field['name']] = $field['label'];
        }

        try {
            $input = $request->validate($rules, [], $names);
            $result = $impl->connectWithForm($request->user(), $input);
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                throw $e;
            }

            // Laravel would flash every field but `password`; secrets must not land in the session.
            return back()->withErrors($e->errors())->withInput($request->only($flashable));
        }

        if ($result instanceof PendingConnection) {
            $result->put();

            return redirect()->route('calendar.index', ['picking' => $provider]);
        }

        $count = count($result);

        return redirect()->route('calendar.index')
            ->with('success', $count === 1 ? 'Calendar connected.' : "{$count} calendars connected.");
    }

    /** Toggle a connection's active state. */
    public function toggle(Request $request, CalendarConnection $connection): RedirectResponse
    {
        abort_unless($connection->user_id === Auth::id(), 403);
        $connection->update(['is_active' => ! $connection->is_active]);

        return back();
    }

    /** Disconnect (soft delete + clear tokens and credentials). */
    public function destroy(Request $request, CalendarConnection $connection): RedirectResponse
    {
        abort_unless($connection->user_id === Auth::id(), 403);
        $connection->update(['access_token' => null, 'refresh_token' => null, 'credentials' => null, 'is_active' => false]);
        $connection->delete();

        return back()->with('success', 'Calendar disconnected.');
    }

    /**
     * Full details of one event for the editor dialog, as a normalized event (any provider).
     * `timezone` (the browser's) asks for the wall-clock times in that zone.
     */
    public function showEvent(Request $request, CalendarConnection $connection, string $eventId): JsonResponse
    {
        $this->authorizeCalendar($connection, $eventId);
        $timezone = $this->validTimezone($request->query('timezone'));

        $provider = CalendarProviders::for($connection);
        $event = $provider->getEvent($connection, $eventId, $timezone);
        if (! $event) {
            return response()->json(['error' => 'Event not found'], 404);
        }

        return response()->json([
            'event' => $event,
            'provider' => $connection->provider,
            'provider_label' => $provider->label(),
            'read_only' => ! $provider->isWritable($connection),
        ]);
    }

    /** Update an event's content (title, description, location, times) on its provider. */
    public function updateEvent(Request $request, CalendarConnection $connection, string $eventId): RedirectResponse
    {
        $this->authorizeCalendar($connection, $eventId);
        $validated = $request->validate([
            'title' => 'sometimes|string|max:500',
            'description' => 'sometimes|nullable|string|max:10000',
            'location' => 'sometimes|nullable|string|max:500',
            'start_date' => 'sometimes|nullable|date_format:Y-m-d',
            'start_time' => 'sometimes|nullable|date_format:H:i',
            'end_date' => 'sometimes|nullable|date_format:Y-m-d',
            'end_time' => 'sometimes|nullable|date_format:H:i',
            'timezone' => 'nullable|string|max:64',
        ]);
        CalendarProviders::writable($connection)->updateEvent($connection, $eventId, $validated);

        return back();
    }

    /** Delete an event on its provider. */
    public function deleteEvent(Request $request, CalendarConnection $connection, string $eventId): RedirectResponse
    {
        $this->authorizeCalendar($connection, $eventId);
        CalendarProviders::writable($connection)->deleteEvent($connection, $eventId);

        return back();
    }

    // ── Google Tasks endpoints ──────────────────────────────────────────

    /** Fetch a single Google Task with full details. */
    public function showTask(Request $request, CalendarConnection $connection, string $taskId, GoogleTasksService $service): JsonResponse
    {
        abort_unless($connection->user_id === Auth::id() && $connection->kind === 'tasks', 403);
        $task = $service->getTask($connection, $taskId);
        if (! $task) {
            return response()->json(['error' => 'Task not found'], 404);
        }

        return response()->json(['task' => $task]);
    }

    /** Create a task on a tasks list (called from QuickCreate). */
    public function createTask(Request $request, CalendarConnection $connection, GoogleTasksService $service): RedirectResponse
    {
        abort_unless($connection->user_id === Auth::id() && $connection->kind === 'tasks', 403);
        $validated = $request->validate([
            'title' => 'required|string|max:1024',
            'date' => 'required|date_format:Y-m-d',
            'time' => 'nullable|date_format:H:i',
            'description' => 'nullable|string|max:8000',
            'timezone' => 'nullable|string|max:64',
        ]);
        $service->createTask($connection, $validated);

        return back();
    }

    public function updateTask(Request $request, CalendarConnection $connection, string $taskId, GoogleTasksService $service): RedirectResponse
    {
        abort_unless($connection->user_id === Auth::id() && $connection->kind === 'tasks', 403);
        $validated = $request->validate([
            'title' => 'sometimes|string|max:1024',
            'description' => 'sometimes|nullable|string|max:8000',
            'date' => 'sometimes|nullable|date_format:Y-m-d',
            'time' => 'sometimes|nullable|date_format:H:i',
            'completed' => 'sometimes|boolean',
            'timezone' => 'nullable|string|max:64',
        ]);
        $service->updateTask($connection, $taskId, $validated);

        return back();
    }

    public function deleteTask(Request $request, CalendarConnection $connection, string $taskId, GoogleTasksService $service): RedirectResponse
    {
        abort_unless($connection->user_id === Auth::id() && $connection->kind === 'tasks', 403);
        $service->deleteTask($connection, $taskId);

        return back();
    }

    // ── Calendar event endpoints (any provider) ─────────────────────────

    /** Create an event on a connected calendar of any writable provider (QuickCreate). */
    public function createEvent(Request $request, CalendarConnection $connection): RedirectResponse
    {
        $this->authorizeCalendar($connection);
        $validated = $request->validate([
            'title' => 'required|string|max:500',
            'date' => 'required|date_format:Y-m-d',
            'time' => 'nullable|date_format:H:i',
            'end_date' => 'nullable|date_format:Y-m-d',
            'end_time' => 'nullable|date_format:H:i',
            'description' => 'nullable|string|max:5000',
            'location' => 'nullable|string|max:500',
            'timezone' => 'nullable|string|max:64',
        ]);

        CalendarProviders::writable($connection)->createEvent($connection, [
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'location' => $validated['location'] ?? null,
            'start_date' => $validated['date'],
            'start_time' => $validated['time'] ?? null,
            'end_date' => $validated['end_date'] ?? null,
            'end_time' => $validated['end_time'] ?? null,
            'timezone' => $validated['timezone'] ?? null,
        ]);

        return back();
    }

    /**
     * The connection is the user's own calendar (not a Google Tasks list). Event ids are any
     * segment but "." / "..", which would turn into another path once put into a provider URL.
     */
    private function authorizeCalendar(CalendarConnection $connection, ?string $eventId = null): void
    {
        abort_unless($connection->user_id === Auth::id(), 403);
        abort_unless($connection->kind === 'calendar', 404);
        abort_if($eventId === '.' || $eventId === '..', 404);
    }

    private function validTimezone(mixed $timezone): ?string
    {
        return is_string($timezone) && in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)
            ? $timezone
            : null;
    }
}
