<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The answer to a whiteboard bootstrap claim (the web's fields): `granted` (this client writes `elements` into
 * the empty document, then saves once with `baseline: true`) or `wait` (another client does; the elements arrive
 * through the sync — ask again after a few seconds if they don't).
 *
 * @property array{status: string, elements?: list<array<string, mixed>>} $resource
 */
class WhiteboardBootstrapResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var 'granted'|'wait' */
            'status' => (string) $this->resource['status'],
            /**
             * the stored Excalidraw elements to load (granted only; null otherwise)
             *
             * @var list<array<string, mixed>>|null
             */
            'elements' => $this->resource['elements'] ?? null,
            // always false here (a replaced document answers 409 with `reset: true`)
            'reset' => false,
        ];
    }
}
