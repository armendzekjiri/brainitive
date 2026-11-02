<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\RealtimeTokenRequest;
use App\Http\Resources\AppApi\RealtimeInfoResource;
use App\Http\Resources\AppApi\RealtimeTokenResource;
use App\Support\AppApi\AppTokens;
use App\Support\Live\LiveConfig;
use App\Support\Live\LiveToken;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App API v1 — live updates for native clients (BRAI-426). Brainitive's realtime is its own live channels
 * (BRAI-151) on the realtime server, not Laravel broadcasting: a client asks for a short-lived token listing
 * the channels it may join — decided by `LiveChannels`, the same policies as the web (`LiveToken::grant`,
 * shared with `live.token`) — and opens a WebSocket. Payloads carry ids only; the client refetches through
 * the normal endpoints. Event catalog: docs/ai/API.md → Realtime.
 */
#[Group('Realtime', weight: 340)]
class RealtimeController extends Controller
{
    /**
     * How to connect to live updates.
     *
     * The WebSocket URL, the token endpoint, limits and channel types. `enabled: false` (and `url: null`) when the
     * server runs without live updates: then poll (notifications every 30 s, boards on focus).
     */
    public function show(Request $request): RealtimeInfoResource
    {
        return new RealtimeInfoResource(['enabled' => LiveConfig::enabled($request)]);
    }

    /**
     * A token to join live channels.
     *
     * `{channels: ["user.12", "board.4", "item.88"]}` (≤ 50) → a token for the ones the user may join (as on the
     * web: the board / issue / plan / ticket / doc must be one they can open, `user.<id>` only their own); the
     * others come back in `denied`, never as an error. Valid for `token_ttl` seconds to join; ask for a new one
     * on every reconnect or for new channels. The realtime server re-checks access every minute and sends
     * `revoked` for a channel the user lost; it closes the socket with 4410 once this device is signed out.
     *
     * @throws NotFoundHttpException live updates are off on this server (404)
     */
    public function token(RealtimeTokenRequest $request): RealtimeTokenResource
    {
        // bound to this device: once it is signed out, the realtime server closes its sockets (4410)
        return new RealtimeTokenResource(LiveToken::grant($request->user(), $request->validated('channels'), AppTokens::currentDevice($request)?->id));
    }
}
