<?php

namespace App\Support\AppApi\OpenApi;

use Dedoc\Scramble\Support\ExceptionToResponseExtensions\ValidationExceptionToResponseExtension;
use Dedoc\Scramble\Support\Type\Type;

/** 422 `validation_failed` (Form Requests, `$request->validate()`) in the App API error shape. */
class AppApiValidationErrors extends ValidationExceptionToResponseExtension
{
    public function toResponse(Type $type)
    {
        return AppApiErrorResponse::from(parent::toResponse($type));
    }
}
