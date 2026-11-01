<?php

namespace App\Http\Resources\AppApi;

/** Workspace or project members by name (pickers). */
class MemberCollection extends CursorCollection
{
    public $collects = MemberResource::class;
}
