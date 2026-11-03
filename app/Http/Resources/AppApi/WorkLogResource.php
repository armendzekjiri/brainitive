<?php

namespace App\Http\Resources\AppApi;

use App\Models\BillingActivity;
use App\Models\Item;
use App\Models\TimeEntry;
use App\Support\Billing\TimeEntries;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One piece of logged time (BRAI-400) — never a rate or an amount. The issue's live key / title / link
 * only while the user can still open it; otherwise the snapshot taken when it was logged (`url: null`).
 * `locked`: on a finalized billing report, read-only. Needs TimeEntries::WITH + `workspace`.
 *
 * @mixin TimeEntry
 */
class WorkLogResource extends JsonResource
{
    /** @param  array<int, int>  $accessible  project ids the user can open, as keys */
    public function __construct(TimeEntry $entry, private readonly array $accessible = [])
    {
        parent::__construct($entry);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $entry = $this->resource;
        $item = TimeEntries::visibleIssue($entry, $this->accessible);
        $activity = $entry->activity;

        return [
            'id' => $this->id,
            // Y-m-d, the day the work was done
            'spent_on' => $entry->spent_on->toDateString(),
            'minutes' => (int) $this->minutes,
            // 1h 30m
            'duration' => Item::formatDuration((int) $this->minutes),
            'note' => $this->note,
            'billable' => (bool) $this->billable,
            // manual | timer | legacy (logged before the timesheet existed: only date, activity and note change)
            'source' => $this->source,
            // billed on a finalized report: no edits, no deletes
            'locked' => $entry->isLocked(),
            // id null + "Deleted or moved project" once it moved away or is out of reach (Codex web review S)
            'project' => TimeEntries::visibleProject($entry, $this->accessible),
            'issue' => $entry->item_id || $entry->item_key ? [
                'id' => $entry->item_id === null ? null : (int) $entry->item_id,
                'work_id' => $item ? 'issue:'.$item->id : null,
                'key' => $item?->key ?? $entry->item_key,
                'title' => $item?->title ?? $entry->item_title,
                'url' => $item ? $this->issueUrl($item) : null,
            ] : null,
            'activity' => $activity instanceof BillingActivity ? [
                'id' => (int) $activity->id,
                'name' => (string) $activity->name,
                'color' => $activity->color,
                'billable' => (bool) $activity->billable,
                'archived' => $activity->archived_at !== null,
            ] : null,
            'workspace' => [
                'slug' => (string) $entry->workspace->slug,
                'name' => (string) $entry->workspace->name,
            ],
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }

    private function issueUrl(Item $item): string
    {
        $board = $item->board;

        return route('boards.items.show', [$board->project->workspace->slug, $board->project->slug, $board->slug, $item->id]);
    }
}
