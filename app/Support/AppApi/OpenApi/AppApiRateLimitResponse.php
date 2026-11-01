<?php

namespace App\Support\AppApi\OpenApi;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\RouteInfo;

/**
 * Every /api/v1 route runs `throttle:app-api` (the `app-api` group), so every operation can answer
 * 429 `rate_limited`. Scramble can't infer that from middleware; this adds it as one shared
 * response component.
 */
class AppApiRateLimitResponse extends OperationExtension
{
    public function handle(Operation $operation, RouteInfo $routeInfo)
    {
        if (! in_array('app-api', $routeInfo->route->gatherMiddleware(), true)) {
            return;
        }

        $components = $this->openApiTransformer->getComponents();
        $reference = new Reference('responses', 'RateLimited', $components);

        if (! $components->has($reference)) {
            $components->add($reference, AppApiErrorResponse::make(429, 'Too many requests. Retry after the `Retry-After` header (seconds).'));
        }

        $operation->addResponse($reference);
    }
}
