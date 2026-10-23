<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Models\Comment;
use App\Support\Mcp\PageContentConverter;
use App\Support\Mcp\ResourceLocator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Read the comments on a board card, oldest first (replies included, with parent_id). Paginate with next_cursor. Bodies are Markdown.')]
class ListItemCommentsTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
            'board' => 'required|string',
            'item_id' => 'required|integer',
            'cursor' => 'nullable|integer|min:0',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $user = $request->user();

        try {
            $item = $this->boardItemForUser($user, $validated['workspace'], $validated['project'], $validated['board'], (int) $validated['item_id']);
            $this->authorizeFor($user, 'view', $item);
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }

        $limit = (int) ($validated['limit'] ?? 30);
        // Ids grow with time: chronological, and stable when new comments arrive.
        $comments = $item->comments()
            ->with('user:id,name')
            ->when(! empty($validated['cursor']), fn ($q) => $q->where('id', '>', (int) $validated['cursor']))
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $comments->count() > $limit;
        $comments = $comments->take($limit);

        return Response::structured([
            'item_key' => $item->key ?? '',
            'comments' => $comments->map(fn (Comment $c) => [
                'id' => $c->id,
                'parent_id' => $c->parent_id ?? 0,
                'author' => $c->user?->name ?? '',
                'created_at' => $c->created_at?->toIso8601String() ?? '',
                'markdown' => PageContentConverter::htmlToMarkdown((string) $c->body),
            ])->values()->all(),
            'next_cursor' => $hasMore ? (string) $comments->last()->id : '',
            'location' => ResourceLocator::locate($item),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'item_key' => $schema->string(),
            'comments' => $schema->array()->items($schema->object([
                'id' => $schema->integer(),
                'parent_id' => $schema->integer()->description('0 for a top-level comment, else the comment it replies to.'),
                'author' => $schema->string(),
                'created_at' => $schema->string(),
                'markdown' => $schema->string(),
            ])),
            'next_cursor' => $schema->string()->description('Pass as cursor for the next page; empty when there are no more.'),
            'location' => ResourceLocator::outputSchema($schema),
        ];
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
            'project' => $schema->string()->description('The project slug.')->required(),
            'board' => $schema->string()->description('The board slug.')->required(),
            'item_id' => $schema->integer()->description('The card id (from get_board).')->required(),
            'cursor' => $schema->integer()->description('next_cursor from the previous page.'),
            'limit' => $schema->integer()->description('Comments per page, 1–100 (default 30).'),
        ];
    }
}
