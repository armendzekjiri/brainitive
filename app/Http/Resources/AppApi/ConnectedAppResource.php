<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One app connected to the account (BRAI-394): a signed-in desktop / mobile install
 * (`device:<id>`) or a Passport client — MCP connector, CLI, browser extension (`oauth:<client id>`).
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
            // desktop | mobile | mcp | cli | extension | oauth
            'kind' => (string) $app['kind'],
            'name' => (string) $app['name'],
            // devices only: macos, windows, linux, ios, android
            'platform' => $app['platform'] === null ? null : (string) $app['platform'],
            'app_version' => $app['app_version'] === null ? null : (string) $app['app_version'],
            // Passport clients only: what the app may do (mcp:use, capture)
            'scopes' => array_map('strval', $app['scopes']),
            // the device making this request
            'current' => (bool) $app['current'],
            'connected_at' => $app['connected_at'] === null ? null : (string) $app['connected_at'],
            // devices only
            'last_used_at' => $app['last_used_at'] === null ? null : (string) $app['last_used_at'],
            // Passport clients only: when the newest token runs out
            'expires_at' => $app['expires_at'] === null ? null : (string) $app['expires_at'],
        ];
    }
}
