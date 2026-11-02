<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new workspace: the web form (WorkspaceController::store) and the apps' first run
 * (POST /api/v1/workspaces). Names are unique per owner among their live workspaces.
 */
class StoreWorkspaceRequest extends FormRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('workspaces', 'name')
                    ->where(fn ($q) => $q
                        ->where('owner_id', $this->user()->id)
                        ->whereNull('deleted_at')),
            ],
            'description' => 'nullable|string|max:1000',
        ];
    }
}
