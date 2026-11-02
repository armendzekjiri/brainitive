<?php

namespace App\Http\Requests\AppApi;

use App\Models\AppDevice;
use App\Support\AppApi\AppClients;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /api/v1/auth/device: a device without a usable browser (the TV app) starts a device-code
 * sign-in (RFC 8628). Same `device` object as /auth/exchange; the platform must be one of the client's.
 */
class StartDeviceAuthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // clients that sign in with a device code (today: `tv`)
            'client' => ['required', 'string', Rule::in(AppClients::using(AppClients::DEVICE_CODE))],
            'device' => ['required', 'array'],
            'device.name' => ['required', 'string', 'max:120'],
            'device.platform' => ['required', 'string', Rule::in(AppDevice::PLATFORMS)],
            'device.app_version' => ['required', 'string', 'max:32'],
            'device.os_version' => ['nullable', 'string', 'max:64'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator) {
            $client = $this->input('client');
            $platform = $this->input('device.platform');
            if (is_string($client) && is_string($platform) && ! $validator->errors()->hasAny(['client', 'device.platform'])
                && ! AppClients::runsOn($client, $platform)) {
                $validator->errors()->add('device.platform', 'This platform does not belong to the '.$client.' app.');
            }
        }];
    }
}
