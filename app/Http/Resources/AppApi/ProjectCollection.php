<?php

namespace App\Http\Resources\AppApi;

/** `GET /api/v1/workspaces/{slug}/projects`: the projects of a workspace the user can open, by name. */
class ProjectCollection extends CursorCollection
{
    public $collects = ProjectResource::class;
}
