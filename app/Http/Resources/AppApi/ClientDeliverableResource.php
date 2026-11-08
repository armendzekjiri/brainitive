<?php

namespace App\Http\Resources\AppApi;

use App\Support\ApiKeys\ApiKeyAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A deliverable shared in a client portal (Deliverables::summary): a published doc or plan snapshot, its
 * version and the client's approval state.
 *
 * @property-read array{id: int, uuid: string, kind: string, title: string, version: int, status: string, decision: string, published_at: string|null, published_by: string|null, decisions_count: int|null, comments_count: int|null, last_decision_at: string|null} $resource
 */
class ClientDeliverableResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $d = $this->resource;

        return [
            'id' => (int) $d['id'],
            'uuid' => (string) $d['uuid'],
            // page (a doc) | plan (a Gantt plan)
            'kind' => (string) $d['kind'],
            // a doc's / plan's name: empty for an API key without Docs / Plans read
            'title' => ApiKeyAccess::reads($d['kind'] === 'page' ? 'docs' : 'plans') ? (string) $d['title'] : '',
            'version' => (int) $d['version'],
            // awaiting | approved | changes_requested, or superseded (a newer version exists) / withdrawn
            'status' => (string) $d['status'],
            // the client's decision on this version: awaiting | approved | changes_requested
            'decision' => (string) $d['decision'],
            'published_at' => self::zulu($d['published_at']),
            'published_by' => $d['published_by'],
            'decisions_count' => (int) ($d['decisions_count'] ?? 0),
            'comments_count' => (int) ($d['comments_count'] ?? 0),
            'last_decision_at' => self::zulu($d['last_decision_at']),
        ];
    }

    protected static function zulu(?string $at): ?string
    {
        return $at === null ? null : Carbon::parse($at)->toIso8601ZuluString();
    }
}
