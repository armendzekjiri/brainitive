<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Mcp\Tools\Concerns\InteractsWithContentLinks;
use App\Models\ContentReference;
use App\Support\Mcp\ContentLinkFormatter;
use App\Support\Mcp\IdempotentRequest;
use App\Support\Mcp\ResourceLocator;
use App\Support\Mcp\ToolError;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Remove a "Related content" link between two items of a project (issue, doc/whiteboard, ticket, plan) — the link added from source to target, or else the one added the other way round. Needs edit rights on the content the link was added on. Removing a link that isn\'t there is a no-op (removed: false). Automatic links (from a plan row, a whiteboard card or a smart link) can\'t be removed here — change the plan / whiteboard / text instead. Pass a unique request_id.')]
class UnlinkRelatedContentTool extends Tool
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
            $source = $this->contentEndpoint($user, $project, $validated['source_type'], $this->contentRef($validated['source_id']), 'source_id');
            $target = $this->contentEndpoint($user, $project, $validated['target_type'], $this->contentRef($validated['target_id']), 'target_id');

            $reference = $this->reference($project->id, $source, $target, ContentReference::ORIGIN_MANUAL)
                ?? $this->reference($project->id, $target, $source, ContentReference::ORIGIN_MANUAL);

            if ($reference !== null) {
                // Same rule as the UI: removing needs edit rights on the content the link was added on.
                $this->authorizeFor($user, 'update', $reference->source_type === ContentReference::typeOf($source) && $reference->source_id === (int) $source->getKey() ? $source : $target);
            } elseif ($this->reference($project->id, $source, $target, ContentReference::ORIGIN_DERIVED)
                || $this->reference($project->id, $target, $source, ContentReference::ORIGIN_DERIVED)) {
                throw new ToolError('This link comes from a plan row, a whiteboard card or a smart link. Change it there.');
            } else {
                $this->authorizeFor($user, 'update', $source);
            }

            $run = IdempotentRequest::run($user, 'unlink_related_content', $validated['request_id'], [
                'project_id' => $project->id,
                'source' => ContentReference::typeOf($source).':'.$source->getKey(),
                'target' => ContentReference::typeOf($target).':'.$target->getKey(),
            ], function () use ($reference, $source) {
                $reference?->delete();

                return ['removed' => $reference !== null, 'location' => ContentLinkFormatter::locate($source)];
            });

            return Response::structured([
                'removed' => ! $run['replayed'] && $run['result']['removed'],
                'location' => $run['result']['location'],
            ]);
        });
    }

    private function reference(int $projectId, Model $from, Model $to, string $origin): ?ContentReference
    {
        return ContentReference::query()
            ->where('project_id', $projectId)
            ->where('source_type', ContentReference::typeOf($from))
            ->where('source_id', $from->getKey())
            ->where('target_type', ContentReference::typeOf($to))
            ->where('target_id', $to->getKey())
            ->where('origin', $origin)
            ->first();
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'removed' => $schema->boolean()->description('False when there was no such link or this request_id was already applied (nothing changed).'),
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
            'project' => $schema->string()->description('The project slug.')->required(),
            'source_type' => $schema->string()->enum($types)->description('item = issue, page = doc or whiteboard, ticket, plan.')->required(),
            'source_id' => $schema->string()->description('Issue: card id or key. Page: uuid. Ticket: id or key. Plan: id or slug.')->required(),
            'target_type' => $schema->string()->enum($types)->description('item = issue, page = doc or whiteboard, ticket, plan.')->required(),
            'target_id' => $schema->string()->description('Issue: card id or key. Page: uuid. Ticket: id or key. Plan: id or slug.')->required(),
            'request_id' => $schema->string()->description('Unique id for this write (e.g. a UUID). Reuse it only to retry the same removal.')->required(),
        ];
    }
}
