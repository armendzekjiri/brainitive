<?php

namespace Tests\Feature\AppApi\Fixtures;

use Illuminate\Http\JsonResponse;

/**
 * Probe endpoints for OpenApiSpecTest: one public, one behind `auth:app` with a Form Request, so the
 * documented error shape is tested without depending on any real endpoint.
 */
class OpenApiProbeController
{
    public function publicInfo(): JsonResponse
    {
        return response()->json(['ok' => true]);
    }

    public function store(OpenApiProbeRequest $request): JsonResponse
    {
        return response()->json(['name' => $request->validated('name')], 201);
    }
}
