<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The answer to a bootstrap claim: `granted` (this client loads `html` / `name` / `icon` into the empty
 * document, then saves once with `baseline: true`) or `wait` (another client does; the text arrives through
 * the sync — ask again after a few seconds if it doesn't).
 *
 * @property array{status: string, html?: string|null, name?: string, icon?: string|null} $resource
 */
class CollabBootstrapResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var 'granted'|'wait' */
            'status' => (string) $this->resource['status'],
            'html' => isset($this->resource['html']) ? (string) $this->resource['html'] : null,
            'name' => isset($this->resource['name']) ? (string) $this->resource['name'] : null,
            'icon' => isset($this->resource['icon']) ? (string) $this->resource['icon'] : null,
        ];
    }
}
