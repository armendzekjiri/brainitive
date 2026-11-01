<?php

namespace App\Http\Requests\AppApi;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH /api/v1/me` (BRAI-384): the fields the web Profile page edits, each optional. Same rules
 * as the web (ProfileUpdateRequest). The email address is web only: a new one must be verified.
 */
class UpdateMeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // auth:app — the user edits themselves
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...array_map(fn (array $rules) => ['sometimes', ...$rules], ProfileUpdateRequest::profileRules()),
            ...ProfileUpdateRequest::preferenceRules(),
            'email' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.prohibited' => 'Change your email address in the web app: the new one has to be verified.',
        ];
    }
}
