<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Mcp\Tools\Concerns\InteractsWithContentLinks;
use App\Models\ItemLink;
use App\Support\Boards\ItemLinks;
use App\Support\Mcp\ContentLinkFormatter;
use App\Support\Mcp\IdempotentRequest;
use App\Support\Mcp\ResourceLocator;
use App\Support\Mcp\ToolError;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Add a "Linked issues" link between two issues of the same project, read as "<item> <link_type> <target_item>" — e.g. item BRAI-12, link_type blocks, target_item BRAI-14. link_type: blocks, is_blocked_by, duplicates, is_duplicated_by, relates_to, clones, is_cloned_by. Issues by card id or key. Adding a link that already exists — also from the other side (A blocks B = B is_blocked_by A) — is a no-op (created: false). Needs edit rights on item. Pass a unique request_id.')]
class LinkIssuesTool extends Tool
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
                'link_type' => 'required|string|max:30',
                'target_item' => 'required',
                'request_id' => 'required|string|max:100',
            ]);

            $type = $this->issueLinkType($validated['link_type']);
            $user = $request->user();
            $project = $this->projectForUser($user, $validated['workspace'], $validated['project']);
            $item = $this->projectIssue($user, $project, $this->contentRef($validated['item']), 'item');
            $this->authorizeFor($user, 'update', $item);
            $target = $this->projectIssue($user, $project, $this->contentRef($validated['target_item']), 'target_item');
            if ($item->id === $target->id) {
                throw new ToolError('Cannot link an item to itself.');
            }

            $run = IdempotentRequest::run($user, 'link_issues', $validated['request_id'], [
                'item_id' => $item->id,
                'target_item_id' => $target->id,
                'link_type' => $type,
            ], function () use ($item, $target, $type, $user) {
                $link = ItemLinks::add($item, $target, $type, $user);

                return [
                    'created' => $link->wasRecentlyCreated,
                    'link_id' => $link->id,
                    'link' => trim(($item->key ?? '').' '.ItemLink::getLinkTypeLabel($type).' '.($target->key ?? '')),
                    'location' => ContentLinkFormatter::locate($item),
                ];
            });

            return Response::structured([
                'created' => ! $run['replayed'] && $run['result']['created'],
                'link_id' => $run['result']['link_id'],
                'link' => $run['result']['link'],
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
            'created' => $schema->boolean()->description('False when the link already existed or this request_id was already applied (nothing changed).'),
            'link_id' => $schema->integer(),
            'link' => $schema->string()->description('The link as a sentence, e.g. "BRAI-12 blocks BRAI-14".'),
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
            'project' => $schema->string()->description('The project slug (both issues must be in it).')->required(),
            'item' => $schema->string()->description('The issue that gets the link: card id or key (e.g. BRAI-12).')->required(),
            'link_type' => $schema->string()->enum(ItemLinks::types())->description('How item relates to target_item.')->required(),
            'target_item' => $schema->string()->description('The other issue: card id or key.')->required(),
            'request_id' => $schema->string()->description('Unique id for this write (e.g. a UUID). Reuse it only to retry the same link.')->required(),
        ];
    }
}
