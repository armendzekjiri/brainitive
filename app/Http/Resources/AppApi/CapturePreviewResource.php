<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The fields a capture becomes for one destination type (CaptureFields::defaults, the web's convert
 * dialog): issue {title, description}, task {title, notes}, page {title, markdown}, bookmark {type, title,
 * url, content}, snippet {title, content, language, description}. Edit them, then send them as `fields`.
 *
 * @property-read array{type: string, fields: array<string, mixed>} $resource
 */
class CapturePreviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'type' => (string) $this->resource['type'],
            'fields' => $this->fields(),
        ];
    }

    private function fields(): object
    {
        return (object) $this->resource['fields'];
    }
}
