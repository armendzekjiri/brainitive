<?php

namespace App\Http\Resources\AppApi;

use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A workspace the signed-in user belongs to, with their role in it (workspace list and switcher,
 * accept invitation, create workspace). The slug addresses it in every other endpoint.
 *
 * @mixin Workspace
 */
class WorkspaceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            // from the membership row when the list loaded it (no query per workspace)
            'role' => $this->resource->pivot?->role ?? $user?->workspaceRole($this->resource),
            'is_owner' => $user !== null && $this->owner_id === $user->id,
            'logo_url' => $this->logo_display,
            'url' => route('workspaces.show', $this->slug),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
