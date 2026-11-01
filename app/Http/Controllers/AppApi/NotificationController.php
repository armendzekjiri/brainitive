<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\ListNotificationsRequest;
use App\Http\Requests\AppApi\UpdateNotificationPreferencesRequest;
use App\Http\Resources\AppApi\NotificationCollection;
use App\Http\Resources\AppApi\NotificationPreferencesResource;
use App\Http\Resources\AppApi\UnreadCountResource;
use App\Notifications\NotificationCatalog;
use App\Support\Notifications\NotificationInbox;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App API v1 — notifications (BRAI-393): the web's inbox (NotificationInbox) for native
 * notifications, the dock / tray badge and the mobile inbox, and the per-category channel
 * preferences of Profile → Notifications. Only the user's own rows; another id is a 404. Every
 * change is announced to the user's web tabs (the bell follows at once).
 */
#[Group('Notifications', weight: 110)]
class NotificationController extends Controller
{
    /** The inbox, newest first: `filter` = all | unread | mentions | assigned; `archived=1` for the archive. */
    public function index(ListNotificationsRequest $request): NotificationCollection
    {
        return new NotificationCollection(
            NotificationInbox::page($request->user(), $request->filter(), $request->archived(), $request->cursor(), $request->limit()),
        );
    }

    /** The badge count: unread notifications in the inbox. */
    public function unreadCount(Request $request): UnreadCountResource
    {
        return new UnreadCountResource(NotificationInbox::unread($request->user()));
    }

    /**
     * Mark one notification read; answers the new unread count.
     *
     * @throws NotFoundHttpException not one of the user's notifications (404)
     */
    public function read(Request $request, string $id): UnreadCountResource
    {
        return new UnreadCountResource(NotificationInbox::apply($request->user(), [$this->uuid($id)], 'read'));
    }

    /** Mark every inbox notification read. */
    public function readAll(Request $request): UnreadCountResource
    {
        NotificationInbox::markAllRead($request->user());

        return new UnreadCountResource(0);
    }

    /**
     * Archive one notification (it leaves the inbox and counts as read); answers the new unread count.
     *
     * @throws NotFoundHttpException not one of the user's notifications (404)
     */
    public function archive(Request $request, string $id): UnreadCountResource
    {
        return new UnreadCountResource(NotificationInbox::apply($request->user(), [$this->uuid($id)], 'archive'));
    }

    /** Per category, which channels deliver (in-app, push, email). */
    public function preferences(Request $request): NotificationPreferencesResource
    {
        return new NotificationPreferencesResource($request->user());
    }

    /** Change channels per category; only the sent ones change. */
    public function updatePreferences(UpdateNotificationPreferencesRequest $request): NotificationPreferencesResource
    {
        $user = $request->user();
        NotificationCatalog::updatePreferences($user, $request->validated('preferences'));

        return new NotificationPreferencesResource($user->fresh());
    }

    /** Notification ids are UUIDs: anything else can't be one of the user's (and Postgres won't compare it). */
    private function uuid(string $id): string
    {
        abort_unless(Str::isUuid($id), 404);

        return $id;
    }
}
