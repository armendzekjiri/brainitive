<?php

namespace App\Http\Resources\AppApi;

use App\Models\Page;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The notes doc of a meeting (MeetingScheduler::notesPage): open it with `GET /pages/{uuid}`.
 *
 * @property-read array{page: Page, url: string} $resource
 */
class MeetingNotesResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            // GET /api/v1/pages/{uuid}
            'uuid' => (string) $this->resource['page']->uuid,
            'name' => (string) $this->resource['page']->name,
            // the web page
            'url' => (string) $this->resource['url'],
        ];
    }
}
