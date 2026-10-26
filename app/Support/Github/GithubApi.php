<?php

namespace App\Support\Github;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Read-only calls to GitHub as the instance's GitHub App (BRAI-61). No outbound writes.
 *
 * - App JWT: RS256 signed with openssl (no JWT library), valid 9 minutes.
 * - Installation tokens: fetched with the JWT, cached ENCRYPTED until 5 minutes before they expire.
 * - Rate limits: 429, or 403 with x-ratelimit-remaining 0 / retry-after → GithubRateLimited.
 * Tokens and secrets never appear in exceptions, logs or responses.
 */
final class GithubApi
{
    public const API = 'https://api.github.com';

    private const MAX_PAGES = 10;

    public function __construct(private readonly GithubAppConfig $app) {}

    public static function make(): ?self
    {
        $app = GithubAppConfig::current();

        return $app ? new self($app) : null;
    }

    public function app(): GithubAppConfig
    {
        return $this->app;
    }

    public function jwt(): string
    {
        $encode = fn (string $data) => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        $now = time();
        $unsigned = $encode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
            .'.'.$encode((string) json_encode(['iat' => $now - 60, 'exp' => $now + 540, 'iss' => (string) $this->app->appId]));

        $key = openssl_pkey_get_private($this->app->privateKey);
        if ($key === false || ! openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new GithubApiException('The GitHub App private key is not a valid RSA key.');
        }

        return $unsigned.'.'.$encode($signature);
    }

    public function installationToken(int $installationId): string
    {
        $key = $this->tokenCacheKey($installationId);
        $cached = Cache::get($key);
        if (is_string($cached)) {
            try {
                return Crypt::decryptString($cached);
            } catch (\Throwable) {
                Cache::forget($key);
            }
        }

        $response = $this->send(self::client()->withToken($this->jwt()), 'post', "/app/installations/{$installationId}/access_tokens");
        $token = (string) $response->json('token');
        if ($token === '') {
            throw new GithubApiException('GitHub returned no installation token.');
        }

        $expires = $this->time($response->json('expires_at')) ?? now()->addHour();
        $ttl = (int) max(60, now()->diffInSeconds($expires->copy()->subMinutes(5), false));
        Cache::put($key, Crypt::encryptString($token), $ttl);

        return $token;
    }

    public function forgetInstallationToken(int $installationId): void
    {
        Cache::forget($this->tokenCacheKey($installationId));
    }

    /** @return array{id: int, account_login: ?string, account_type: ?string, repository_selection: ?string, suspended: bool} */
    public function installation(int $installationId): array
    {
        $data = $this->send(self::client()->withToken($this->jwt()), 'get', "/app/installations/{$installationId}")->json();

        return [
            'id' => (int) data_get($data, 'id'),
            'account_login' => data_get($data, 'account.login'),
            'account_type' => data_get($data, 'account.type') ?? data_get($data, 'target_type'),
            'repository_selection' => data_get($data, 'repository_selection'),
            'suspended' => data_get($data, 'suspended_at') !== null,
        ];
    }

    /** @return list<array{id: int, full_name: string, private: bool}> */
    public function installationRepositories(int $installationId): array
    {
        $client = fn () => self::client()->withToken($this->installationToken($installationId));
        $repos = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $response = $this->send($client(), 'get', '/installation/repositories', ['per_page' => 100, 'page' => $page]);
            foreach ((array) $response->json('repositories', []) as $repo) {
                $repos[] = self::repo($repo);
            }
            if (count((array) $response->json('repositories', [])) < 100) {
                break;
            }
        }

