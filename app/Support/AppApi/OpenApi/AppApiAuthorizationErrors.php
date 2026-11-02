<?php

namespace App\Support\AppApi\OpenApi;

use Dedoc\Scramble\Support\ExceptionToResponseExtensions\AuthorizationExceptionToResponseExtension;
use Dedoc\Scramble\Support\Type\Type;

/** 403 `forbidden` (policies, `authorize()`) in the App API error shape. */
class AppApiAuthorizationErrors extends AuthorizationExceptionToResponseExtension
{
    public function toResponse(Type $type)
    {
        return AppApiErrorResponse::from(parent::toResponse($type));
    }
}
