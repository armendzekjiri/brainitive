<?php

namespace App\Http\Resources\AppApi;

use App\Models\InstanceSettings;
use App\Support\AppVersion;
use App\Support\Auth\SignInMethods;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /api/v1/instance` (BRAI-354): what the desktop first-run screen and the mobile app show
 * before anyone signs in. Public — so only what the login page already reveals, nothing secret.
 *
 * @property-read InstanceSettings|null $resource
 */
class InstanceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->resource?->name ?: config('app.name'),
            'url' => rtrim((string) config('app.url'), '/'),
            'version' => AppVersion::current(),
            'api_version' => '1',
            'sign_in' => SignInMethods::all(),
            'sign_up_open' => InstanceSettings::allowsRegistration(),
            'email_verification_required' => InstanceSettings::requiresEmailVerification(),
            'min_versions' => [
                'desktop' => (string) config('app-api.min_versions.desktop'),
                'mobile' => (string) config('app-api.min_versions.mobile'),
            ],
            'authorize_path' => '/app/authorize',
        ];
    }
}
