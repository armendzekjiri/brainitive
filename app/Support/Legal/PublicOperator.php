<?php

namespace App\Support\Legal;

use App\Models\Workspace;

/**
 * The "who runs this page" line on a customer's public page (BRAI-156): support page, ticket
 * portal, lead form, feedback / client portal, share links, public plans and changelogs.
 *
 * The workspace (our customer) is the controller of its visitors' data; the platform operator
 * only processes it on the customer's behalf. So the page names the workspace (or the legal name
 * it set in Workspace settings → Legal) and links ITS privacy policy / imprint; the platform's
 * privacy policy is linked only for the platform part (hosting, security logs).
 */
class PublicOperator
{
    /**
     * $nameFallback: without a legal name, show the workspace name — only on pages that always showed
     * the organisation (support page / widget, lead forms). Token links (share links, plans,
     * changelogs, portals, ticket links) don't reveal an internal workspace name: there the operator
     * stays generic ("named" = false) until the admin sets a legal name.
     *
     * @return array{name: string, named: bool, privacy_url: string|null, imprint_url: string|null, configured: bool, platform_name: string, platform_privacy_url: string}
     */
    public static function for(?Workspace $workspace, bool $nameFallback = false): array
    {
        $https = fn ($url) => is_string($url) && str_starts_with(strtolower($url), 'https://') ? $url : null;

        $name = match (true) {
            $workspace && filled($workspace->legal_controller_name) => $workspace->legal_controller_name,
            $workspace && $nameFallback => $workspace->name,
            default => null,
        };
        $privacy = $workspace ? $https($workspace->legal_privacy_url) : null;
        $imprint = $workspace ? $https($workspace->legal_imprint_url) : null;

        return [
            'name' => (string) ($name ?: 'the organisation that shared this page'),
            'named' => filled($name),
            'privacy_url' => $privacy,
            'imprint_url' => $imprint,
            'configured' => $privacy !== null || $imprint !== null || filled($workspace?->legal_controller_name),
            'platform_name' => self::platformName(),
            'platform_privacy_url' => route('privacy'),
        ];
    }

    /**
     * One plain line for e-mails a visitor gets from a customer's page (BRAI-156): who sends it, and
     * that customer's privacy policy. Safe for Markdown mail lines (names stripped, URL escaped).
     */
    /** The platform's name in public texts: APP_NAME, but never the framework default "Laravel". */
    public static function platformName(): string
    {
        $name = trim((string) config('app.name'));

        return $name === '' || strcasecmp($name, 'Laravel') === 0 ? 'Brainitive' : $name;
    }

    public static function mailLine(?Workspace $workspace, bool $nameFallback = false): string
    {
        $op = self::for($workspace, $nameFallback);
        $name = trim(preg_replace('/[\[\]()<>*_`#!|\\\\]/', '', str_replace(["\r", "\n"], ' ', $op['name'])));
        $line = "This e-mail was sent by {$name} using {$op['platform_name']}.";
        if ($op['privacy_url']) {
            $line .= ' Privacy policy: '.strtr($op['privacy_url'], ['(' => '%28', ')' => '%29', '[' => '%5B', ']' => '%5D', '<' => '%3C', '>' => '%3E', '*' => '%2A', '`' => '%60', '|' => '%7C', '\\' => '%5C', '!' => '%21', ' ' => '%20']);
        }

        return $line;
    }
}
