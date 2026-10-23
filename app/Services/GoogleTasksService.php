<?php

namespace App\Services;

use App\Models\CalendarConnection;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around the Google Tasks API. Uses the same access tokens stored
 * on CalendarConnection (rows where kind = 'tasks').
 */
class GoogleTasksService
{
    private const API_BASE = 'https://tasks.googleapis.com/tasks/v1';

    public function __construct(private GoogleCalendarService $calendarService) {}

    /** Same token-refresh behaviour as the Calendar service. */
    private function ensureFreshToken(CalendarConnection $connection): bool
    {
        return $this->calendarService->ensureFreshToken($connection);
    }

    /** Fetch the user's task lists. Returns array of {id, title}. */
    public function listTaskLists(CalendarConnection $connection): array
    {
        if (! $this->ensureFreshToken($connection)) {
            return [];
        }
        $resp = $this->get($connection, '/users/@me/lists');
        $items = $resp->json('items', []);

        return array_map(fn ($l) => [
            'id' => $l['id'],
            'title' => $l['title'] ?? $l['id'],
        ], $items);
    }

    /** Fetch all tasks from a list. */
    public function listTasks(CalendarConnection $connection, ?Carbon $dueMin = null, ?Carbon $dueMax = null): array
    {
        if (! $this->ensureFreshToken($connection)) {
            return [];
        }
        $listId = rawurlencode($connection->external_calendar_id);
        $query = ['showCompleted' => 'true', 'showHidden' => 'true', 'maxResults' => 100];
        if ($dueMin) {
            $query['dueMin'] = $dueMin->copy()->startOfDay()->toRfc3339String();
        }
        if ($dueMax) {
            $query['dueMax'] = $dueMax->copy()->endOfDay()->toRfc3339String();
        }
        $resp = $this->get($connection, "/lists/{$listId}/tasks", $query);

        return $resp->json('items', []);
    }

    /** Fetch a single task with all details. */
    public function getTask(CalendarConnection $connection, string $taskId): ?array
    {
        if (! $this->ensureFreshToken($connection)) {
            return null;
        }
        $listId = rawurlencode($connection->external_calendar_id);
        $resp = $this->get($connection, "/lists/{$listId}/tasks/{$taskId}");
        if (! $resp->successful()) {
            return null;
        }

        return $resp->json();
    }

    /**
     * Create a task.
     *
     * @param  array{title:string,date?:string,time?:?string,description?:?string,timezone?:?string}  $data
     */
    public function createTask(CalendarConnection $connection, array $data): ?array
    {
        if (! $this->ensureFreshToken($connection)) {
            return null;
        }
        $listId = rawurlencode($connection->external_calendar_id);
        $body = ['title' => $data['title']];
        if (! empty($data['description'])) {
            $body['notes'] = $data['description'];
        }
        $body['due'] = $this->buildDueField($data['date'] ?? null, $data['time'] ?? null, $data['timezone'] ?? null);

        $resp = Http::withToken($connection->access_token)
            ->acceptJson()
            ->post(self::API_BASE."/lists/{$listId}/tasks", $body);

        if (! $resp->successful()) {
            Log::warning('Google task create failed', [
                'connection_id' => $connection->id,
                'status' => $resp->status(),
                'body' => $resp->json(),
            ]);

            return null;
        }

        return $resp->json();
    }

    /**
     * Patch a task.
     *
     * @param  array{title?:string,description?:?string,date?:?string,time?:?string,completed?:?bool,timezone?:?string}  $data
     */
    public function updateTask(CalendarConnection $connection, string $taskId, array $data): ?array
    {
        if (! $this->ensureFreshToken($connection)) {
            return null;
        }
        $listId = rawurlencode($connection->external_calendar_id);
        $body = [];
        if (isset($data['title'])) {
            $body['title'] = $data['title'];
        }
        if (array_key_exists('description', $data)) {
            $body['notes'] = $data['description'];
        }
        if (array_key_exists('date', $data) || array_key_exists('time', $data)) {
            if (empty($data['date'])) {
                $body['due'] = null;
            } else {
                $body['due'] = $this->buildDueField($data['date'], $data['time'] ?? null, $data['timezone'] ?? null);
            }
        }
        if (array_key_exists('completed', $data)) {
            if ($data['completed']) {
                $body['status'] = 'completed';
                $body['completed'] = now()->toRfc3339String();
            } else {
                $body['status'] = 'needsAction';
                $body['completed'] = null;
            }
        }

        $resp = Http::withToken($connection->access_token)
            ->acceptJson()
            ->patch(self::API_BASE."/lists/{$listId}/tasks/{$taskId}", $body);

        if (! $resp->successful()) {
            Log::warning('Google task update failed', [
                'connection_id' => $connection->id,
                'task_id' => $taskId,
                'status' => $resp->status(),
                'body' => $resp->json(),
            ]);

            return null;
        }

        return $resp->json();
    }

    public function deleteTask(CalendarConnection $connection, string $taskId): bool
    {
        if (! $this->ensureFreshToken($connection)) {
            return false;
        }
        $listId = rawurlencode($connection->external_calendar_id);
        $resp = Http::withToken($connection->access_token)
            ->delete(self::API_BASE."/lists/{$listId}/tasks/{$taskId}");

        return $resp->successful() || $resp->status() === 410;
    }

    /** Build the `due` field. Google Tasks now supports time-of-day. */
    private function buildDueField(?string $date, ?string $time, ?string $tz): ?string
    {
        if (! $date) {
            return null;
        }
        $tz = $tz ?: config('app.timezone');
        if ($time) {
            return Carbon::parse("{$date} {$time}", $tz)->toRfc3339String();
        }

        // Date-only: use UTC midnight (Google convention for all-day tasks)
        return Carbon::parse($date, 'UTC')->startOfDay()->toRfc3339String();
    }

    private function get(CalendarConnection $connection, string $path, array $query = []): Response
    {
        return Http::withToken($connection->access_token)
            ->acceptJson()
            ->get(self::API_BASE.$path, $query);
    }
}
