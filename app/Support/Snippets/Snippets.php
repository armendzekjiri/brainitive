<?php

namespace App\Support\Snippets;

use App\Models\Snippet;
use App\Models\SnippetCollection;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Entitlements\LimitGate;
use App\Support\Validation\ContentLimits;
use Illuminate\Support\Str;

/**
 * Snippet collections and their snippets, the same way for the web (`SnippetController`) and the App
 * API (`/api/v1/snippet-collections`, `/api/v1/snippets`): the fields' rules and how they are created.
 * Every workspace member reads them; workspace editors / admins add and change snippets
 * (SnippetPolicy::create); a collection is changed by its creator or a workspace admin. Callers authorize.
 */
final class Snippets
{
    /** @return array<string, mixed> */
    public static function collectionRules(bool $update = false): array
    {
        return [
            'name' => $update ? 'sometimes|required|string|max:255' : 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            // an emoji (the web also takes an uploaded image's URL)
            'icon' => 'nullable|string|max:2048',
            // private (members only) | public (anyone with the share link)
            'visibility' => 'nullable|in:private,public',
        ];
    }

    /** @return array<string, mixed> */
    public static function snippetRules(bool $update = false): array
    {
        return [
            'title' => $update ? 'sometimes|required|string|max:255' : 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'language' => 'nullable|string|max:50',
            'content' => ['nullable', ...ContentLimits::body()],
            // inherit (the collection's) | private | public
            'visibility' => 'nullable|in:inherit,private,public',
        ];
    }

    /**
     * A new collection at the end of the workspace, with a slug free in it.
     *
     * @param  array<string, mixed>  $data  validated collectionRules()
     */
    public static function createCollection(Workspace $workspace, User $user, array $data): SnippetCollection
    {
        $base = Str::slug($data['name']) ?: 'collection';
        $slug = $base;
        for ($i = 1; SnippetCollection::where('workspace_id', $workspace->id)->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return SnippetCollection::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'slug' => $slug,
            'icon' => $data['icon'] ?? '📋',
            'visibility' => $data['visibility'] ?? 'private',
            'position' => (SnippetCollection::where('workspace_id', $workspace->id)->max('position') ?? 0) + 1,
        ]);
    }

    /**
     * A new snippet at the end of the collection.
     *
     * @param  array<string, mixed>  $data  validated snippetRules()
     */
    public static function createSnippet(SnippetCollection $collection, User $user, array $data): Snippet
    {
        // content limit (Codex web review B): every metered type, in the shared create path
        app(LimitGate::class)->ensureContentRoom($collection->workspace);

        return Snippet::create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'content' => $data['content'] ?? null,
            'collection_id' => $collection->id,
            'created_by' => $user->id,
            'language' => ($data['language'] ?? null) ?: 'plaintext',
            'visibility' => $data['visibility'] ?? 'inherit',
            'position' => (Snippet::where('collection_id', $collection->id)->max('position') ?? 0) + 1,
        ]);
    }
}
