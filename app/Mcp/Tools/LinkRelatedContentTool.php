<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Mcp\Tools\Concerns\InteractsWithContentLinks;
use App\Models\ContentReference;
use App\Support\Mcp\ContentLinkFormatter;
use App\Support\Mcp\IdempotentRequest;
use App\Support\Mcp\ResourceLocator;
use App\Support\Mcp\ToolError;
use App\Support\References\ContentReferences;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Add a "Related content" link from one issue, doc/whiteboard, helpdesk ticket or plan to another of the same project — e.g. connect a meeting issue to the doc with its notes. The target then lists the source under "Referenced by". Types: item | page | ticket | plan; ids: card id or issue key, doc uuid, ticket id or key, plan id or slug. Linking twice is a no-op (created: false). Issue ↔ issue links are "Linked issues": use link_issues. Needs edit rights on the source. Pass a unique request_id: retrying with the same request_id never links twice.')]
class LinkRelatedContentTool extends Tool
{
    use InteractsWithBrainitive;
    use InteractsWithContentLinks;

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->guard(function () use ($request) {
            $validated = $request->validate([
                'workspace' => 'required|string',
                'project' => 'required|string',
                'source_type' => 'required|string|max:20',
                'source_id' => 'required',
                'target_type' => 'required|string|max:20',
                'target_id' => 'required',
                'request_id' => 'required|string|max:100',
            ]);

            $user = $request->user();
            $project = $this->projectForUser($user, $validated['workspace'], $validated['project']);

            // Everything is resolved and checked before anything is written (same order as the UI).
            $source = $this->contentEndpoint($user, $project, $validated['source_type'], $this->contentRef($validated['source_id']), 'source_id');
            $this->authorizeFor($user, 'update', $source);
            $target = $this->contentEndpoint($user, $project, $validated['target_type'], $this->contentRef($validated['target_id']), 'target_id');

            $sourceType = ContentReference::typeOf($source);
            $targetType = ContentReference::typeOf($target);
            if ($sourceType === 'item' && $targetType === 'item') {
                throw new ToolError('Issue ↔ issue links are "Linked issues" (blocks, relates to, duplicates…): use link_issues.');
            }

            $run = IdempotentRequest::run($user, 'link_related_content', $validated['request_id'], [
                'project_id' => $project->id,
                'source' => "{$sourceType}:{$source->getKey()}",
                'target' => "{$targetType}:{$target->getKey()}",
            ], function () use ($project, $user, $source, $target) {
                $reference = ContentReferences::link($project, $user, $source, $target);

                return [
                    'created' => $reference->wasRecentlyCreated,
                    'reference_id' => $reference->id,
                    'source' => ContentReferences::summary($project->workspace, $project, $source)['title'],
                    'target' => ContentReferences::summary($project->workspace, $project, $target)['title'],
                    'location' => ContentLinkFormatter::locate($source),
                ];
            });

            return Response::structured([
                'created' => ! $run['replayed'] && $run['result']['created'],
                'reference_id' => $run['result']['reference_id'],
                'source' => $run['result']['source'],
                'target' => $run['result']['target'],
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
            'reference_id' => $schema->integer(),
            'source' => $schema->string()->description('Title of the source.'),
            'target' => $schema->string()->description('Title of the target.'),
            'location' => ResourceLocator::outputSchema($schema),
        ];
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        $types = ['item', 'page', 'ticket', 'plan'];

        return [
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
            'project' => $schema->string()->description('The project slug (source and target must both be in it).')->required(),
            'source_type' => $schema->string()->enum($types)->description('Type of the content that gets the link: item = issue, page = doc or whiteboard, ticket, plan.')->required(),
            'source_id' => $schema->string()->description('Issue: card id or key. Page: uuid. Ticket: id or key. Plan: id or slug.')->required(),
            'target_type' => $schema->string()->enum($types)->description('Type of the linked content.')->required(),
            'target_id' => $schema->string()->description('Issue: card id or key. Page: uuid. Ticket: id or key. Plan: id or slug.')->required(),
            'request_id' => $schema->string()->description('Unique id for this write (e.g. a UUID). Reuse it only to retry the same link.')->required(),
        ];
    }
}
