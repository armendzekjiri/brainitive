<?php

namespace App\Http\Controllers;

use App\Models\AppDevice;
use App\Support\AppApi\AppTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Profile → "Desktop & mobile apps" (BRAI-385): sign out one of the user's app installs from the
 * web. The list itself comes with the Profile page (ProfileController::edit). Another user's
 * device is a 404.
 */
class AppDeviceController extends Controller
{
    public function destroy(Request $request, int $device): RedirectResponse
    {
        $target = $request->user()->appDevices()->active()->whereKey($device)->first();
        abort_unless($target instanceof AppDevice, 404);

        AppTokens::revoke($target, 'user');

        return back()->with('success', "Signed out {$target->name}.");
    }

    /**
     * e2e only (APP_ENV=e2e; the route doesn't exist elsewhere): a signed-in desktop device and its
     * access token for the session user, as the sign-in exchange would create it.
     */
    public function e2eCreate(Request $request): JsonResponse
    {
        abort_unless(app()->environment('e2e'), 404);

        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'platform' => 'nullable|in:'.implode(',', AppDevice::PLATFORMS),
        ]);

        $device = AppDevice::create([
            'user_id' => $request->user()->id,
            'client' => match ($validated['platform'] ?? 'macos') {
                'ios', 'android' => 'mobile',
                'android_tv' => 'tv',
                default => 'desktop',
            },
            'platform' => $validated['platform'] ?? 'macos',
            'name' => $validated['name'],
            'app_version' => '0.1.0',
            'os_version' => '15.1',
        ]);
        $tokens = AppTokens::issue($device);
        $device->forceFill(['last_used_at' => now()])->save();

        return response()->json(['id' => $device->id, 'access_token' => $tokens['access_token']]);
    }
}
