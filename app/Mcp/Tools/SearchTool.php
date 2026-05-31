<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\SearchController;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
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
#[Description('Search across all of the user\'s Brainitive content — workspaces, projects, pages/documents, board items, snippets and bookmarks. Returns matches with a "type:id" handle you can pass to the fetch tool to read the full content.')]
class SearchTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'query' => 'required|string',
        ]);

        $user = $request->user();

        // Reuse the app's global search exactly (same scoping & permissions).
        $httpRequest = HttpRequest::create('/search', 'GET', ['q' => $validated['query']]);
        $httpRequest->setUserResolver(fn () => $user);

        $payload = app(SearchController::class)($httpRequest)->getData(true);

        $results = [];
        foreach (['workspaces', 'projects', 'pages', 'items', 'snippets', 'bookmarks'] as $group) {
            foreach ($payload[$group] ?? [] as $row) {
                $results[] = [
                    'id' => $row['type'].':'.$row['id'],
                    'title' => $row['title'],
                    'url' => $row['url'] ?? null,
                    'subtitle' => $row['subtitle'] ?? null,
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
            ])),
        ];
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('The search query.')->required(),
        ];
    }
}
