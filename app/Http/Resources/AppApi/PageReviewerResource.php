<?php

namespace App\Http\Resources\AppApi;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A reviewer of a doc review and their decision (ReviewPresenter::summary).
 *
 * @property array{user_id: int|null, name: string, decision: string, comment: string|null, decided_at: string|null, active: bool} $resource
 */
class PageReviewerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            // null when the account was deleted
            'user_id' => $this->resource['user_id'] === null ? null : (int) $this->resource['user_id'],
            'name' => (string) $this->resource['name'],
            /** @var 'pending'|'approved'|'changes_requested' */
            'decision' => (string) $this->resource['decision'],
            'comment' => $this->resource['comment'] === null ? null : (string) $this->resource['comment'],
            'decided_at' => self::zulu($this->resource['decided_at']),
            // false: can't open the project any more (or deleted) — not counted
            'active' => (bool) $this->resource['active'],
        ];
    }

    public static function zulu(?string $iso): ?string
    {
        return $iso === null ? null : CarbonImmutable::parse($iso)->utc()->toIso8601ZuluString();
    }
}
