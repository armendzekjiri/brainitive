<?php

namespace App\Http\Resources\AppApi;

/** The snippet collections of a workspace, in the web's order (position). */
class SnippetCollectionCollection extends CursorCollection
{
    public $collects = SnippetCollectionResource::class;
}
