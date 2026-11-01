<?php

namespace App\Http\Resources\AppApi;

use App\Models\InboxNotification;
use App\Support\Notifications\NotificationInbox;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One inbox notification (BRAI-393), as the web bell gets it: `type` (e.g. `item_assigned`,
 * `mention`), the catalog `category` (null for an unknown type) and `data`, the type's payload the
 * web renders (actor_name, item_title, url …; it differs per type — show `data.url` when present).
 *
 * @mixin InboxNotification
 */
class NotificationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $notification = $this->resource;

        return [
            'id' => (string) $this->id,
            'type' => NotificationInbox::type($notification),
            // mentions, assignments, comments, due, … (NotificationCatalog::CATEGORIES)
            'category' => NotificationInbox::category($notification),
            'data' => self::payload($notification),
            'read' => $this->read_at !== null,
            'read_at' => $this->read_at?->toIso8601ZuluString(),
            'archived_at' => $this->archived_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }

    /** The type's payload as a JSON object (`{}` when empty, never `[]`). */
    private static function payload(InboxNotification $notification): object
    {
        return (object) NotificationInbox::data($notification);
    }
}
