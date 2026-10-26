<?php

namespace App\Mcp\Tools;

use App\Support\Search\PlaceSearch;
use App\Support\Search\SearchQuery;
use App\Support\Search\SearchService;
use App\Support\Search\SearchTypes;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * Named exactly `search` so it satisfies ChatGPT deep-research connectors,
 * which expect a `search` tool returning `{ results: [{ id, title, url }] }`.
 * The id is a "type:id" handle that the `fetch` tool knows how to resolve.
 *
 * NOTE: verify the exact field names against the current OpenAI deep-research
 * connector docs if ChatGPT changes the contract; the general tools work
 * regardless of this one.
 */
#[Name('search')]
#[Description('Search everything the user can open in Brainitive — issues (by key, title, description, labels), docs, comments, helpdesk tickets, plans, tasks, snippets, bookmarks, their private captures, file names, projects and workspaces — ranked, typo tolerant. Supports "exact phrases" and -excluded words, and optional filters. Returns matches with a "type:id" handle you can pass to the fetch tool to read the full content.')]
class SearchTool extends Tool
{
    /** The fetch tool reads comments / files through their issue or ticket, snippets and bookmarks through their collection. */
    private const FETCH_HANDLES = [
        SearchTypes::COMMENT => 'item',
        SearchTypes::ATTACHMENT => 'item',
        SearchTypes::TICKET_ATTACHMENT => 'ticket',
        SearchTypes::SNIPPET => 'snippet',
        SearchTypes::BOOKMARK => 'bookmark',
    ];

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'query' => 'required|string|max:300',
            'type' => 'nullable|string|in:'.implode(',', array_keys(SearchTypes::GROUPS)),
            'status' => 'nullable|string|in:open,done',
            'assigned_to_me' => 'nullable|boolean',
            'limit' => 'nullable|integer|min:1|max:'.SearchQuery::MAX_PER_PAGE,
        ]);

        $user = $request->user();

        // the same service, visibility and ranking as the app's search page (BRAI-55)
        $found = app(SearchService::class)->search($user, SearchService::queryFrom([
            'q' => $validated['query'],
            'type' => $validated['type'] ?? null,
            'status' => $validated['status'] ?? null,
            'assignee' => ! empty($validated['assigned_to_me']) ? 'me' : null,
        ], $user, (int) ($validated['limit'] ?? 20)));

        $results = [];
        foreach ($found['results'] as $row) {
            $handle = isset(self::FETCH_HANDLES[$row['type']])
                ? self::FETCH_HANDLES[$row['type']].':'.($row['container_id'] ?? $row['source_id'])
                : $row['id'];
            $results[] = [
                'id' => $handle,
                'title' => $row['title'],
                'url' => $row['url'],
                'subtitle' => trim($row['label'].($row['context'] ? ' · '.$row['context'] : '')),
                'snippet' => html_entity_decode(strip_tags((string) $row['snippet_html']), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ];
        }

        // workspaces and projects by name (fetchable as workspace:<id> / project:<id>)
        if (empty($validated['type']) && empty($validated['status']) && empty($validated['assigned_to_me']) && mb_strlen(trim($validated['query'])) >= 2) {
            $places = PlaceSearch::find($user, trim($validated['query']), 0);
            foreach ([...$places['projects'], ...$places['workspaces']] as $place) {
                $results[] = [
                    'id' => $place['type'].':'.$place['id'],
                    'title' => $place['title'],
                    'url' => $place['url'],
                    'subtitle' => ucfirst($place['type']).($place['subtitle'] ? ' · '.$place['subtitle'] : ''),
                    'snippet' => '',
                ];
            }
        }

        return Response::structured(['results' => $results]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'results' => $schema->array()->items($schema->object([
                'id' => $schema->string()->description('"type:id" handle to pass to fetch.'),
                'title' => $schema->string(),
                'url' => $schema->string(),
                'subtitle' => $schema->string(),
                'snippet' => $schema->string()->description('The matching passage.'),
            ])),
        ];
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Words to find. "quoted words" match as a phrase, -word excludes, an issue key like WEB-12 finds that issue first.')->required(),
            'type' => $schema->string()->enum(array_keys(SearchTypes::GROUPS))->description('Only this kind of content.'),
            'status' => $schema->string()->enum(['open', 'done'])->description('Only open or done issues, tickets, tasks and captures.'),
            'assigned_to_me' => $schema->boolean()->description('Only issues, tickets and tasks assigned to the user.'),
            'limit' => $schema->integer()->description('Maximum results (default 20, max 50).'),
        ];
    }
}
