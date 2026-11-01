<?php

namespace App\Http\Resources\AppApi;

use App\Models\CalendarFeed;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The user's private calendar feed (BRAI-142): the secret ICS link to subscribe to in Apple / Google /
 * Outlook Calendar, and what it contains. `enabled: false` → every link is null and `options` are the defaults.
 *
 * @property-read CalendarFeed|null $resource
 */
class CalendarFeedResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $feed = $this->resource;
        $links = $feed?->subscribeLinks();

        return [
            'enabled' => $feed !== null,
            // the secret https link (anyone with it can read the feed: PUT … then POST /me/calendar-feed/reset if it leaked)
            'url' => $feed?->url(),
            // the same as webcal:// (opens the OS calendar app)
            'webcal_url' => $feed?->webcalUrl(),
            // "subscribe" links of each calendar app
            'links' => $links === null ? null : [
                'apple' => (string) $links['apple'],
                'google' => (string) $links['google'],
                'outlook' => (string) $links['outlook'],
            ],
            // what the feed contains: assigned, created, tasks, my_day, focus, completed, descriptions (bool), project_ids (empty = all)
            'options' => $this->options($feed?->resolvedOptions() ?? CalendarFeed::DEFAULT_OPTIONS),
            'created_at' => $feed?->created_at?->toIso8601ZuluString(),
            // when a calendar app last fetched it
            'last_fetched_at' => $feed?->last_fetched_at?->toIso8601ZuluString(),
            'fetch_count' => (int) ($feed?->fetch_count ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{assigned: bool, created: bool, tasks: bool, my_day: bool, focus: bool, completed: bool, descriptions: bool, project_ids: list<int>}
     */
    private function options(array $options): array
    {
        return [
            'assigned' => (bool) $options['assigned'],
            'created' => (bool) $options['created'],
            'tasks' => (bool) $options['tasks'],
            'my_day' => (bool) $options['my_day'],
            'focus' => (bool) $options['focus'],
            'completed' => (bool) $options['completed'],
            'descriptions' => (bool) $options['descriptions'],
            'project_ids' => array_values(array_map('intval', (array) $options['project_ids'])),
        ];
    }
}
