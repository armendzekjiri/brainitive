<?php

namespace App\Http\Resources\AppApi;

use App\Models\BoardView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A saved board view (BRAI-78 / 407): its `params` are the board page's URL params, reduced to what still
 * exists on the board (BoardViewConfig::sanitize) — send them as they are to `GET /boards/{id}/items`.
 * `params.view` is the layout (kanban | list | gantt | calendar), `sort` / `hide` the list view's settings.
 * The controller sets `api_params`, `api_dropped` and `api_default`; needs `user`.
 *
 * @mixin BoardView
 */
class BoardViewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $view = $this->resource;
        $params = (array) $view->getAttribute('api_params');

        return [
            'id' => (int) $view->id,
            'name' => (string) $view->name,
            // shared with everyone on the board (else only its owner sees it)
            'is_shared' => (bool) $view->is_shared,
            'is_own' => (int) $view->user_id === (int) $request->user()->id,
            'owner' => $view->user?->name,
            // the user's default view of this board (the plain board URL opens it)
            'is_default' => (bool) $view->getAttribute('api_default'),
            'layout' => (string) ($params['view'] ?? 'kanban'),
            'params' => (object) $params,
            // parts of the view that no longer exist on the board (left out of params)
            'dropped' => array_values((array) $view->getAttribute('api_dropped')),
        ];
    }
}
