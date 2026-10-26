<?php

namespace App\Support\Github;

use App\Models\GithubApp;
use Illuminate\Support\Facades\Cache;

/**
 * The instance's effective GitHub App (BRAI-61): GITHUB_APP_* env vars when GITHUB_APP_ID is set,
 * otherwise the app an instance admin created through the manifest flow (github_apps, encrypted),
 * or — only with APP_ENV=e2e and GITHUB_APP_FAKE — a fake app whose GitHub API is faked
 * (FakeGithub). Secrets stay in this object: never put it in a response or a log line.
 */
final class GithubAppConfig
{
    public function __construct(
        public readonly string $source, // env | stored | fake
        public readonly int $appId,
        public readonly string $slug,
        public readonly ?string $clientId,
        #[\SensitiveParameter] public readonly ?string $clientSecret,
        #[\SensitiveParameter] public readonly string $privateKey,
        #[\SensitiveParameter] public readonly string $webhookSecret,
        public readonly ?string $name = null,
        public readonly ?string $owner = null,
        public readonly ?string $htmlUrl = null,
    ) {}

    /** The configured app, or null when none is complete (connect falls back to manual webhooks). */
    public static function current(): ?self
    {
        if (self::fake()) {
            return self::fakeApp();
        }

        $env = config('services.github_app');
        if (filled($env['app_id'] ?? null)) {
            $key = self::pem((string) ($env['private_key'] ?? ''));
            if ($key === '' || blank($env['slug'] ?? null) || blank($env['webhook_secret'] ?? null)) {
                return null;
            }

            return new self('env', (int) $env['app_id'], (string) $env['slug'], $env['client_id'] ?: null, $env['client_secret'] ?: null, $key, (string) $env['webhook_secret'],
                htmlUrl: 'https://github.com/apps/'.$env['slug']);
        }

        $stored = self::stored();
        if (! $stored || blank($stored->private_key) || blank($stored->webhook_secret)) {
            return null;
        }

        return new self('stored', $stored->app_id, $stored->slug, $stored->client_id, $stored->client_secret, (string) $stored->private_key, (string) $stored->webhook_secret,
            $stored->name, $stored->owner, $stored->html_url);
    }

    public static function stored(): ?GithubApp
    {
        return GithubApp::query()->latest('id')->first();
    }

    /** e2e only: a fake app + faked GitHub API (no network). */
    public static function fake(): bool
    {
        return app()->environment('e2e') && (bool) config('services.github_app.fake');
    }

    /** Env vars set the app — the admin console can't create or delete one then. */
    public static function fromEnv(): bool
    {
        return filled(config('services.github_app.app_id'));
    }

    /** Users can authorize during install (needed to prove an installation is theirs). */
    public function canVerifyInstallations(): bool
    {
        return filled($this->clientId) && filled($this->clientSecret);
    }

    /** GitHub's install / repository picker for this app; `state` comes back to the setup URL. */
    public function installUrl(string $state): string
    {
        return 'https://github.com/apps/'.rawurlencode($this->slug).'/installations/new?state='.rawurlencode($state);
    }

    public function publicUrl(): string
    {
        return $this->htmlUrl ?: 'https://github.com/apps/'.rawurlencode($this->slug);
    }

    /** A PEM given inline (with literal \n escapes) or as a path to the .pem file. */
    private static function pem(string $value): string
    {
        $value = trim($value);
        if ($value !== '' && ! str_contains($value, '-----BEGIN') && is_file($value) && is_readable($value)) {
            $value = trim((string) file_get_contents($value));
        }

        return str_replace('\n', "\n", $value);
    }

    private static function fakeApp(): self
    {
        // Any RSA key signs the JWT; the faked API never checks it. Generated once per e2e database.
        $key = Cache::rememberForever('github-app:e2e-private-key', function () {
            $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($res, $pem);

            return $pem;
        });

        return new self('fake', 1, FakeGithub::SLUG, 'Iv1.e2efake', 'e2e-client-secret', $key, FakeGithub::WEBHOOK_SECRET, 'Brainitive (e2e)', 'e2e-org');
    }
}
