<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppApi\InstanceResource;
use App\Models\InstanceSettings;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public instance info for the desktop / mobile apps (BRAI-354): name, version, sign-in methods,
 * minimum app versions. No token needed; cached briefly by clients and proxies.
 */
#[Group('Instance', weight: 10)]
class InstanceController extends Controller
{
    /** Instance info: name, version, sign-in methods and minimum app versions. */
    public function __invoke(Request $request): JsonResponse
    {
        return InstanceResource::make(InstanceSettings::current())
            ->response($request)
            // a resource of a just-created model answers 201; the settings row is created lazily
            ->setStatusCode(200)
            ->header('Cache-Control', 'public, max-age=300');
    }
}
