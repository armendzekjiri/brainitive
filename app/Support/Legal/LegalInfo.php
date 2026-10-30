<?php

namespace App\Support\Legal;

use Illuminate\Support\Carbon;

/**
 * Who runs this installation and which legal documents it shows (BRAI-156). The single reader
 * of config/legal.php: legal pages, footers and the operator line on customers' public pages.
 *
 * Brainitive Cloud (brainitive.app) gets its known facts as defaults; a self-hosted install
 * shows only its own operator's configured values — never Brainitive's.
 */
class LegalInfo
{
    /** Hosts of Brainitive Cloud (production + staging), when LEGAL_CLOUD isn't set explicitly. */
    public const CLOUD_HOSTS = ['brainitive.app', 'www.brainitive.app', 'dev.brainitive.app'];

    /**
     * Version + date of every document. Bump the version when the content changes materially;
     * the terms version is stored with each new account (users.terms_version).
     */
    public const DOCUMENTS = [
        'privacy' => ['version' => '2.2', 'updated' => '2026-10-27'], // v1.45.0: booking pages
        'terms' => ['version' => '2.1', 'updated' => '2026-10-30'], // v1.46.0: BSL 1.1 licence
        'cookies' => ['version' => '2.1', 'updated' => '2026-10-27'],
        'imprint' => ['version' => '1.0', 'updated' => '2026-10-26'],
        'subprocessors' => ['version' => '1.0', 'updated' => '2026-10-26'],
        'dpa' => ['version' => '1.0', 'updated' => '2026-10-26'],
    ];

    /** Facts about Brainitive Cloud given by its operator (2026-10-26); see docs/legal/README.md. */
    private const CLOUD_DEFAULTS = [
        'operator' => [
            'email' => 'hello@brainitive.app',
            'description' => 'Brainitive is run by an individual (sole proprietor), not by a company.',
        ],
        'providers' => [
            'hosting' => 'OVHcloud',
            'hosting_location' => 'France (EU)', // operator, 2026-10-27
            'email' => 'MXroute',
            'backup' => 'OVHcloud (a separate server)',
            'backup_location' => 'France (EU)', // operator, 2026-10-27
        ],
    ];

    public static function isCloud(): bool
    {
        $explicit = config('legal.cloud');
        if ($explicit !== null) {
            return (bool) $explicit;
        }

        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && in_array(strtolower($host), self::CLOUD_HOSTS, true);
    }

    /**
     * The operator's details; empty values are null (pages don't render those rows).
     *
     * @return array<string, string|null>
     */
    public static function operator(): array
    {
        $configured = array_map(fn ($v) => filled($v) ? trim((string) $v) : null, (array) config('legal.operator', []));

        if (self::isCloud()) {
            foreach (self::CLOUD_DEFAULTS['operator'] as $key => $value) {
                $configured[$key] ??= $value;
            }
        }

        return $configured;
    }

    /**
     * Processors run by the operator (hosting, e-mail, backups) — Cloud defaults or configured.
     *
     * @return array<string, string|null>
     */
    public static function providers(): array
    {
        $configured = array_map(fn ($v) => filled($v) ? trim((string) $v) : null, (array) config('legal.providers', []));

        if (self::isCloud()) {
            foreach (self::CLOUD_DEFAULTS['providers'] as $key => $value) {
                $configured[$key] ??= $value;
            }
        }

        return $configured;
    }

    /**
     * Self-hosted operators' own documents (https URLs only).
     *
     * @return array{privacy_url: string|null, terms_url: string|null, imprint_url: string|null}
     */
    public static function externalDocuments(): array
    {
        $docs = (array) config('legal.documents', []);
        $safe = fn ($url) => is_string($url) && preg_match('#^https?://#i', $url) ? $url : null;

        return [
            'privacy_url' => $safe($docs['privacy_url'] ?? null),
            'terms_url' => $safe($docs['terms_url'] ?? null),
            'imprint_url' => $safe($docs['imprint_url'] ?? null),
        ];
    }

    /** Is there anything to put on an imprint page? Cloud always has one (contact at least). */
    public static function hasImprint(): bool
    {
        if (self::isCloud()) {
            return true;
        }
        $op = self::operator();

        return filled($op['name'] ?? null) || filled($op['address'] ?? null) || filled($op['email'] ?? null)
            || filled(self::externalDocuments()['imprint_url']);
    }

    /** Where "Imprint" links to: our page, a self-hosted operator's own imprint, or nowhere. */
    public static function imprintUrl(): ?string
    {
        if (! self::hasImprint()) {
            return null;
        }
        $op = self::operator();
        $external = self::externalDocuments()['imprint_url'];
        if (! self::isCloud() && $external && ! filled($op['name'] ?? null) && ! filled($op['address'] ?? null)) {
            return $external;
        }

        return route('imprint');
    }

    /**
     * Footer links for every page (shared Inertia prop `legal`). Cloud-only documents (DPA,
     * sub-processors) are null on self-hosted installs; the imprint is null when there's none.
     *
     * @return array<string, mixed>
     */
    public static function links(): array
    {
        $cloud = self::isCloud();

        return [
            'cloud' => $cloud,
            'privacy' => route('privacy'),
            'terms' => route('terms'),
            'cookies' => route('cookies'),
            'imprint' => self::imprintUrl(),
            'subprocessors' => $cloud ? route('legal.subprocessors') : null,
            'dpa' => $cloud ? route('legal.dpa') : null,
            'refund' => $cloud ? route('refund') : null,
        ];
    }

    /**
     * Everything a legal page needs.
     *
     * @return array<string, mixed>
     */
    public static function pageProps(string $document): array
    {
        return [
            'legal' => [
                ...self::links(),
                'document' => ['key' => $document, ...self::DOCUMENTS[$document]],
                'operator' => self::operator(),
                'providers' => self::providers(),
                'external' => self::externalDocuments(),
                'retention' => array_map(fn ($v) => filled($v) ? trim((string) $v) : null, (array) config('legal.retention', [])),
                // Cloud documents are about Brainitive itself, whatever APP_NAME says
                'app_name' => self::isCloud() ? 'Brainitive' : (string) config('app.name', 'Brainitive'),
                'session_cookie' => (string) config('session.cookie'),
                'analytics' => self::isCloud() && filled(config('legal.analytics.script')),
                'host' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: null,
            ],
        ];
    }

    public static function termsVersion(): string
    {
        return self::DOCUMENTS['terms']['version'];
    }

    /**
     * Columns for a new account created through a sign-up form that shows the Terms notice.
     *
     * @return array{terms_accepted_at: Carbon, terms_version: string}
     */
    public static function termsAcceptance(): array
    {
        return ['terms_accepted_at' => now(), 'terms_version' => self::termsVersion()];
    }
}
