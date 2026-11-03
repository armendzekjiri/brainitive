<?php

use App\Support\AppApi\OpenApi\AppApiAuthenticationErrors;
use App\Support\AppApi\OpenApi\AppApiAuthorizationErrors;
use App\Support\AppApi\OpenApi\AppApiHttpErrors;
use App\Support\AppApi\OpenApi\AppApiLimitReachedErrors;
use App\Support\AppApi\OpenApi\AppApiNotFoundErrors;
use App\Support\AppApi\OpenApi\AppApiRateLimitResponse;
use App\Support\AppApi\OpenApi\AppApiValidationErrors;
use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;
use Dedoc\Scramble\OpenApiVersion;

/*
| OpenAPI spec of the App API v1 (BRAI-381) — the desktop and mobile apps only. The committed export
| is docs/api/openapi.json:  php artisan scramble:export --path=docs/api/openapi.json
| (tests/Feature/AppApi/OpenApiSpecTest.php fails when it is stale). Security scheme, server
| variable and the `viewApiDocs` gate: App\Providers\AppApiDocsServiceProvider.
|
| Keep the generated document independent of the environment (APP_URL, APP_NAME, the release
| version): the same code must always export the same file.
*/

return [

    // Only /api/v1. The MCP server, the CLI and the extension APIs (/mcp, /api/cli, /api/ext) and
    // incoming webhooks are separate surfaces and never appear here. One static include → the paths
    // are relative to the server URL (/instance, /auth/exchange …).
    'api_path' => 'api/v1',

    'api_domain' => null,

    'export_path' => 'docs/api/openapi.json',

    'cache' => [
        'key' => 'scramble.openapi',
        'store' => 'file',
    ],

    'openapi_version' => OpenApiVersion::V3_1,

    'info' => [
        // The API's own version, NOT the app release: only a breaking or additive API change bumps it.
        'version' => '1.0.0',

        'description' => <<<'MD'
The JSON API of the first-party **Brainitive Desktop** and **Brainitive Mobile** apps. Every
Brainitive server (Brainitive Cloud or self-hosted) serves it under `/api/v1`.

It is separate from the MCP server, the CLI and the browser extension: own tokens, own guard, own
rate limits. Conventions (full text in `docs/ai/API.md` of the repository):

- **Auth:** `Authorization: Bearer bra_...` - a 15-minute access token from the native sign-in
  (`/app/authorize` in the system browser -> `POST /auth/exchange`). Refresh with
  `POST /auth/refresh` (`brr_...`, 60 days, single use; replaying an old one revokes the
  device). Tokens of the MCP / CLI / extension clients get 401 here. The TV app (device-code
  sign-in, `POST /auth/device`) may only call a read-mostly list of endpoints (`docs/ai/API.md`);
  anything else answers 403 `client_not_allowed`.
- **Errors:** always `{"message": "...", "code": "...", "errors"?: {"field": ["..."]}}` - 401
  `unauthenticated`, 403 `forbidden` (or `client_not_allowed`: not for this app), 404 `not_found`,
  409 `conflict`, 422 `validation_failed`,
  429 `rate_limited`. A 404 can also mean "exists, but not for you".
- **Dates:** ISO-8601 UTC.
- **Lists:** cursor pagination `?cursor=&limit=` (default 50, max 100) -> `{data, next_cursor}`;
  `updated_since` for sync.
- **Writes:** `Idempotency-Key` accepted; updates carry `revision` / `updated_at` -> 409 on conflict.
MD,
    ],

    'ui' => [
        'title' => 'Brainitive App API',
    ],

    'dev_tools' => [
        'enabled' => env('SCRAMBLE_DEV_TOOLS', false),
    ],

    'renderer' => 'elements',

    'renderers' => [
        'elements' => [
            'view' => 'scramble::docs',
            'theme' => 'light',
            'hideTryIt' => false,
            'hideSchemas' => false,
            'logo' => '',
            // the docs page is signed in with the web session; the API itself takes Bearer tokens only
            'tryItCredentialsPolicy' => 'omit',
            'layout' => 'responsive',
            'router' => 'hash',
        ],
        'scalar' => [
            'view' => 'scramble::scalar',
            'cdn' => 'https://cdn.jsdelivr.net/npm/@scalar/api-reference',
            'theme' => 'laravel',
            'proxyUrl' => 'https://proxy.scalar.com',
            'darkMode' => false,
            'showDeveloperTools' => 'never',
            'agent' => ['disabled' => true],
            'credentials' => 'omit',
        ],
    ],

    // The one server is every Brainitive instance; `{instance}` is defined in AppApiDocsServiceProvider.
    // A full URL, so the export never depends on APP_URL.
    'servers' => [
        'Your Brainitive server' => 'https://{instance}/api/v1',
    ],

    'enum_cases_description_strategy' => 'description',

    'enum_cases_names_strategy' => false,

    'flatten_deep_query_parameters' => true,

    // /docs/api and /docs/api.json: the `local` environment, otherwise instance admins only
    // (gate `viewApiDocs`). Everyone else gets 403.
    'middleware' => [
        'web',
        RestrictedDocsAccess::class,
    ],

    // The App API error shape {message, code, errors?} for every documented error response, and
    // the 429 of throttle:app-api on every operation. Later extensions override Scramble's own.
    'extensions' => [
        AppApiValidationErrors::class,
        AppApiAuthorizationErrors::class,
        AppApiAuthenticationErrors::class,
        AppApiHttpErrors::class,
        AppApiNotFoundErrors::class,
        AppApiLimitReachedErrors::class,
        AppApiRateLimitResponse::class,
    ],

    // Security is set in AppApiDocsServiceProvider (always one Bearer scheme, public routes opt out).
    'security_strategy' => null,
];
