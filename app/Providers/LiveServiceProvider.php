<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Live\BoardLive;
use App\Support\Live\Live;
use App\Support\Live\RealtimeSession;
use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Logout;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Live channels (BRAI-151): what Brainitive publishes on its own. Features publish through
 * `Live::publish()` where their change happens; app-wide hooks like the bell live here.
 */
class LiveServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // the bell: a notification stored for someone → their open tabs refresh it at once (the 30 s
        // poll stays as the fallback). Only the id: the browser fetches the row itself.
        Event::listen(NotificationSent::class, function (NotificationSent $event) {
            if ($event->channel === 'database' && $event->notifiable instanceof User && $event->response instanceof DatabaseNotification) {
                Live::publish("user.{$event->notifiable->id}", 'notification.created', ['id' => (string) $event->response->id]);
            }
        });

        // a browser session signs out (logout button, suspension, revoked app webview …): its realtime sockets close
        // and its remaining tokens stop working (Codex realtime review 1). Before the session is invalidated.
        Event::listen([Logout::class, CurrentDeviceLogout::class], fn () => RealtimeSession::end());

        // live boards + plans (BRAI-152): cards, columns, sprints, plans → board.{id} / plan.{id}
        BoardLive::register();
    }
}
