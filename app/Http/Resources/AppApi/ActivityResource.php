<?php

namespace App\Http\Resources\AppApi;

use App\Models\ActivityLog;
use App\Support\ApiKeys\ApiKeyAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry of an issue's history (BRAI-405), as the issue dialog's History tab gets it: `action` (e.g.
 * `created`, `updated`, `moved`, `assigned`, `commented`, `labels_changed`, `field_changed`,
 * `attachment_added`, `work_logged`), `changes` (`{field: {from, to}}`) and `metadata` (per action).
 * Show a generic line for actions the app doesn't know. Needs `user`.
 *
 * @mixin ActivityLog
 */
class ActivityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $actor = $this->resource->user;

        return [
            'id' => (int) $this->id,
            'action' => (string) $this->action,
            // the fix version is Plans & releases: left out of an API key's view without it
            'changes' => (object) (ApiKeyAccess::reads('plans') ? ($this->changes ?? []) : array_diff_key((array) ($this->changes ?? []), ['release_id' => true])),
            'metadata' => (object) ($this->metadata ?? []),
            // null for automations / imports, or when the account is gone
            'user' => $actor ? new PersonResource($actor) : null,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
