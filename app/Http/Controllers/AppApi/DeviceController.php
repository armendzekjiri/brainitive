<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\UpdateDeviceRequest;
use App\Http\Resources\AppApi\DeviceResource;
use App\Models\AppDevice;
use App\Support\AppApi\AppTokens;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * App API v1 — the user's signed-in desktop / mobile installs (BRAI-385). A device row is created
 * by the sign-in exchange; here the apps list them, update their own, and sign any of them out.
 * Another user's device is a 404.
 */
#[Group('Devices', weight: 40)]
class DeviceController extends Controller
{
    /** The user's active devices, most recently used first; `current` marks the calling one. */
    public function index(Request $request): JsonResponse
    {
        $devices = $request->user()->appDevices()->active()->recentFirst()->get();

        return response()->json(['data' => DeviceResource::collection($devices)->resolve($request)]);
    }

    /** The calling device updates its name, versions or push token. */
    public function updateCurrent(UpdateDeviceRequest $request): DeviceResource
    {
        $device = AppTokens::currentDevice($request);
        abort_unless($device, 401);

        $device->fill($request->validated())->save();

        return new DeviceResource($device);
    }

    /** Sign one of the user's devices out: its tokens stop working at once. The calling one = sign out. */
    public function destroy(Request $request, int $device): Response
    {
        $target = $request->user()->appDevices()->active()->whereKey($device)->first();
        abort_unless($target instanceof AppDevice, 404);

        $current = AppTokens::currentDevice($request)?->is($target) ?? false;
        AppTokens::revoke($target, $current ? 'sign_out' : 'user');

        return response()->noContent();
    }
}
