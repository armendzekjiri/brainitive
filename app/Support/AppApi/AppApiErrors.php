<?php

namespace App\Support\AppApi;

use App\Support\ApiKeys\ApiKeys;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The one error shape of the App API v1 (BRAI-380): `{message, code, errors?}`. Registered in
 * bootstrap/app.php for every `api/v1/*` request, so a missing route, a policy denial and a
 * validation failure all look the same to the desktop and mobile apps.
 */
final class AppApiErrors
{
    /** Status → machine-readable code. */
    public const CODES = [
        400 => 'bad_request',
        401 => 'unauthenticated',
        402 => 'limit_reached',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        409 => 'conflict',
        410 => 'gone',
        413 => 'payload_too_large',
        422 => 'validation_failed',
        429 => 'rate_limited',
        500 => 'server_error',
        503 => 'unavailable',
    ];

    /**
     * Codes that refine a status (sent instead of its default code):
     *  - 400 / 401 `invalid_grant` (sign-in codes, refresh tokens), 400 RFC 8628 device-code errors;
     *  - 403 `client_not_allowed`: the calling app may not use this endpoint at all (the TV's narrow
     *    scope, `config/app-api.php` → `clients.*.abilities`) — not a permission of this record.
     */
    public const CLIENT_NOT_ALLOWED = 'client_not_allowed';

    /**
     * Personal API keys (Developer API, v1.52.0):
     *  - 401 `api_key_revoked` / `api_key_expired` / `api_keys_disabled` (switched off on this server);
     *  - 403 `key_endpoint_not_allowed`: never with an API key (account, devices, sync …);
     *  - 403 `key_ability_denied`: the key's area level (or the delete flag) doesn't cover it;
     *  - 403 `key_scope_denied`: outside the key's workspaces / projects / boards;
     *  - 403 `workspace_api_keys_disabled`: the workspace's admins turned API keys off.
     */
    public const KEY_ENDPOINT_NOT_ALLOWED = 'key_endpoint_not_allowed';

    public const KEY_ABILITY_DENIED = 'key_ability_denied';

    public const KEY_SCOPE_DENIED = 'key_scope_denied';

    public const WORKSPACE_API_KEYS_DISABLED = 'workspace_api_keys_disabled';

    /** 401 messages of a refused personal API key, by code. */
    public const KEY_FAILURES = [
        'api_key_revoked' => 'This API key was revoked.',
        'api_key_expired' => 'This API key has expired.',
        'api_keys_disabled' => 'API keys are turned off on this Brainitive server.',
    ];

    public static function applies(Request $request): bool
    {
        return $request->is('api/v1', 'api/v1/*');
    }

    /** Called with the prepared exception (AuthorizationException → 403, ModelNotFound → 404 …). */
    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! self::applies($request)) {
            return null;
        }

        if ($e instanceof ValidationException) {
            $response = self::json(422, $e->getMessage(), $e->errors());

            return $e instanceof ValidationWithExtras && $e->extras !== []
                ? response()->json([...$response->getData(true), ...$e->extras], 422)
                : $response;
        }

        if ($e instanceof AuthenticationException) {
            $failure = $request->attributes->get(ApiKeys::FAILURE_ATTRIBUTE);

            return is_string($failure) && isset(self::KEY_FAILURES[$failure])
                ? self::json(401, self::KEY_FAILURES[$failure], code: $failure)
                : self::json(401, 'Unauthenticated.');
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $message = $e->getMessage() !== '' && $status < 500 ? $e->getMessage() : self::defaultMessage($status);

            return self::json($status, $message)->withHeaders($e->getHeaders());
        }

        // Unexpected: keep Laravel's debug page while developing, never leak details in production.
        return config('app.debug') ? null : self::json(500, self::defaultMessage(500));
    }

    /**
     * @param  array<string, array<int, string>>|null  $errors
     * @param  string|null  $code  overrides the status code's default (e.g. `invalid_grant` on 400 / 401)
     */
    public static function json(int $status, string $message, ?array $errors = null, ?string $code = null): JsonResponse
    {
        $body = ['message' => $message, 'code' => $code ?? self::CODES[$status] ?? 'error'];
        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $status);
    }

    /**
     * A plan limit (App\Support\Entitlements\LimitReached::payload) in this shape: 402
     * `limit_reached` plus `limit` {kind, limit, usage, plan, workspace, billing_url}; billing_url is
     * null for people who can't upgrade the workspace (the message then says to ask an admin).
     *
     * @param  array{kind: string, limit: int, usage: int, plan: string, workspace: ?string, billingUrl: ?string, message: string}  $payload
     */
    public static function limitReached(array $payload): JsonResponse
    {
        return response()->json([
            'message' => $payload['message'],
            'code' => self::CODES[402],
            'limit' => [
                'kind' => $payload['kind'],
                'limit' => $payload['limit'],
                'usage' => $payload['usage'],
                'plan' => $payload['plan'],
                'workspace' => $payload['workspace'],
                'billing_url' => $payload['billingUrl'],
            ],
        ], 402);
    }

    private static function defaultMessage(int $status): string
    {
        return match ($status) {
            401 => 'Unauthenticated.',
            403 => 'This action is unauthorized.',
            404 => 'Not found.',
            405 => 'Method not allowed.',
            429 => 'Too many requests.',
            503 => 'Service unavailable.',
            default => $status >= 500 ? 'Server error.' : 'Request failed.',
        };
    }
}
