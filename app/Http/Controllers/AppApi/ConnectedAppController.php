<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppApi\ConnectedAppResource;
use App\Http\Resources\AppApi\DataExportStateResource;
use App\Models\AppDevice;
use App\Support\AppApi\AppTokens;
use App\Support\Profile\ConnectedApps;
use App\Support\Profile\DataExports;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App API v1 — connected apps and "Download my data" (BRAI-394): every app with access to the
 * account (desktop / mobile installs, MCP connectors, the CLI, the browser extension) with revoke,
 * and the GDPR export of Profile → Your data. Account deletion stays on the web.
 */
#[Group('Connected apps', weight: 120)]
class ConnectedAppController extends Controller
{
    /** Every app with access to the account: signed-in devices first (most recently used), then MCP / CLI / extension. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $current = AppTokens::currentDevice($request);

        $devices = $user->appDevices()->active()->recentFirst()->get()->map(fn (AppDevice $device) => [
            'id' => 'device:'.$device->id,
            'kind' => $device->client,
            'name' => $device->name,
            'platform' => $device->platform,
            'app_version' => $device->app_version,
            'scopes' => [],
            'current' => $current?->is($device) ?? false,
            'connected_at' => $device->created_at?->toIso8601ZuluString(),
            'last_used_at' => $device->last_used_at?->toIso8601ZuluString(),
            'expires_at' => null,
        ]);

        $clients = collect(ConnectedApps::for($user))->map(fn (array $app) => [
            'id' => 'oauth:'.$app['client_id'],
            'kind' => $app['kind'],
            'name' => $app['kind'] === 'cli' ? 'Brainitive CLI' : $app['name'],
            'platform' => null,
            'app_version' => null,
            'scopes' => $app['scopes'],
            'current' => false,
            'connected_at' => self::zulu($app['last_issued']),
            'last_used_at' => null,
            'expires_at' => self::zulu($app['expires_at']),
        ]);

        return ConnectedAppResource::collection($devices->concat($clients)->values());
    }

    /**
     * Revoke an app's access at once: a device is signed out (the calling one = sign out), a
     * Passport client loses all of this account's tokens.
     *
     * @throws NotFoundHttpException not an app connected to this account (404)
     */
    public function destroy(Request $request, string $id): Response
    {
        $user = $request->user();
        // device:<id> or oauth:<client uuid> (Passport client ids are uuids: anything else can't match)
        [$kind, $key] = array_pad(explode(':', $id, 2), 2, '');
        abort_unless(($kind === 'device' && preg_match('/^[1-9][0-9]{0,18}$/', $key) === 1) || ($kind === 'oauth' && Str::isUuid($key)), 404);

        $revoked = $kind === 'device'
            ? AppTokens::signOutDevice($user, (int) $key, $request)
            : ConnectedApps::disconnect($user, $key) > 0;
        abort_unless($revoked, 404);

        return response()->noContent();
    }

    /** The state of "Download my data": the newest export, the last few, when a new one is allowed. */
    public function export(Request $request): DataExportStateResource
    {
        return new DataExportStateResource($request->user());
    }

    /**
     * Request a copy of all the user's data (a ZIP, prepared in the background; a notification and an
     * email follow when it's ready). One per hour.
     *
     * @throws ValidationException one is already being prepared, or within the hour (422, `export`)
     */
    public function requestExport(Request $request): JsonResponse
    {
        DataExports::request($request->user());

        return (new DataExportStateResource($request->user()))->response()->setStatusCode(202);
    }

    private static function zulu(?string $iso): ?string
    {
        return $iso === null ? null : now()->parse($iso)->utc()->toIso8601ZuluString();
    }
}
