<?php

namespace App\Http\Resources\AppApi;

use App\Models\User;
use App\Models\Workspace;
use App\Support\AppApi\AppTokens;
use App\Support\Entitlements\Entitlements;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /api/v1/me` (BRAI-384): the signed-in user, their workspaces with the Cloud limits of each
 * (Entitlements contract; all null = unlimited, as on self-hosted installs) and the device of the
 * current app token. Explicit fields only — never the model's toArray().
 *
 * There is no language or theme on the server (the theme is per browser), so neither is here.
 *
 * @property-read User $resource
 */
class MeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $this->resource;
        $entitlements = app(Entitlements::class);
        $device = AppTokens::currentDevice($request);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            // true also when the instance does not require verification (User::hasVerifiedEmail)
            'email_verified' => $user->hasVerifiedEmail(),
            'email_verified_at' => $user->email_verified_at?->toIso8601ZuluString(),
            'avatar_url' => $user->avatar_display,
            'bio' => $user->bio,
            'phone' => $user->phone,
            // null until a browser or app sent one; effective_timezone is what reminders use
            'timezone' => $user->timezone,
            'effective_timezone' => $user->localTimezone(),
            'task_digest' => (bool) $user->task_digest,
            'is_instance_admin' => $user->isInstanceAdmin(),
            'created_at' => $user->created_at?->toIso8601ZuluString(),
            'limits_enforced' => $entitlements->enforced(),
            'workspaces' => $user->workspaces
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->map(fn (Workspace $workspace) => $this->workspace($workspace, $user, $entitlements))
                ->all(),
            'device' => $device ? [
                'id' => $device->id,
                'name' => $device->name,
                'client' => $device->client,
                'platform' => $device->platform,
                'app_version' => $device->app_version,
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function workspace(Workspace $workspace, User $user, Entitlements $entitlements): array
    {
        $limits = $entitlements->limits($workspace);

        return [
            'slug' => $workspace->slug,
            'name' => $workspace->name,
            'role' => $workspace->pivot?->role,
            'is_owner' => $workspace->owner_id === $user->id,
            'logo_url' => $workspace->logo_display,
            'limits' => [
                'plan_name' => $limits->plan,
                'free' => $limits->free,
                'unlimited' => $limits->isUnlimited(),
                'members' => $limits->members,
                'projects' => $limits->projects,
                'content_bytes' => $limits->contentBytes,
                'file_bytes' => $limits->fileBytes,
                'versions' => [
                    'keep_latest' => $limits->versions->keepLatest,
                    'keep_days' => $limits->versions->keepDays,
                ],
                'grace_until' => $limits->graceUntil?->toIso8601ZuluString(),
            ],
            // "Plan & billing" on the web; null on self-hosted installs
            'billing_url' => $entitlements->billingUrl($workspace),
        ];
    }
}
