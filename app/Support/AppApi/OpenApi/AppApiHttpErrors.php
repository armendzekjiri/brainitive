<?php

namespace App\Support\AppApi\OpenApi;

use Dedoc\Scramble\Support\ExceptionToResponseExtensions\HttpExceptionToResponseExtension;
use Dedoc\Scramble\Support\Type\Type;

/** `abort(409)`, `abort(404)` & co. in the App API error shape. */
class AppApiHttpErrors extends HttpExceptionToResponseExtension
{
    public function toResponse(Type $type)
    {
        return AppApiErrorResponse::from(parent::toResponse($type));
    }
}
