<?php

namespace App\Http\Requests\AppApi;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/v1/auth/refresh: the current refresh token (`brr_…`, single use). */
class RefreshTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // anything longer than our tokens is answered as an invalid grant, not a validation error
            'refresh_token' => ['required', 'string', 'max:4096'],
        ];
    }
}
