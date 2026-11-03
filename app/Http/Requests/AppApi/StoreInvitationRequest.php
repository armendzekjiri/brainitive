<?php

namespace App\Http\Requests\AppApi;

use App\Models\Workspace;
use App\Support\Workspaces\WorkspaceInvites;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/v1/workspaces/{slug}/invitations` (BRAI-390): the web's invite rules. Authorized
 * before validation: outside the workspace → 404, members who aren't admins → 403 (nobody else
 * learns who is a member through "already a member").
 */
class StoreInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Workspace $workspace */
        $workspace = $this->route('workspace');
        abort_unless($this->user()->can('view', $workspace), 404);

        return $this->user()->can('update', $workspace);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(WorkspaceInvites::ROLES)],
        ];
    }
}
