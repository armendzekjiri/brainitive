<?php

namespace App\Http\Resources\AppApi;

use App\Models\Page;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The answer to a live draft save: `saved`, `unchanged` (same text), `covered` (an older or concurrent
 * snapshot — another client's save holds everything; nothing written) or `baseline`; plus the page's revision.
 *
 * @property array{status: string, page: Page} $resource
 */
class CollabSaveResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $page = $this->resource['page'];

        return [
            /** @var 'saved'|'unchanged'|'covered'|'baseline' */
            'status' => (string) $this->resource['status'],
            'revision' => (int) $page->edit_revision,
            'has_unpublished_changes' => $page->hasUnpublishedChanges(),
        ];
    }
}
