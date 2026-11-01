<?php

namespace App\Http\Requests\AppApi;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Foundation\Http\FormRequest;

/** `POST /api/v1/me/avatar` (BRAI-384): multipart `avatar`, the web Profile page's image rules. */
class UpdateAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // auth:app — the user edits themselves
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'avatar' => ['required', 'file', ...ProfileUpdateRequest::avatarRules()],
        ];
    }
}
