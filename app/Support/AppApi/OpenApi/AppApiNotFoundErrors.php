<?php

namespace App\Support\AppApi\OpenApi;

use Dedoc\Scramble\Support\ExceptionToResponseExtensions\NotFoundExceptionToResponseExtension;
use Dedoc\Scramble\Support\Type\Type;

/** 404 `not_found` (route model binding, `findOrFail`) in the App API error shape. */
class AppApiNotFoundErrors extends NotFoundExceptionToResponseExtension
{
    public function toResponse(Type $type)
    {
        return AppApiErrorResponse::from(parent::toResponse($type));
    }
}
