<?php

namespace App\Http\Resources\AppApi;

use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A workspace the signed-in user belongs to, with their role in it (accept invitation, create
 * workspace). The slug addresses it in every other endpoint.
 *
 * @mixin Workspace
 */
class WorkspaceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'role' => $request->user()?->workspaceRole($this->resource),
        ];
    }
}
