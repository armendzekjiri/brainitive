<?php

namespace App\Http\Requests\AppApi;

use App\Models\AppDevice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/auth/exchange: the one-time code from brainitive://auth/callback, the PKCE
 * verifier and the device that signs in. Whether the platform fits the client is checked after
 * the code is known (AuthController::exchange).
 */
class ExchangeCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:128'],
            // RFC 7636: 43–128 unreserved characters
            'code_verifier' => ['required', 'string', 'min:43', 'max:128', 'regex:/^[A-Za-z0-9\-._~]+$/'],
            'device' => ['required', 'array'],
            'device.name' => ['required', 'string', 'max:120'],
            'device.platform' => ['required', 'string', Rule::in(AppDevice::PLATFORMS)],
            'device.app_version' => ['required', 'string', 'max:32'],
            'device.os_version' => ['nullable', 'string', 'max:64'],
        ];
    }
}
