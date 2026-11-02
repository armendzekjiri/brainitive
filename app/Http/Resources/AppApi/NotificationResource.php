<?php

namespace App\Http\Resources\AppApi;

use App\Models\InboxNotification;
use App\Support\Notifications\NotificationInbox;
use App\Support\Notifications\NotificationLinks;
use App\Support\Notifications\NotificationText;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One inbox notification (BRAI-393), as the web bell gets it: `type` (e.g. `item_assigned`,
 * `mention`), the catalog `category` (null for an unknown type), `summary` + `url` (the bell's sentence as
 * plain text and the page to open, built server side by NotificationText — show these) and `data`, the
 * type's raw payload the web renders (actor_name, item_title, url …; it differs per type).
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
            // the bell's sentence as one line of plain text (no HTML / Markdown, mentions as @Name, ≤ 200 characters)
            'summary' => (string) NotificationText::summary($notification),
            /** @var string|null the page to open: an absolute URL on this server while the user can open it, else null */
            'url' => NotificationText::url($request->user(), $notification, self::links($request)),
            'data' => self::payload($notification),
            'read' => $this->read_at !== null,
            'read_at' => $this->read_at?->toIso8601ZuluString(),
            'archived_at' => $this->archived_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }

    /** One link checker per request (it remembers what it looked up for the whole page). */
    private static function links(Request $request): NotificationLinks
    {
        $links = $request->attributes->get(NotificationLinks::class);
        if (! $links instanceof NotificationLinks) {
            $links = new NotificationLinks($request->user());
            $request->attributes->set(NotificationLinks::class, $links);
        }

        return $links;
    }

    /** The type's payload as a JSON object (`{}` when empty, never `[]`). */
    private static function payload(InboxNotification $notification): object
    {
        return (object) NotificationInbox::data($notification);
    }
}
