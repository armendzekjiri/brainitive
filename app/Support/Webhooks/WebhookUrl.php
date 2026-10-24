<?php

namespace App\Support\Webhooks;

use App\Support\OutboundUrl;
use Closure;

/**
 * Where outgoing webhooks may POST to (BRAI-104). Same SSRF guard as every other request to a
 * user-chosen URL (OutboundUrl: public IPs only, pinned resolve, no redirects), checked when the
 * webhook is saved AND again before every attempt (DNS can change in between).
 *
 * - https is required in production and staging.
 * - Private / loopback targets are allowed ONLY when APP_ENV=e2e and WEBHOOKS_ALLOW_PRIVATE_TARGETS
 *   is set (the Playwright receiver on 127.0.0.1). Any other environment ignores the flag.
 */
final class WebhookUrl
{
    public static function allowPrivate(): bool
    {
        return app()->environment('e2e') && (bool) config('webhooks.allow_private_targets');
    }

    public static function requiresHttps(): bool
    {
        return app()->environment(['production', 'staging']);
    }

    /**
     * Guzzle options for a delivery to $url, or null when the URL isn't allowed.
     *
     * @return array<string, mixed>|null
     */
    public static function options(string $url): ?array
    {
        if (self::requiresHttps() && strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return null;
        }

        return OutboundUrl::options($url, self::allowPrivate());
    }

    /**
     * Validation rules for the URL field.
     *
     * @return list<mixed>
     */
    public static function rules(): array
    {
        return ['required', 'string', 'max:2048', 'url:http,https', function (string $attribute, mixed $value, Closure $fail) {
            if (! is_string($value)) {
                return;
            }
            if (self::requiresHttps() && strtolower((string) parse_url($value, PHP_URL_SCHEME)) !== 'https') {
                $fail('The webhook URL must use https.');

                return;
            }
            if (OutboundUrl::options($value, self::allowPrivate()) === null) {
                $fail('The webhook URL must point to a public internet address (no localhost or private networks).');
            }
        }];
    }
}
