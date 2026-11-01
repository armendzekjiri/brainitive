<?php

namespace App\Http\Resources\AppApi;

use App\Models\Item;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkTimer;
use App\Support\Billing\TimeLogActivities;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The user's focus timer (BRAI-400): running or not, the issue (only while the user can still open it;
 * otherwise `issue: null` — stop or discard only), and what the stop dialog needs (the workspace's
 * activities, whether one is required, the preselected one). Same payload as the web's top-bar pill
 * (WorkTimer::payload).
 */
class TimerStateResource extends JsonResource
{
    public function __construct(?WorkTimer $timer, private readonly User $user)
    {
        parent::__construct($timer);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $timer = $this->resource instanceof WorkTimer ? $this->resource : null;
        $payload = $timer?->payload($this->user);
        $issue = $payload['item'] ?? null;
        $workspace = is_array($issue) ? Workspace::where('slug', $issue['workspace'])->first() : null;
        $item = $workspace ? $timer?->item : null;

        return [
            'running' => $timer !== null,
            'timer' => $timer ? [
                // send it back as timer_id to stop / discard
                'id' => (int) $timer->id,
                'started_at' => $timer->started_at->toIso8601ZuluString(),
                'elapsed_seconds' => (int) $timer->started_at->diffInSeconds(now()),
                // what the stop dialog suggests (at least 1)
                'elapsed_minutes' => (int) $timer->elapsedMinutes(),
                'issue' => is_array($issue) ? [
                    'id' => (int) $issue['id'],
                    'work_id' => 'issue:'.$issue['id'],
                    'key' => self::text($issue['key']),
                    'title' => (string) $issue['title'],
                    'url' => (string) $issue['url'],
                    'workspace' => (string) $issue['workspace'],
                ] : null,
            ] : null,
            // the timer's workspace: pick one when stopping (empty when not running)
            'activities' => TimeActivityResource::collection($workspace ? TimeLogActivities::options($workspace) : []),
            'activity_required' => $workspace !== null && TimeLogActivities::required($workspace),
            'default_activity_id' => $this->defaultActivity($workspace, $item),
        ];
    }

    private function defaultActivity(?Workspace $workspace, ?Item $item): ?int
    {
        return $workspace ? TimeLogActivities::defaultFor($this->user, $workspace, $item) : null;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
