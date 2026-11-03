<?php

namespace App\Http\Resources\AppApi;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A person shown next to content (watchers, authors): never the email address.
 *
 * @mixin User
 */
class PersonResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $avatar = $this->resource->avatar_display;

        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'avatar_url' => is_string($avatar) ? $avatar : null,
        ];
    }
}
