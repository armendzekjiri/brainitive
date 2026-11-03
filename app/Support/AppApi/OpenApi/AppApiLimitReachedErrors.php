<?php

namespace App\Support\AppApi\OpenApi;

use App\Support\Entitlements\LimitReached;
use Dedoc\Scramble\Extensions\ExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType as OpenApiObject;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;

/**
 * 402 `limit_reached` (a Cloud plan limit, AppApiErrors::limitReached). Scramble can't see it thrown
 * deep in the domain code, so the controller method declares `@throws LimitReached`.
 */
class AppApiLimitReachedErrors extends ExceptionToResponseExtension
{
    public function shouldHandle(Type $type)
    {
        return $type instanceof ObjectType && $type->isInstanceOf(LimitReached::class);
    }

    public function toResponse(Type $type)
    {
        $response = AppApiErrorResponse::make(402, 'A plan limit of the workspace was reached');
        $schema = $response->getContent('application/json');
        $body = $schema instanceof Schema ? $schema->type : null;

        if ($body instanceof OpenApiObject) {
            $nullableString = fn (string $description) => (new StringType)->nullable(true)->setDescription($description);

            $body->addProperty('limit', (new OpenApiObject)
                ->addProperty('kind', (new StringType)->enum(LimitReached::KINDS))
                ->addProperty('limit', new IntegerType)
                ->addProperty('usage', new IntegerType)
                ->addProperty('plan', new StringType)
                ->addProperty('workspace', $nullableString('Workspace name; null for a per-owner limit.'))
                ->addProperty('billing_url', $nullableString('Web "Plan & billing" page; null when this user can\'t upgrade (ask an admin).'))
                ->setRequired(['kind', 'limit', 'usage', 'plan', 'workspace', 'billing_url']))
                ->addRequired(['limit']);
        }

        return $response;
    }

    public function reference(ObjectType $type)
    {
        return new Reference('responses', 'LimitReached', $this->components);
    }
}
