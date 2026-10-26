<?php

namespace App\Support\Push;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * e2e only (APP_ENV=e2e + WEBPUSH_FAKE): a Guzzle handler standing in for the browser push services
 * (BRAI-154). The endpoint path picks the answer, so e2e journeys can register "devices" that
 * behave like the real failure cases:
 *
 * - `…/e2e-expired…`  → 410 Gone (the browser dropped the subscription)
 * - `…/e2e-rejected…` → 403 with a VAPID-style reason
 * - `…/e2e-offline…`  → connection error
 * - anything else      → 201 Created (accepted)
 */
class FakePushService
{
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $path = $request->getUri()->getPath();

        if (str_contains($path, 'e2e-offline')) {
            return Create::rejectionFor(new ConnectException('Could not resolve host', $request));
        }

        return Create::promiseFor(match (true) {
            str_contains($path, 'e2e-expired') => new Response(410, [], 'push subscription has unsubscribed or expired.'),
            str_contains($path, 'e2e-rejected') => new Response(403, ['Content-Type' => 'application/json'], '{"reason":"BadJwtToken"}'),
            default => new Response(201),
        });
    }
}
