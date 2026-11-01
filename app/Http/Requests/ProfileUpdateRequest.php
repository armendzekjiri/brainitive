<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...self::profileRules(),
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            'avatar' => ['nullable', ...self::avatarRules()],
        ];
    }

    /**
     * The profile fields besides email and picture; shared with the App API (`PATCH /api/v1/me`).
     *
     * @return array<string, array<int, string>>
     */
    public static function profileRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * A profile picture; shared with the App API (`POST /api/v1/me/avatar`).
     *
     * @return array<int, string>
     */
    public static function avatarRules(): array
    {
        return ['image', 'mimes:jpeg,png,jpg', 'max:2048'];
    }

    /**
     * Small personal settings (`profile.preferences` and `PATCH /api/v1/me`).
     *
     * @return array<string, string>
     */
    public static function preferenceRules(): array
    {
        return [
            // all_with_bc: browsers still report old names such as "Asia/Calcutta".
            'timezone' => 'sometimes|required|string|timezone:all_with_bc',
            'task_digest' => 'sometimes|boolean',
        ];
    }
}
