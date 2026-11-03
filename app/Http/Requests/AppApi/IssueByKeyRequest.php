<?php

namespace App\Http\Requests\AppApi;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** `GET /api/v1/items/by-key/{key}?workspace=`. */
class IssueByKeyRequest extends FormRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            // the workspace's slug, when the key exists in several of the user's workspaces
            'workspace' => ['sometimes', 'string', 'max:255'],
        ];
    }

    public function workspaceSlug(): ?string
    {
        $slug = $this->validated('workspace');

        return is_string($slug) && $slug !== '' ? $slug : null;
    }
}
