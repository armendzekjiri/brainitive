<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Mcp\Tools\Concerns\InteractsWithContentLinks;
use App\Models\Item;
use App\Support\Mcp\ContentLinkFormatter;
use App\Support\Mcp\ResourceLocator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Read the links of an issue, doc/whiteboard, helpdesk ticket or plan — what the app shows as "Related content" (what it links to), "Referenced by" (what links to it, incl. automatic links from smart links, plan rows and whiteboard cards) and, for issues, "Linked issues" (blocks, is blocked by, duplicates, relates to, clones…). type: item | page | ticket | plan; id: card id or issue key, doc uuid, ticket id or key, plan id or slug. Only content you can see is returned.')]
class ListContentLinksTool extends Tool
{
    use InteractsWithBrainitive;
    use InteractsWithContentLinks;

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->guard(function () use ($request) {
            $validated = $request->validate([
                'workspace' => 'required|string',
                'project' => 'required|string',
                'type' => 'required|string|max:20',
                'id' => 'required',
            ]);

            $user = $request->user();
            $project = $this->projectForUser($user, $validated['workspace'], $validated['project']);
            $model = $this->contentEndpoint($user, $project, $validated['type'], $this->contentRef($validated['id']));
            $workspace = $project->workspace;

            $references = ContentLinkFormatter::references($workspace, $project, $user, $model);

            return Response::structured([
                'related' => $references['related'],
                'referenced_by' => $references['referenced_by'],
                'linked_issues' => $model instanceof Item ? ContentLinkFormatter::issueLinks($model, $user) : [],
                'can_link' => $references['can_link'],
                'location' => ContentLinkFormatter::locate($model),
            ]);
        });
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'related' => ContentLinkFormatter::referenceSchema($schema)->description('Related content: what this links to.'),
            'referenced_by' => ContentLinkFormatter::referenceSchema($schema)->description('Referenced by: what links to this.'),
            'linked_issues' => ContentLinkFormatter::issueLinkSchema($schema)->description('Linked issues (issues only; empty for other types).'),
            'can_link' => $schema->boolean()->description('Whether you can add / remove links on this content.'),
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
            'type' => $schema->string()->enum(['item', 'page', 'ticket', 'plan'])->description('item = issue / board card, page = doc or whiteboard, ticket = helpdesk ticket, plan = Gantt plan.')->required(),
            'id' => $schema->string()->description('Issue: card id or key (e.g. BRAI-12). Page: uuid. Ticket: id or key. Plan: id or slug.')->required(),
        ];
    }
}
