<?php

namespace App\Http\Resources\AppApi;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A connected calendar (or Google Tasks list), status only (CalendarEvents::connectionRow): never
 * tokens, passwords or server credentials. Connecting / removing: web only.
 *
 * @property-read array{id: int, provider: string, provider_label: string, kind: string, account_email: string|null, account_name: string|null, external_calendar_name: string|null, external_calendar_color: string|null, is_active: bool, read_only: bool, sync_error: string|null, sync_error_at: string|null} $resource
 */
class CalendarConnectionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $c = $this->resource;

        return [
            'id' => (int) $c['id'],
            // google | outlook | caldav | ics …
            'provider' => (string) $c['provider'],
            'provider_label' => (string) $c['provider_label'],
            // calendar | tasks (Google Tasks)
            'kind' => (string) $c['kind'],
            'account_email' => $this->text($c['account_email']),
            'account_name' => $this->text($c['account_name']),
            'name' => $this->text($c['external_calendar_name']),
            'color' => $this->text($c['external_calendar_color']),
            // false: switched off because access failed (see sync_error): reconnect on the web
            'is_active' => (bool) $c['is_active'],
            // true: events can't be created / changed in it (ICS subscriptions)
            'read_only' => (bool) $c['read_only'],
            // why the last load failed ("Reconnect …"), null when fine
            'sync_error' => $this->text($c['sync_error']),
            'sync_error_at' => $c['sync_error_at'] !== null ? Carbon::parse($c['sync_error_at'])->toIso8601ZuluString() : null,
        ];
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
