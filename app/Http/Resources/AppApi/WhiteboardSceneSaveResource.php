<?php

namespace App\Http\Resources\AppApi;

use App\Models\Page;
use App\Models\PageVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The answer to a live scene save (the web's fields): `saved`, `unchanged` (same elements, images and library),
 * `covered` (an older or concurrent snapshot — another client's save holds everything; nothing written) or
 * `baseline`; the page's edit revision and the version a checkpoint recorded.
 *
 * @property array{status: string, page: Page, version: PageVersion|null} $resource
 */
class WhiteboardSceneSaveResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var 'saved'|'unchanged'|'covered'|'baseline' */
            'status' => (string) $this->resource['status'],
            'edit_revision' => (int) $this->resource['page']->edit_revision,
            // the version number this save recorded (a checkpoint: Save, or every few minutes of drawing); null when none
            'version' => $this->resource['version']?->version,
        ];
    }
}
