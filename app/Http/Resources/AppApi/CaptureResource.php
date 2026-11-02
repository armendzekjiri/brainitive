<?php

namespace App\Http\Resources\AppApi;

use App\Models\Capture;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One capture of the user's private inbox (BRAI-398), addressed by its uuid. `target` is what it was
 * turned into: where it is now while the user can still open it, otherwise the snapshot taken at
 * conversion without a link (`available: false`) — CaptureConversion::location(), as the web.
 *
 * @mixin Capture
 */
class CaptureResource extends JsonResource
{
    /** @param  array<string, mixed>|null  $location  CaptureConversion::location() of a processed capture */
    public function __construct(Capture $capture, private readonly ?array $location = null)
    {
        parent::__construct($capture);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $location = $this->location ?? [];

        return [
            // the uuid
            'id' => $this->uuid,
            'text' => $this->text,
            'url' => $this->url,
            // issue | task | page | bookmark | snippet | null: what the user meant it to become
            'intended_type' => $this->intended_type,
            // where it came from ("Brainitive Desktop", "Browser extension", …)
            'source' => $this->source,
            // inbox | processed | archived
            'state' => $this->resource->state(),
            'captured_at' => $this->captured_at?->toIso8601ZuluString(),
            'processed_at' => $this->processed_at?->toIso8601ZuluString(),
            'archived_at' => $this->archived_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
            'target' => $this->target_type ? [
                // issue | task | page | bookmark | snippet
                'type' => (string) $this->target_type,
                'title' => self::text($location['title'] ?? null),
                'breadcrumb' => self::text($location['breadcrumb'] ?? null),
                // null when the user can't open it any more (or it was deleted)
                'url' => self::link($location),
                'available' => (bool) ($location['available'] ?? false),
            ] : null,
        ];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /** @param  array<string, mixed>  $location */
    private static function link(array $location): ?string
    {
        return ($location['available'] ?? false) && is_string($location['url'] ?? null) ? $location['url'] : null;
    }
}
