<?php

namespace App\Notifications\Channels;

use App\Jobs\SendMobilePush;
use App\Models\AppDevice;
use App\Models\User;
use App\Support\Webhooks\WebhookDispatcher;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Push to the user's mobile app installs (BRAI-448), next to browser push: NotificationCatalog adds it
 * whenever the category's `push` is on, so it honours exactly the same preference as WebPushChannel.
 *
 * The message is the notification's toWebPush() (title, body, url) — the phone never shows more than
 * the browser push does. This channel only finds the eligible devices (active, client `mobile`, with
 * a push token) and hands their ids to SendMobilePush, ≤ batch_size per job; it never calls Expo
 * itself, so a request is never slowed down. On a sync queue in a web request the job runs after the
 * response. Tokens never leave the database row: the job carries device ids.
 */
class MobilePushChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! config('app-api.push.enabled') || ! $notifiable instanceof User || ! method_exists($notification, 'toWebPush')) {
            return;
        }

        $deviceIds = AppDevice::query()->active()
            ->where('user_id', $notifiable->id)
            ->where('client', 'mobile')
            ->whereNotNull('push_token')
            ->orderBy('id')
            ->pluck('id')
            ->all();
        if ($deviceIds === []) {
            return;
        }

        $message = $notification->toWebPush($notifiable, $notification)->toArray();
        $title = Str::limit(trim((string) ($message['title'] ?? '')), 100);
        $body = Str::limit(trim((string) ($message['body'] ?? '')), 240);
        if ($title === '' && $body === '') {
            return;
        }
        $url = self::webPath($message['data']['url'] ?? null);

        foreach (array_chunk($deviceIds, max(1, (int) config('app-api.push.batch_size', 100))) as $chunk) {
            $job = new SendMobilePush($notifiable->id, $chunk, $title, $body, $url, $notification->id);

            if (WebhookDispatcher::syncQueue() && ! app()->runningInConsole()) {
                dispatch($job)->afterResponse();
            } else {
                dispatch($job);
            }
        }
    }

    /**
     * The web path the app opens on tap (`/workspaces/acme/…`, with query and fragment): an absolute URL
     * on this server or a relative one become a path; another host or scheme (mailto: …) → null.
     */
    public static function webPath(mixed $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }
        $parts = parse_url(trim($url));
        if ($parts === false) {
            return null;
        }
        if (isset($parts['host'])) {
            $ours = parse_url((string) config('app.url'), PHP_URL_HOST);
            if (! is_string($ours) || strcasecmp($parts['host'], $ours) !== 0) {
                return null;
            }
        } elseif (isset($parts['scheme'])) {
            return null;
        }

        return '/'.ltrim($parts['path'] ?? '', '/')
            .(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }
}
