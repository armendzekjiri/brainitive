<?php

namespace App\Support\Github;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * e2e only (APP_ENV=e2e + GITHUB_APP_FAKE): GitHub's API answered in-process, so the connect flow
 * runs without a network or a real app. Stateless: the installation id travels inside the fake
 * code / tokens, so parallel tests each get their own installation and repositories:
 * code "e2e-{id}" → user token "gho_e2e_{id}" → /user/installations = [{id}];
 * installation {id} → repositories "e2e-org/site-{id}" ({id}1) and "e2e-org/api-{id}" ({id}2).
 */
final class FakeGithub
{
    public const SLUG = 'brainitive-e2e';

    public const WEBHOOK_SECRET = 'e2e-github-app-secret';

    private static bool $registered = false;

    public static function registerIfEnabled(): void
    {
        if (self::$registered || ! GithubAppConfig::fake()) {
            return;
        }
        self::$registered = true;

        $id = fn (string $value, string $prefix) => str_starts_with($value, $prefix) ? (int) substr($value, strlen($prefix)) : 0;
        $bearer = fn (Request $r) => trim(substr($r->header('Authorization')[0] ?? '', 7));

        Http::fake([
            'github.com/login/oauth/access_token' => function (Request $r) use ($id) {
                $installation = $id((string) ($r->data()['code'] ?? ''), 'e2e-');

                return $installation > 0
                    ? Http::response(['access_token' => 'gho_e2e_'.$installation, 'token_type' => 'bearer'])
                    : Http::response(['error' => 'bad_verification_code']);
            },
            'api.github.com/user/installations/*/repositories*' => function (Request $r) {
                preg_match('#/user/installations/(\d+)/repositories#', $r->url(), $m);
                $installation = $m[1] ?? '0';

                return Http::response(['total_count' => 2, 'repositories' => [
                    ['id' => (int) ($installation.'1'), 'full_name' => "e2e-org/site-{$installation}", 'private' => true],
                    ['id' => (int) ($installation.'2'), 'full_name' => "e2e-org/api-{$installation}", 'private' => false],
                ]]);
            },
            'api.github.com/user/installations*' => function (Request $r) use ($id, $bearer) {
                $installation = $id($bearer($r), 'gho_e2e_');

                return Http::response(['total_count' => 1, 'installations' => [['id' => $installation, 'account' => ['login' => 'e2e-org', 'type' => 'Organization']]]]);
            },
            'api.github.com/app/installations/*/access_tokens' => function (Request $r) {
                preg_match('#/app/installations/(\d+)/#', $r->url(), $m);

                return Http::response(['token' => 'ghs_e2e_'.($m[1] ?? 0), 'expires_at' => now()->addHour()->toIso8601String()], 201);
            },
            'api.github.com/app/installations/*' => function (Request $r) {
                preg_match('#/app/installations/(\d+)#', $r->url(), $m);

                return Http::response(['id' => (int) ($m[1] ?? 0), 'account' => ['login' => 'e2e-org', 'type' => 'Organization'], 'repository_selection' => 'selected', 'suspended_at' => null]);
            },
            'api.github.com/installation/repositories*' => function (Request $r) use ($id, $bearer) {
                $installation = $id($bearer($r), 'ghs_e2e_');

                return Http::response(['total_count' => 2, 'repositories' => [
                    ['id' => (int) ($installation.'1'), 'full_name' => "e2e-org/site-{$installation}", 'private' => true],
                    ['id' => (int) ($installation.'2'), 'full_name' => "e2e-org/api-{$installation}", 'private' => false],
                ]]);
            },
            'api.github.com/repos/*' => Http::response(['message' => 'Not Found'], 404),
        ]);
    }
}
