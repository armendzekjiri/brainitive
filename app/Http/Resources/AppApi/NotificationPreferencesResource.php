<?php

namespace App\Http\Resources\AppApi;

use App\Models\User;
use App\Notifications\NotificationCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Profile → Notifications (BRAI-393): per category, which channels deliver — `in_app` (the bell),
 * `push` (browser push today, the apps' push later) and `email`; the effective choice (the user's
 * or the default).
 *
 * @property-read User $resource
 */
class NotificationPreferencesResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'categories' => collect(NotificationCatalog::forUser($this->resource))->map(fn (array $category) => [
                'key' => (string) $category['key'],
                'label' => (string) $category['label'],
                'description' => (string) $category['description'],
                'channels' => [
                    'in_app' => (bool) ($category['channels']['in_app'] ?? false),
                    'push' => (bool) ($category['channels']['push'] ?? false),
                    'email' => (bool) ($category['channels']['email'] ?? false),
                ],
            ])->values()->all(),
        ];
    }
}
