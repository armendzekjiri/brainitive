<?php

namespace App\Http\Requests\AppApi;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/v1/auth/device/token: the `device_code` of POST /auth/device. */
class DeviceTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // a wrong one is answered as `invalid_grant`, not a validation error
            'device_code' => ['required', 'string', 'max:256'],
        ];
    }
}
