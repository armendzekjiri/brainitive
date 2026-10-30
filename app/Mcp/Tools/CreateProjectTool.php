<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Mcp\Tools\Concerns\SetsUpBoards;
use App\Models\Project;
use App\Support\Entitlements\LimitGate;
use App\Support\Mcp\IdempotentRequest;
use App\Support\Mcp\ResourceLocator;
use App\Support\Mcp\ToolError;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Create a project in a workspace with its first board. `key` is the issue prefix (letters only, e.g. "SHOP" → SHOP-1). Pass `columns` to define the board\'s statuses in order (otherwise To Do / In Progress / Done); mark the last one is_done. Returns the project and board slugs plus the column ids. Follow up with create_label, create_item_type, create_item_field, add_project_member and create_board_items to set the rest up.')]
class CreateProjectTool extends Tool
{
    use InteractsWithBrainitive;
    use SetsUpBoards;

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate([
            'workspace' => 'required|string',
            'name' => 'required|string|max:255',
            'key' => 'required|string|max:10|alpha',
            'description' => 'nullable|string|max:50000',
            'board_name' => 'nullable|string|max:255',
            'board_type' => 'nullable|in:kanban,scrum',
            'request_id' => 'required|string|max:100',
        ] + $this->columnRules());
        $user = $request->user();

        return $this->guard(function () use ($user, $v) {
            $workspace = $this->workspaceForUser($user, $v['workspace']);
            $this->authorizeFor($user, 'createProjects', $workspace);

            $payload = collect($v)->except('request_id')->all();
            $run = IdempotentRequest::run($user, 'create_project', $v['request_id'], $payload, function () use ($user, $workspace, $v) {
                // plan limits (v1.46.0) — inside the run, so a retry of a finished request still answers
                app(LimitGate::class)->ensureCanCreateProject($workspace);
                app(LimitGate::class)->ensureContentRoom($workspace);
                $key = strtoupper($v['key']);
                // Deleted projects keep their row (soft delete) and their unique name / key / slug.
                $projects = $workspace->projects()->withTrashed();
                if ((clone $projects)->where('name', $v['name'])->exists()) {
                    throw new ToolError("A project named \"{$v['name']}\" already exists in this workspace (or did, and was deleted). Call list_projects to see it, or pick another name.");
                }
                if ((clone $projects)->where('key', $key)->exists()) {
                    throw new ToolError("The key {$key} is already used by another project in this workspace (or was, before it was deleted). Choose another key.");
                }

                $slug = Str::slug($v['name']) ?: 'project';
                for ($base = $slug, $n = 2; (clone $projects)->where('slug', $slug)->exists(); $n++) {
                    $slug = "{$base}-{$n}";
                }

                /** @var Project $project */
                $project = $workspace->projects()->create([
                    'name' => $v['name'],
                    'slug' => $slug,
                    'key' => $key,
                    'description' => $v['description'] ?? null,
                    'created_by' => $user->id,
                ]);
                // Creator is added as project admin so they have access (same as the UI)
                $project->addUser($user, 'admin');

                $board = $this->createBoard($project, $user, $v['board_name'] ?? 'Main Board', null, $v['board_type'] ?? 'kanban', $v['columns'] ?? null);

                return [
                    'project_slug' => $project->slug,
                    'key' => $project->key,
                    'board_slug' => $board->slug,
                    'columns' => $board->columns()->orderBy('position')->get()->map(fn ($c) => $this->columnSummary($c))->all(),
                    'location' => ResourceLocator::locate($project),
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
            'project_slug' => $schema->string(),
            'key' => $schema->string(),
            'board_slug' => $schema->string(),
            'columns' => $schema->array()->items($this->columnSchema($schema)),
            'location' => ResourceLocator::outputSchema($schema),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
            'name' => $schema->string()->description('The project name.')->required(),
            'key' => $schema->string()->description('Issue key prefix, 1–10 letters (e.g. SHOP).')->required(),
            'description' => $schema->string()->description('Optional description (Markdown is fine).'),
            'board_name' => $schema->string()->description('Name of the first board. Default "Main Board".'),
            'board_type' => $schema->string()->description('"kanban" (default) or "scrum" (sprints + backlog).'),
            'columns' => $this->columnsArg($schema),
            'request_id' => $schema->string()->description('Unique id for this write; reuse only to retry it.')->required(),
        ];
    }
}
