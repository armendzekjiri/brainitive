<?php

namespace App\Http\Controllers\AppApi\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * ETag / `If-None-Match` for the endpoints the apps poll (tray, TV, mobile): the tag is a hash of the
 * answer's body and the user, so it changes exactly when the answer changes and never matches across
 * users. A matching tag answers 304 without a body.
 */
trait AnswersWithETag
{
    /** @param  string|null  $basis  what the tag is made of when not the whole body (e.g. without values that tick on their own) */
    protected function withETag(Request $request, JsonResponse $response, ?string $basis = null): JsonResponse|Response
    {
        $etag = '"'.sha1($request->user()->getKey().'|'.($basis ?? $response->getContent())).'"';
        $response->headers->set('ETag', $etag);
        $response->headers->set('Cache-Control', 'private, no-cache');

        if (in_array($etag, $request->getETags(), true)) {
            return response()->noContent(304)->setEtag(trim($etag, '"'))->header('Cache-Control', 'private, no-cache');
        }

        return $response;
    }
}
