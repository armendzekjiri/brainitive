<?php

namespace App\Support\AppApi\OpenApi;

use App\Support\AppApi\AppApiErrors;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

/**
 * The App API error shape `{message, code, errors?}` (AppApiErrors) as an OpenAPI response, so the
 * spec shows what the apps really receive instead of Laravel's default `{message}`. Used by the
 * Scramble extensions in this namespace (config/scramble.php → extensions).
 */
final class AppApiErrorResponse
{
    public static function make(int $status, string $description): Response
    {
        $code = AppApiErrors::CODES[$status] ?? 'error';

        $body = (new ObjectType)
            ->addProperty('message', (new StringType)->setDescription('Human-readable, safe to show.'))
            ->addProperty('code', (new StringType)->enum([$code])->setDescription('Machine-readable: switch on this, not on the message.'));
        $required = ['message', 'code'];

        if ($status === 422) {
            $body->addProperty('errors', (new ObjectType)
                ->setDescription('The messages of each field that failed validation.')
                ->additionalProperties((new ArrayType)->setItems(new StringType)));
            $required[] = 'errors';
        }

        return Response::make($status)
            ->setDescription($description)
            ->setContent('application/json', Schema::fromType($body->setRequired($required)));
    }

    /** Rebuild a response Scramble made for an exception in the App API shape (same status). */
    public static function from(mixed $response): mixed
    {
        if (! $response instanceof Response || ! is_numeric($response->code)) {
            return $response;
        }

        return self::make((int) $response->code, $response->description);
    }
}
