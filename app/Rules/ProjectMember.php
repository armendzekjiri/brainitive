<?php

namespace App\Rules;

use App\Models\Project;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The user id must belong to someone who can open the project (members + workspace admins).
 * Use for assignees, watchers, etc. — `exists:users,id` alone lets anyone pick (and so look up)
 * any account on the instance.
 */
class ProjectMember implements ValidationRule
{
    public function __construct(private readonly Project $project) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $user = is_numeric($value) ? User::find((int) $value) : null;

        if (! $user || ! $user->hasProjectAccess($this->project)) {
            $fail('Pick a member of this project.');
        }
    }
}
