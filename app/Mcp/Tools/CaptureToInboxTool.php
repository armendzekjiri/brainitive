<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Models\Capture;
use App\Support\Mcp\IdempotentRequest;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Save a quick note or link to the user\'s personal capture inbox to sort later (no workspace or project needed). Use it when the user wants to jot something down fast; they turn captures into issues, tasks, docs, bookmarks or snippets in Brainitive. Returns the inbox location.')]
class CaptureToInboxTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate([
            'text' => 'required|string|max:'.Capture::MAX_TEXT,
            'url' => 'nullable|url:http,https|max:2048',
            'intended_type' => ['nullable', Rule::in(Capture::TYPES)],
            'source' => 'nullable|string|max:255',
            'request_id' => 'required|string|max:100',
        ]);
        $user = $request->user();

        return $this->guard(function () use ($user, $v) {
            $run = IdempotentRequest::run($user, 'capture_to_inbox', $v['request_id'], collect($v)->except('request_id')->all(), function () use ($user, $v) {
                $capture = Capture::create([
                    'user_id' => $user->id,
                    'text' => $v['text'],
                    'url' => $v['url'] ?? null,
                    'intended_type' => $v['intended_type'] ?? null,
                    'source' => ($v['source'] ?? null) ?: 'MCP',
                ]);

                return [
                    'capture_id' => $capture->uuid,
                    'location' => [
                        'type' => 'capture',
                        'title' => Str::limit(strtok($capture->text, "\n") ?: $capture->text, 80),
                        'workspace' => '',
                        'project' => '',
                        'breadcrumb' => 'Captures',
                        'url' => route('captures.index'),
                    ],
                ];
            });

            return Response::structured(['created' => ! $run['replayed']] + $run['result']);
        });
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'created' => $schema->boolean(),
            'capture_id' => $schema->string(),
            'location' => $schema->object([
                'type' => $schema->string(),
                'title' => $schema->string(),
                'workspace' => $schema->string(),
                'project' => $schema->string(),
                'breadcrumb' => $schema->string(),
                'url' => $schema->string(),
            ]),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()->description('The note. The first line becomes the title when it is converted.')->required(),
            'url' => $schema->string()->description('Optional link the note is about.'),
            'intended_type' => $schema->string()->description('Optional hint for later: issue, task, page, bookmark or snippet.'),
            'source' => $schema->string()->description('Where it came from, e.g. "Claude" or "CLI". Default "MCP".'),
            'request_id' => $schema->string()->description('Unique id for this write; reuse only to retry it.')->required(),
        ];
    }
}
