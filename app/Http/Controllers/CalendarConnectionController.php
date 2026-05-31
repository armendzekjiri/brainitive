<?php

namespace App\Http\Controllers;

use App\Models\CalendarConnection;
use App\Services\GoogleCalendarService;
use App\Services\GoogleTasksService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
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
            return redirect()->route('calendar.index')->with('status', 'Connecting your Google calendar was cancelled.');
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
            return redirect()->route('calendar.index')->with('status', 'Google didn\'t return a refresh token. Try again.');
        }

        // Fetch this account's calendar list and store on the session for the
        // picker. We don't create the connection yet — that happens after the
        // user picks which calendar(s) to add.
        $resp = Http::withToken($accessToken)
            ->acceptJson()
            ->get('https://www.googleapis.com/calendar/v3/users/me/calendarList');

        if (! $resp->successful()) {
            return redirect()->route('calendar.index')->with('status', 'Could not load your Google calendars.');
        }

        $calendars = collect($resp->json('items', []))
            ->map(fn ($c) => [
                'id' => $c['id'],
                'summary' => $c['summary'] ?? $c['id'],
                'backgroundColor' => $c['backgroundColor'] ?? null,
                'primary' => $c['primary'] ?? false,
                'access_role' => $c['accessRole'] ?? null,
            ])
            ->filter(fn ($c) => in_array($c['access_role'], ['owner', 'writer', 'reader']))
            ->values()
            ->all();

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

        $request->session()->put('calendar_connect_pending', [
            'provider' => 'google',
            'account_email' => $googleUser->getEmail(),
            'account_name' => $googleUser->getName(),
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $expiresIn,
            'calendars' => $calendars,
            'task_lists' => $taskLists,
        ]);

        return redirect()->route('calendar.index', ['picking' => 'google']);
    }

    /** Return the pending picker state (calendars + task lists) for the UI. */
    public function pendingPicker(Request $request): JsonResponse
    {
        $pending = $request->session()->get('calendar_connect_pending');
        if (! $pending) {
            return response()->json(['pending' => null]);
        }
        return response()->json([
            'pending' => [
                'provider' => $pending['provider'],
                'account_email' => $pending['account_email'],
                'account_name' => $pending['account_name'],
                'calendars' => $pending['calendars'] ?? [],
                'task_lists' => $pending['task_lists'] ?? [],
            ],
        ]);
    }

    /** Create CalendarConnection rows for each chosen calendar AND task list. */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'external_calendar_ids' => 'nullable|array',
            'external_calendar_ids.*' => 'string',
            'external_task_list_ids' => 'nullable|array',
            'external_task_list_ids.*' => 'string',
        ]);

        $pending = $request->session()->get('calendar_connect_pending');
        if (! $pending) {
            return redirect()->route('calendar.index')->with('status', 'Connection state expired. Please connect again.');
        }

        $tokenFields = [
            'account_name' => $pending['account_name'],
            'access_token' => $pending['access_token'],
            'refresh_token' => $pending['refresh_token'],
            'token_expires_at' => now()->addSeconds($pending['expires_in'] - 60),
            'is_active' => true,
        ];

        $calendarsById = collect($pending['calendars'] ?? [])->keyBy('id');
        foreach ((array) ($validated['external_calendar_ids'] ?? []) as $calId) {
            $cal = $calendarsById->get($calId);
            if (! $cal) continue;
            CalendarConnection::updateOrCreate(
                [
                    'user_id' => Auth::id(),
                    'provider' => $pending['provider'],
                    'kind' => 'calendar',
                    'account_email' => $pending['account_email'],
                    'external_calendar_id' => $cal['id'],
                ],
                array_merge($tokenFields, [
                    'external_calendar_name' => $cal['summary'],
                    'external_calendar_color' => $cal['backgroundColor'],
                ]),
            );
        }

        $tasksById = collect($pending['task_lists'] ?? [])->keyBy('id');
        foreach ((array) ($validated['external_task_list_ids'] ?? []) as $listId) {
            $list = $tasksById->get($listId);
            if (! $list) continue;
            CalendarConnection::updateOrCreate(
                [
                    'user_id' => Auth::id(),
                    'provider' => $pending['provider'],
                    'kind' => 'tasks',
                    'account_email' => $pending['account_email'],
                    'external_calendar_id' => $list['id'],
                ],
                array_merge($tokenFields, [
                    'external_calendar_name' => $list['title'],
                    'external_calendar_color' => null,
                ]),
            );
        }

        // When the same Google account is reconnected with a refreshed scope,
        // propagate the new tokens to all of that account's existing rows so
        // refresh-token rotation stays in sync.
        CalendarConnection::where('user_id', Auth::id())
            ->where('account_email', $pending['account_email'])
            ->update([
                'access_token' => $pending['access_token'],
                'refresh_token' => $pending['refresh_token'],
                'token_expires_at' => now()->addSeconds($pending['expires_in'] - 60),
            ]);

        $request->session()->forget('calendar_connect_pending');

        return redirect()->route('calendar.index')->with('status', 'Connected.');
    }

    /** Toggle a connection's active state. */
    public function toggle(Request $request, CalendarConnection $connection): RedirectResponse
    {
        abort_unless($connection->user_id === Auth::id(), 403);
        $connection->update(['is_active' => ! $connection->is_active]);
        return back();
    }

    /** Disconnect (soft delete + clear tokens). */
    public function destroy(Request $request, CalendarConnection $connection): RedirectResponse
    {
        abort_unless($connection->user_id === Auth::id(), 403);
        $connection->update(['access_token' => null, 'refresh_token' => null, 'is_active' => false]);
        $connection->delete();
        return back()->with('status', 'Calendar disconnected.');
    }

    /** Fetch full details for a single Google event (for the editor dialog). */
    public function showEvent(Request $request, CalendarConnection $connection, string $eventId, GoogleCalendarService $service): JsonResponse
    {
        abort_unless($connection->user_id === Auth::id(), 403);
        $event = $service->getEvent($connection, $eventId);
        if (! $event) {
            return response()->json(['error' => 'Event not found'], 404);
        }
        return response()->json(['event' => $event]);
    }

    /** Update a Google event's full content (title, description, location, times). */
    public function updateEvent(Request $request, CalendarConnection $connection, string $eventId, GoogleCalendarService $service): RedirectResponse
    {
        abort_unless($connection->user_id === Auth::id(), 403);
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
        $service->updateEvent($connection, $eventId, $validated);
        return back();
    }

    /** Delete a Google event. */
    public function deleteEvent(Request $request, CalendarConnection $connection, string $eventId, GoogleCalendarService $service): RedirectResponse
    {
        abort_unless($connection->user_id === Auth::id(), 403);
        $service->deleteEvent($connection, $eventId);
        return back();
    }

    // ── Google Tasks endpoints ──────────────────────────────────────────

    /** Fetch a single Google Task with full details. */
    public function showTask(Request $request, CalendarConnection $connection, string $taskId, GoogleTasksService $service): JsonResponse
    {
        abort_unless($connection->user_id === Auth::id() && $connection->kind === 'tasks', 403);
        $task = $service->getTask($connection, $taskId);
        if (! $task) return response()->json(['error' => 'Task not found'], 404);
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

    // ── Google Calendar event endpoints ─────────────────────────────────

    /** Create an event on a specific Google calendar (called from QuickCreate). */
    public function createEvent(Request $request, CalendarConnection $connection, GoogleCalendarService $service): RedirectResponse
    {
        abort_unless($connection->user_id === Auth::id(), 403);
        $validated = $request->validate([
            'title' => 'required|string|max:500',
            'date' => 'required|date_format:Y-m-d',
            'time' => 'nullable|date_format:H:i',
            'end_date' => 'nullable|date_format:Y-m-d',
            'end_time' => 'nullable|date_format:H:i',
            'description' => 'nullable|string|max:5000',
            'timezone' => 'nullable|string|max:64',
        ]);

        $service->createEvent($connection, $validated);

        return back();
    }
}
