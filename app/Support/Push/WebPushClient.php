<?php

namespace App\Support\Push;

use GuzzleHttp\HandlerStack;
use Minishlink\WebPush\WebPush;

/**
 * The one place that builds the WebPush client (BRAI-154). `AppServiceProvider` hands it to the
 * package's `WebPushChannel`, so real notifications and the Profile test push share the same VAPID
 * keys, padding and Guzzle options. Mirrors `WebPushServiceProvider::webPushAuth()` of
 * laravel-notification-channels/webpush.
 *
 * With APP_ENV=e2e + WEBPUSH_FAKE=true the requests are answered by FakePushService (no network).
 */
class WebPushClient
{
    /** Whether the server has VAPID keys: without them no browser can subscribe or receive. */
    public static function isConfigured(): bool
    {
        return filled(config('webpush.vapid.public_key')) && filled(config('webpush.vapid.private_key'));
    }

    /** @param  int  $timeout  seconds; the synchronous Profile test push uses a short one */
    public static function make(int $timeout = 30): WebPush
    {
        $clientOptions = (array) config('webpush.client_options', []);
        // push services (RFC 8030) never redirect; don't follow one away from the allow-listed host
        $clientOptions['allow_redirects'] ??= false;
        if (app()->environment('e2e') && config('webpush.fake')) {
            $clientOptions['handler'] = HandlerStack::create(new FakePushService);
        }

        return (new WebPush(self::auth(), [], $timeout, $clientOptions))
            ->setReuseVAPIDHeaders(true)
            ->setAutomaticPadding(config('webpush.automatic_padding'));
    }

    /** @return array<string, mixed> */
    private static function auth(): array
    {
        if (! self::isConfigured()) {
            return [];
        }

        $vapid = config('webpush.vapid');
        $auth = ['VAPID' => [
            'publicKey' => $vapid['public_key'],
            'privateKey' => $vapid['private_key'],
            'subject' => filled($vapid['subject'] ?? null) ? $vapid['subject'] : url('/'),
        ]];

        if (filled($vapid['pem_file'] ?? null)) {
            $pem = $vapid['pem_file'];
            $auth['VAPID']['pemFile'] = str_starts_with($pem, 'storage') ? base_path($pem) : $pem;
        }

        return $auth;
    }
}
