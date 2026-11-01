<?php

namespace App\Support\AppApi;

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
            return self::json(422, $e->getMessage(), $e->errors());
        }

        if ($e instanceof AuthenticationException) {
            return self::json(401, 'Unauthenticated.');
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $message = $e->getMessage() !== '' && $status < 500 ? $e->getMessage() : self::defaultMessage($status);

            return self::json($status, $message)->withHeaders($e->getHeaders());
        }

        // Unexpected: keep Laravel's debug page while developing, never leak details in production.
        return config('app.debug') ? null : self::json(500, self::defaultMessage(500));
    }

    /** @param  array<string, array<int, string>>|null  $errors */
    public static function json(int $status, string $message, ?array $errors = null): JsonResponse
    {
        $body = ['message' => $message, 'code' => self::CODES[$status] ?? 'error'];
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
