<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Mcp\Tools\Concerns\InteractsWithContentLinks;
use App\Support\Boards\ItemLinks;
use App\Support\Mcp\ContentLinkFormatter;
use App\Support\Mcp\IdempotentRequest;
use App\Support\Mcp\ResourceLocator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Remove "Linked issues" links between two issues of a project. With link_type, only that link is removed (stored from either side: removing "A blocks B" also removes "B is_blocked_by A"); without it, every link between the two. Issues by card id or key. Removing a link that isn\'t there is a no-op (removed: 0). Needs edit rights on item. Pass a unique request_id.')]
class UnlinkIssuesTool extends Tool
{
    use InteractsWithBrainitive;
    use InteractsWithContentLinks;

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->guard(function () use ($request) {
            $validated = $request->validate([
                'workspace' => 'required|string',
                'project' => 'required|string',
                'item' => 'required',
                'target_item' => 'required',
                'link_type' => 'nullable|string|max:30',
                'request_id' => 'required|string|max:100',
            ]);

            $type = ($validated['link_type'] ?? '') !== '' ? $this->issueLinkType($validated['link_type']) : null;

            $user = $request->user();
            $project = $this->projectForUser($user, $validated['workspace'], $validated['project']);
            $item = $this->projectIssue($user, $project, $this->contentRef($validated['item']), 'item');
            $this->authorizeFor($user, 'update', $item);
            $target = $this->projectIssue($user, $project, $this->contentRef($validated['target_item']), 'target_item');

            $run = IdempotentRequest::run($user, 'unlink_issues', $validated['request_id'], [
                'item_id' => $item->id,
                'target_item_id' => $target->id,
                'link_type' => $type,
            ], function () use ($item, $target, $type) {
                $links = $type === null
                    ? ItemLinks::between($item, $target)->all()
                    : array_filter([ItemLinks::find($item, $target, $type)]);
                foreach ($links as $link) {
                    ItemLinks::remove($item, $link);
                }

                return ['removed' => count($links), 'location' => ContentLinkFormatter::locate($item)];
            });

            return Response::structured([
                'removed' => $run['replayed'] ? 0 : $run['result']['removed'],
                'location' => $run['result']['location'],
            ]);
        });
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'removed' => $schema->integer()->description('How many links were removed (0 when there were none or this request_id was already applied).'),
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
            'item' => $schema->string()->description('The issue to unlink: card id or key (e.g. BRAI-12).')->required(),
            'target_item' => $schema->string()->description('The other issue: card id or key.')->required(),
            'link_type' => $schema->string()->enum(ItemLinks::types())->description('Optional: only this link, read from item\'s side. Omit to remove every link between the two issues.'),
            'request_id' => $schema->string()->description('Unique id for this write (e.g. a UUID). Reuse it only to retry the same removal.')->required(),
        ];
    }
}
