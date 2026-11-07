<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One app connected to the account (BRAI-394): a signed-in desktop / mobile install
 * (`device:<id>`), a Passport client — MCP connector, CLI, browser extension (`oauth:<client id>`) — or a
 * personal API key of the Developer API (`api_key:<id>`, v1.52.0).
 * Never a token, its hash, an IP or a push token.
 *
 * @property-read array{id: string, kind: string, name: string, platform: string|null, app_version: string|null, scopes: list<string>, current: bool, connected_at: string|null, last_used_at: string|null, expires_at: string|null} $resource
 */
class ConnectedAppResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $app = $this->resource;

        return [
            // pass to DELETE /me/connected-apps/{id}
            'id' => (string) $app['id'],
            // desktop | mobile | tv | mcp | cli | extension | oauth | api_key
            'kind' => (string) $app['kind'],
            'name' => (string) $app['name'],
            // devices only: macos, windows, linux, ios, android
            'platform' => $app['platform'] === null ? null : (string) $app['platform'],
            'app_version' => $app['app_version'] === null ? null : (string) $app['app_version'],
            // Passport clients: what the app may do (mcp:use, capture); API keys: their areas as `area:read|write` (+ `delete`)
            'scopes' => array_map('strval', $app['scopes']),
            // the device making this request
            'current' => (bool) $app['current'],
            'connected_at' => $app['connected_at'] === null ? null : (string) $app['connected_at'],
            // devices and API keys
            'last_used_at' => $app['last_used_at'] === null ? null : (string) $app['last_used_at'],
            // Passport clients: when the newest token runs out; API keys: their expiry (null = never)
            'expires_at' => $app['expires_at'] === null ? null : (string) $app['expires_at'],
        ];
    }
}
