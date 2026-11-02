<?php

namespace App\Http\Resources\AppApi;

/** `GET /api/v1/workspaces`: the user's workspaces, by name. */
class WorkspaceCollection extends CursorCollection
{
    public $collects = WorkspaceResource::class;
}
