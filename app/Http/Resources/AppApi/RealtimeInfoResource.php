<?php

namespace App\Http\Resources\AppApi;

use App\Support\Live\LiveChannels;
use App\Support\Live\LiveConfig;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * How a native client connects to live updates (BRAI-426): the realtime server's WebSocket URL, where to
 * get a channel token, the limits and the channel types. The event catalog is in docs/ai/API.md → Realtime.
 *
 * @property-read array{enabled: bool} $resource
 */
class RealtimeInfoResource extends JsonResource
{
    /** type => [id kind, who may join] */
    private const TYPES = [
        'user' => ['number', 'only the user themself (user.<own id>): notifications'],
        'board' => ['number', 'who can open the board (BoardPolicy::view): cards, columns, sprints'],
        'item' => ['number', 'who can open the issue (ItemPolicy::view): its fields, comments, presence, typing'],
        'plan' => ['number', 'who can open the Gantt plan (PlanPolicy::view): rows, linked issues'],
        'ticket' => ['number', 'who can open the helpdesk ticket (TicketPolicy::view): replies'],
        'page' => ['uuid', 'who can open the doc / whiteboard (PagePolicy::view): presence only, no events yet'],
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $enabled = (bool) $this->resource['enabled'];

        return [
            // false: this server has no live updates — poll the normal endpoints instead
            'enabled' => $enabled,
            /** @var string|null the WebSocket URL (ws:// or wss://), null when off */
            'url' => $enabled ? LiveConfig::absoluteUrl() : null,
            // POST it {channels: [...]} for a token (send that as the socket's first message)
            'token_path' => '/api/v1/realtime/token',
            // seconds a token is valid for joining (ask again for every reconnect / new channel)
            'token_ttl' => LiveConfig::tokenTtl(),
            // most channels per token request (and subscriptions per socket)
            'max_channels' => LiveChannels::MAX_PER_TOKEN,
            // the realtime server's message protocol (docs/ai/API.md → Realtime)
            'protocol' => 1,
            /** @var list<array{type: string, id: string, access: string}> channel `<type>.<id>` and who may join it */
            'channel_types' => array_map(
                fn (string $type) => ['type' => $type, 'id' => self::TYPES[$type][0], 'access' => self::TYPES[$type][1]],
                array_keys(array_intersect_key(self::TYPES, LiveChannels::TYPES)),
            ),
        ];
    }
}