        return array_values(array_filter($repos, fn (array $r) => $r['id'] > 0 && $r['full_name'] !== ''));
    }

    /** @return array<string, mixed> the pull request as GitHub's webhook payload has it */
    public function pullRequest(int $installationId, string $repository, int $number): array
    {
        return (array) $this->send(self::client()->withToken($this->installationToken($installationId)), 'get', '/repos/'.$repository.'/pulls/'.$number)->json();
    }

    /** OAuth code from "request user authorization during installation" → user token (never stored). */
    public function userToken(string $code): string
    {
        if (! $this->app->canVerifyInstallations()) {
            throw new GithubApiException('The GitHub App has no client id / secret.');
        }

        $response = self::http()->asForm()->post('https://github.com/login/oauth/access_token', [
            'client_id' => $this->app->clientId,
            'client_secret' => $this->app->clientSecret,
            'code' => $code,
        ]);
        $token = (string) $response->json('access_token');
        if (! $response->successful() || $token === '') {
            throw new GithubApiException('GitHub refused the authorization code ('.($response->json('error') ?: $response->status()).').', $response->status());
        }

        return $token;
    }

    /** @return list<int> the installations of this app the signed-in GitHub user can access */
    public function userInstallationIds(string $userToken): array
    {
        $ids = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $response = $this->send(self::client()->withToken($userToken), 'get', '/user/installations', ['per_page' => 100, 'page' => $page]);
            $installations = (array) $response->json('installations', []);
            foreach ($installations as $installation) {
                $ids[] = (int) data_get($installation, 'id');
            }
            if (count($installations) < 100) {
                break;
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * The installation's repositories the signed-in GitHub user can read. /user/installations lists
     * an installation for anyone with access to ONE of its repositories, so this is what a project
     * may follow — never the whole installation.
     *
     * @return list<int>
     */
    public function userInstallationRepositoryIds(string $userToken, int $installationId): array
    {
        $ids = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $response = $this->send(self::client()->withToken($userToken), 'get', "/user/installations/{$installationId}/repositories", ['per_page' => 100, 'page' => $page]);
            $repos = (array) $response->json('repositories', []);
            foreach ($repos as $repo) {
                $ids[] = (int) data_get($repo, 'id');
            }
            if (count($repos) < 100) {
                break;
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * The manifest flow's last step: GitHub returns the new app with its secrets (shown only once).
     *
     * @return array<string, mixed>
     */
    public static function convertManifest(string $code): array
    {
        $response = self::client()->post('/app-manifests/'.rawurlencode($code).'/conversions');
        if (! $response->successful()) {
            throw new GithubApiException('GitHub could not create the app (HTTP '.$response->status().').', $response->status());
        }

        return (array) $response->json();
    }

    /** @return array{id: int, full_name: string, private: bool} */
    public static function repo(mixed $repo): array
    {
        return [
            'id' => (int) data_get($repo, 'id'),
            'full_name' => mb_substr((string) data_get($repo, 'full_name', ''), 0, 200),
            'private' => (bool) data_get($repo, 'private', false),
        ];
    }

    private static function http(): PendingRequest
    {
        FakeGithub::registerIfEnabled();

        return Http::acceptJson()->timeout(15)->connectTimeout(5)->withHeaders(['User-Agent' => 'Brainitive', 'X-GitHub-Api-Version' => '2022-11-28']);
    }

    private static function client(): PendingRequest
    {
        return self::http()->baseUrl(self::API)->accept('application/vnd.github+json');
    }

    private function send(PendingRequest $client, string $method, string $path, array $query = []): Response
    {
        try {
            $response = $method === 'get' ? $client->get($path, $query) : $client->post($path);
        } catch (ConnectionException) {
            throw new GithubApiException("GitHub could not be reached ({$path}).");
        }

        if ($response->status() === 429 || ($response->status() === 403 && ($response->header('x-ratelimit-remaining') === '0' || $response->header('retry-after') !== ''))) {
            throw new GithubRateLimited($this->retryAt($response));
        }
        if (! $response->successful()) {
            throw new GithubApiException("GitHub answered HTTP {$response->status()} for {$path}.", $response->status());
        }

        return $response;
    }

    private function retryAt(Response $response): Carbon
    {
        $after = $response->header('retry-after');
        if (is_numeric($after)) {
            return now()->addSeconds(max(1, (int) $after));
        }
        $reset = $response->header('x-ratelimit-reset');
        if (is_numeric($reset) && (int) $reset > time()) {
            return Carbon::createFromTimestamp((int) $reset);
        }

        return now()->addMinute();
    }

    private function tokenCacheKey(int $installationId): string
    {
        return 'github-app:installation-token:'.$this->app->appId.':'.$installationId;
    }

    private function time(mixed $value): ?Carbon
    {
        try {
            return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
