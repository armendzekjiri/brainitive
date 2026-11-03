<?php

namespace App\Http\Resources\AppApi;

use App\Models\Capture;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The result of `POST /captures/{id}/convert`: where the new content is and the processed capture.
 * `replayed`: the capture had been converted before (a retry), nothing new was created.
 */
class CaptureConversionResource extends JsonResource
{
    /** @param  array{capture: Capture, location: array<string, mixed>, replayed: bool}  $result  CaptureConversion::convert() */
    public function __construct(array $result, private readonly ?array $current = null)
    {
        parent::__construct($result);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $location = $this->resource['location'];

        return [
            'replayed' => (bool) $this->resource['replayed'],
            'location' => [
                // where it is, as the web names it: board item, task list, page, bookmark, snippet collection
                'type' => self::text($location['type'] ?? null),
                'title' => self::text($location['title'] ?? null),
                'workspace' => self::text($location['workspace'] ?? null),
                'project' => self::text($location['project'] ?? null),
                'breadcrumb' => self::text($location['breadcrumb'] ?? null),
                'url' => self::link($location['url'] ?? null),
            ],
            'capture' => new CaptureResource($this->resource['capture'], $this->current),
        ];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function link(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
