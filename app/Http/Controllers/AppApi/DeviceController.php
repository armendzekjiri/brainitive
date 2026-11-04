<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\UpdateDeviceRequest;
use App\Http\Resources\AppApi\DeviceResource;
use App\Jobs\SendMobilePush;
use App\Models\AppDevice;
use App\Support\AppApi\AppTokens;
use App\Support\Webhooks\WebhookDispatcher;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

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

    /**
     * The calling device updates its name, versions or push token.
     *
     * A push token belongs to one install: any other device holding the same one (an earlier sign-in
     * on this phone, also in another account) loses it, so the phone only gets this account's pushes.
     */
    public function updateCurrent(UpdateDeviceRequest $request): DeviceResource
    {
        $device = AppTokens::currentDevice($request);
        abort_unless($device, 401);

        $data = $request->validated();
        $device->fill($data)->save();

        // an Expo token belongs to one install: any other row with it (an earlier sign-in on this
        // phone, in this account or another) stops getting this phone's pushes
        if (array_key_exists('push_token', $data) && $device->push_token_hash !== null) {
            AppDevice::query()
                ->where('push_token_hash', $device->push_token_hash)
                ->whereKeyNot($device->id)
                ->update(['push_token' => null, 'push_token_hash' => null]);
        }

        return new DeviceResource($device);
    }

    /**
     * Send a test notification to the calling device ("Send a test notification to this device").
     *
     * Queued through the Expo push service like every push → 202. The device needs an Expo push token
     * (`PATCH /devices/current`); without one → 409 `no_push_token`. Push turned off on this server →
     * 409 `push_disabled`. 3 per minute.
     *
     * @throws ConflictHttpException no push token on this device (`no_push_token`) or push is off on this server (`push_disabled`) (409)
     */
    public function testPush(Request $request): JsonResponse
    {
        $device = AppTokens::currentDevice($request);
        abort_unless($device, 401);

        if (! config('app-api.push.enabled')) {
            return response()->json(['message' => 'Push notifications are turned off on this server.', 'code' => 'push_disabled'], 409);
        }
        if (! SendMobilePush::isExpoToken($device->push_token)
            || ! AppDevice::query()->eligibleForPush()->whereKey($device->id)->exists()) {
            return response()->json(['message' => 'This device has no push token. Allow notifications in the app, then try again.', 'code' => 'no_push_token'], 409);
        }

        $job = new SendMobilePush($device->user_id, [$device->id], 'Test notification', 'Push notifications work on this device.', null, null);
        if (WebhookDispatcher::syncQueue() && ! app()->runningInConsole()) { // as MobilePushChannel
            dispatch($job)->afterResponse();
        } else {
            dispatch($job);
        }

        return response()->json(['data' => ['status' => 'queued']], 202);
    }

    /** Sign one of the user's devices out: its tokens stop working at once. The calling one = sign out. */
    public function destroy(Request $request, int $device): Response
    {
        abort_unless(AppTokens::signOutDevice($request->user(), $device, $request), 404);

        return response()->noContent();
    }
}
