<?php

namespace App\Support\Helpdesks\Inbound;

use App\Models\Helpdesk;
use Illuminate\Support\Str;

/**
 * A helpdesk's forwarding address (BRAI-124): `<slug>-<random>@<INBOUND_MAIL_DOMAIN>`. Customers (or
 * a Gmail / Outlook auto-forward) send mail there; the provider posts it to
 * /api/inbound-mail/{provider} (InboundMailController), which finds the helpdesk with resolve().
 *
 * Only the local part is stored (`helpdesks.forwarding_local_part`, unique, $hidden), so changing the
 * domain moves every address with it. Regenerating replaces it: the old address stops working.
 */
final class ForwardingAddress
{
    public const PROVIDERS = ['postmark', 'mailgun'];

    private const RANDOM_LENGTH = 10;

    public static function domain(): ?string
    {
        $domain = strtolower(trim((string) config('services.inbound_mail.domain'), " \t\n\r\0\x0B@."));

        return $domain === '' ? null : $domain;
    }

    /** The provider the settings describe (config), when it is configured. */
    public static function provider(): ?string
    {
        $provider = strtolower((string) config('services.inbound_mail.provider'));

        return self::providerEnabled($provider) ? $provider : null;
    }

    /** True when the domain and this provider's secret are set; otherwise its endpoint answers 404. */
    public static function providerEnabled(string $provider): bool
    {
        return self::domain() !== null && match ($provider) {
            'postmark' => filled(config('services.inbound_mail.postmark_token')),
            'mailgun' => filled(config('services.inbound_mail.mailgun_signing_key')),
            default => false,
        };
    }

    public static function enabled(): bool
    {
        return self::provider() !== null;
    }

    /** The helpdesk's address, created on first use; null while the feature is off. */
    public static function for(Helpdesk $helpdesk): ?string
    {
        if (! self::enabled()) {
            return null;
        }
        if (! $helpdesk->forwarding_local_part) {
            // only if still empty: two tabs opening the Email tab at once agree on one address
            Helpdesk::whereKey($helpdesk->id)->whereNull('forwarding_local_part')
                ->update(['forwarding_local_part' => self::newLocalPart($helpdesk)]);
            $helpdesk->forwarding_local_part = Helpdesk::whereKey($helpdesk->id)->value('forwarding_local_part');
            $helpdesk->syncOriginalAttribute('forwarding_local_part');
        }

        return $helpdesk->forwarding_local_part.'@'.self::domain();
    }

    /** A new address for the helpdesk; the old one stops working at once. */
    public static function regenerate(Helpdesk $helpdesk): string
    {
        $helpdesk->forceFill(['forwarding_local_part' => self::newLocalPart($helpdesk)])->save();

        return $helpdesk->forwarding_local_part.'@'.self::domain();
    }

    /**
     * The helpdesk an envelope recipient belongs to, or null. The domain must be ours; a
     * `+tag` (sub-addressing, e.g. a reply routing token) is ignored for the lookup.
     */
    public static function resolve(string $recipient): ?Helpdesk
    {
        $domain = self::domain();
        $recipient = strtolower(trim($recipient));
        if ($domain === null || substr_count($recipient, '@') !== 1) {
            return null;
        }
        [$local, $host] = explode('@', $recipient);
        if ($host !== $domain) {
            return null;
        }
        $local = explode('+', $local, 2)[0];
        if ($local === '' || strlen($local) > 80) {
            return null;
        }

        return Helpdesk::where('forwarding_local_part', $local)->whereHas('project.workspace')->first();
    }

    /** `<slug>-<10 random [a-z0-9]>`: about 52 bits, so the address can't be guessed from the slug. */
    private static function newLocalPart(Helpdesk $helpdesk): string
    {
        $base = trim(Str::limit(Str::slug($helpdesk->slug ?: $helpdesk->name), 40, ''), '-') ?: 'helpdesk';
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $random = '';
        for ($i = 0; $i < self::RANDOM_LENGTH; $i++) {
            $random .= $alphabet[random_int(0, 35)];
        }

        return $base.'-'.$random;
    }
}
