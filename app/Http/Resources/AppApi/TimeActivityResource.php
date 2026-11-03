<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An activity to log time with (TimeLogActivities::options): what members may see — never a rate.
 *
 * @property-read array{id: int, name: string, color: string|null, pricing_type: string, pricing_label: string, billable: bool} $resource
 */
class TimeActivityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->resource['id'],
            'name' => (string) $this->resource['name'],
            'color' => self::text($this->resource['color']),
            // hourly | fixed
            'pricing_type' => (string) $this->resource['pricing_type'],
            // Per hour | Fixed price
            'pricing_label' => (string) $this->resource['pricing_label'],
            'billable' => (bool) $this->resource['billable'],
        ];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
