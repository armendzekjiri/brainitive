<?php

namespace App\Http\Resources\AppApi;

use App\Models\SnippetCollection;
use Illuminate\Http\Request;

/**
 * One snippet collection: the fields + what the user may do. The snippets are a list of their own
 * (`GET /snippet-collections/{id}/snippets`).
 *
 * @mixin SnippetCollection
 */
class SnippetCollectionDetailResource extends SnippetCollectionResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::toArray($request),
            'can' => [
                // rename, describe, visibility (its creator or a workspace admin)
                'update' => $user->can('update', $this->resource),
                'delete' => $user->can('delete', $this->resource),
                // add, edit and delete snippets (workspace editors / admins)
                'manage_snippets' => $user->can('create', [SnippetCollection::class, $this->resource->workspace]),
            ],
        ];
    }
}
