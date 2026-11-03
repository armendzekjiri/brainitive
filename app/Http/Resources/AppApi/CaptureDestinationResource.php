<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A workspace and what the user can save into there (CaptureDestinations, shared with the web's convert
 * dialog and the browser extension): projects with their boards (+ columns) and task lists, bookmark
 * groups and snippet collections. Only what the user may write to.
 *
 * @property-read array<string, mixed> $resource
 */
class CaptureDestinationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->resource['id'],
            'name' => (string) $this->resource['name'],
            'slug' => (string) $this->resource['slug'],
            // pages, issues (boards) and tasks (task lists) go into projects
            'projects' => CaptureDestinationProjectResource::collection($this->resource['projects']),
            // bookmark destination_id
            'bookmarks' => CaptureDestinationOptionResource::collection($this->resource['bookmarks']),
            // snippet destination_id
            'snippet_collections' => CaptureDestinationOptionResource::collection($this->resource['snippet_collections']),
        ];
    }
}
