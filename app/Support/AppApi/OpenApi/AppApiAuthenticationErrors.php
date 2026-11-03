<?php

namespace App\Support\AppApi\OpenApi;

use Dedoc\Scramble\Support\ExceptionToResponseExtensions\AuthenticationExceptionToResponseExtension;
use Dedoc\Scramble\Support\Type\Type;

/** 401 `unauthenticated` (`auth:app`: missing, expired or revoked token) in the App API error shape. */
class AppApiAuthenticationErrors extends AuthenticationExceptionToResponseExtension
{
    public function toResponse(Type $type)
    {
        return AppApiErrorResponse::from(parent::toResponse($type));
    }
}
