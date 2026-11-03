<?php

namespace App\Http\Resources\AppApi;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A member of a workspace or project, for assignee / mention pickers (BRAI-390). `role` is the
 * role in what was listed (workspace: admin | editor | member | viewer; project: admin | editor |
 * viewer). The same fields the web's pickers get — never more of the account.
 *
 * @mixin User
 */
class MemberResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar_url' => $this->avatar_display,
            'role' => self::roleOf($this->resource),
        ];
    }

    private static function roleOf(User $user): ?string
    {
        $role = $user->pivot?->role;

        return $role === null ? null : (string) $role;
    }
}
