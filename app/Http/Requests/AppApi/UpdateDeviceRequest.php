<?php

namespace App\Http\Requests\AppApi;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/v1/devices/current: the calling app updates what it knows about itself (after an
 * update, a rename, or once it has a push token). Only the sent fields change; a null push_token
 * removes it.
 */
class UpdateDeviceRequest extends FormRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:32'],
            'os_version' => ['sometimes', 'nullable', 'string', 'max:64'],
            // APNs device token / FCM registration token; stored encrypted, never returned
            'push_token' => ['sometimes', 'nullable', 'string', 'max:4096'],
        ];
    }
}
