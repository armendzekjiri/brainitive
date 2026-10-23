<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Models\Item;
use App\Support\Boards\ItemComments;
use App\Support\Mcp\IdempotentRequest;
use App\Support\Mcp\PageContentConverter;
use App\Support\Mcp\RequestIdConflict;
use App\Support\Mcp\ResourceLocator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Add a comment (Markdown) to a board card as the signed-in user, e.g. an implementation update — the card description stays untouched. Pass a unique request_id: retrying with the same request_id never posts twice. Mention project members as @their-email (they are notified like in the app).')]
class AddItemCommentTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
            'board' => 'required|string',
            'item_id' => 'required|integer',
            'markdown' => 'required|string|max:20000',
            'request_id' => 'required|string|max:100',
            'reply_to_comment_id' => 'nullable|integer',
        ]);

        $user = $request->user();

        try {
            $item = $this->boardItemForUser($user, $validated['workspace'], $validated['project'], $validated['board'], (int) $validated['item_id']);
            // Same rule as the board UI: commenting needs edit rights on the card.
            $this->authorizeFor($user, 'update', $item);
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }

        $parentId = $validated['reply_to_comment_id'] ?? null;
        if ($parentId && ! $item->comments()->whereKey($parentId)->exists()) {
            return Response::error('reply_to_comment_id is not a comment on this card.');
        }

        try {
            $run = IdempotentRequest::run($user, 'add_item_comment', $validated['request_id'], [
                'item_id' => $item->id,
                'markdown' => $validated['markdown'],
                'reply_to' => $parentId,
            ], function () use ($item, $user, $validated, $parentId) {
                $html = $this->linkMentions(PageContentConverter::markdownToHtml($validated['markdown']), $item);
                $comment = ItemComments::add($item, $user, $html, $parentId);

                return ['comment_id' => $comment->id, 'location' => ResourceLocator::locate($item)];
            });
        } catch (RequestIdConflict $e) {
            return Response::error($e->getMessage());
        }

        return Response::structured([
            'created' => ! $run['replayed'],
            'comment_id' => $run['result']['comment_id'],
            'location' => $run['result']['location'],
        ]);
    }

    /**
     * "@person@example.com" → a mention of that project member (notified like in the app).
     */
    private function linkMentions(string $html, Item $item): string
    {
        $project = $item->board->project;

        return preg_replace_callback('/(?<![\w@])@([A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,})/', function (array $m) use ($project) {
            $user = $project->workspace->users()->where('email', strtolower($m[1]))->first();
            if (! $user || ! $user->hasProjectAccess($project)) {
                return $m[0];
            }

            return '<span class="mention" data-mention-user-id="'.$user->id.'">@'.e($user->name).'</span>';
        }, $html) ?? $html;
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'created' => $schema->boolean()->description('False when this request_id was already applied (nothing new was posted).'),
            'comment_id' => $schema->integer(),
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
            'markdown' => $schema->string()->description('Comment body as Markdown (max 20,000 characters).')->required(),
            'request_id' => $schema->string()->description('Unique id for this write (e.g. a UUID). Reuse it only to retry the same comment.')->required(),
            'reply_to_comment_id' => $schema->integer()->description('Optional: reply to this comment (from list_item_comments).'),
        ];
    }
}
