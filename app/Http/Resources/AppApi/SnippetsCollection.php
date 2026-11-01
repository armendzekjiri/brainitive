<?php

namespace App\Http\Resources\AppApi;

/** The snippets of a collection, in the web's order (position). */
class SnippetsCollection extends CursorCollection
{
    public $collects = SnippetResource::class;
}
