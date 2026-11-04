<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a client needs to join a whiteboard's live document (`WhiteboardCollab::session`) — the web session's
 * fields: the realtime server, the document name, a short-lived token (`expires_at` unix seconds; ask again to
 * reconnect), whether it may draw and the vector of the stored scene.
 *
 * @property array{url: string, document: string, epoch: int, token: string, expires_at: int, edit: bool, vector: object|null} $resource
 */
class WhiteboardCollabSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            // the realtime server (wss://…/collab)
            'url' => (string) $this->resource['url'],
            // the Y document to open (`whiteboard.<uuid>.<epoch>`)
            'document' => (string) $this->resource['document'],
            'epoch' => (int) $this->resource['epoch'],
            // the connection token for the realtime server (never sent anywhere else)
            'token' => (string) $this->resource['token'],
            // unix seconds
            'expires_at' => (int) $this->resource['expires_at'],
            'edit' => (bool) $this->resource['edit'],
            /**
             * the state vector of the last stored scene ({client id: clock}); null before the first live save
             *
             * @var array<string, int>|null
             */
            'vector' => $this->resource['vector'],
            'user' => ['id' => (int) $request->user()->id, 'name' => (string) $request->user()->name],
        ];
    }
}
