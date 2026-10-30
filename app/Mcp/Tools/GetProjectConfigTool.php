<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Models\ItemPriority;
use App\Models\ItemType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Read how a project is set up: every board with its columns (statuses), the labels, item types, priorities, custom fields with their options, and the members with roles. Call it before creating cards in bulk or when mapping an import (Jira, Trello…) onto the project, and again after changing the setup to get the new ids.')]
class GetProjectConfigTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate(['workspace' => 'required|string', 'project' => 'required|string']);
        $user = $request->user();

        return $this->guard(function () use ($user, $v) {
            $project = $this->projectForUser($user, $v['workspace'], $v['project']);

            $boards = $project->boards()->orderBy('position')->orderBy('id')->with('columns')->get();
            $boardSlugs = $boards->pluck('slug', 'id');

            return Response::structured([
                'project' => [
                    'name' => $project->name,
                    'slug' => $project->slug,
                    'key' => $project->key ?? '',
                    'description' => $project->description ?? '',
                ],
                'boards' => $boards->map(fn ($b) => [
                    'slug' => $b->slug,
                    'name' => $b->name,
                    'type' => $b->type ?? 'kanban',
                    'columns' => $b->columns->sortBy('position')->values()->map(fn ($c) => $this->columnSummary($c))->all(),
                ])->all(),
                'labels' => $project->labels()->orderBy('name')->get()->map(fn ($l) => [
                    'label_id' => $l->id, 'name' => $l->name, 'slug' => $l->slug ?? '', 'color' => $l->color ?? '',
                ])->all(),
                'item_types' => ItemType::forProject($project)->orderBy('project_id')->orderBy('id')->get()->map(fn ($t) => [
                    'type_id' => $t->id, 'name' => $t->name, 'slug' => $t->slug ?? '', 'color' => $t->color ?? '',
                    'is_default' => (bool) $t->is_default, 'scope' => $t->project_id === null ? 'instance' : 'project',
                ])->all(),
                'priorities' => ItemPriority::forProject($project)->orderBy('order')->get()->map(fn ($p) => [
                    'priority_id' => $p->id, 'name' => $p->name, 'slug' => $p->slug ?? '', 'color' => $p->color ?? '',
                    'is_default' => (bool) $p->is_default, 'scope' => $p->project_id === null ? 'instance' : 'project',
                ])->all(),
                'fields' => $project->itemFields()->with('options')->orderBy('position')->orderBy('id')->get()->map(fn ($f) => [
                    'field_id' => $f->id,
                    'name' => $f->name,
                    'type' => $f->type,
                    'archived' => $f->isArchived(),
                    'boards' => $f->board_ids === null ? [] : collect($f->board_ids)->map(fn ($id) => $boardSlugs[(int) $id] ?? '')->filter()->values()->all(),
                    'options' => $f->options->sortBy('position')->values()->map(fn ($o) => [
                        'option_id' => $o->id, 'label' => $o->label, 'color' => $o->color ?? '', 'archived' => $o->archived_at !== null,
                    ])->all(),
                ])->all(),
                'members' => $project->users()->orderBy('name')->get()->map(fn ($m) => [
                    'name' => $m->name, 'email' => $m->email, 'role' => $m->pivot->role ?? '',
                ])->all(),
            ]);
        });
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->object([
                'name' => $schema->string(), 'slug' => $schema->string(), 'key' => $schema->string(), 'description' => $schema->string(),
            ]),
            'boards' => $schema->array()->items($schema->object([
                'slug' => $schema->string(), 'name' => $schema->string(), 'type' => $schema->string(),
                'columns' => $schema->array()->items($this->columnSchema($schema)),
            ])),
            'labels' => $schema->array()->items($schema->object([
                'label_id' => $schema->integer(), 'name' => $schema->string(), 'slug' => $schema->string(), 'color' => $schema->string(),
            ])),
            'item_types' => $schema->array()->items($schema->object([
                'type_id' => $schema->integer(), 'name' => $schema->string(), 'slug' => $schema->string(), 'color' => $schema->string(),
                'is_default' => $schema->boolean(), 'scope' => $schema->string()->description('"instance" types are shared; "project" ones belong to this project.'),
            ])),
            'priorities' => $schema->array()->items($schema->object([
                'priority_id' => $schema->integer(), 'name' => $schema->string(), 'slug' => $schema->string(), 'color' => $schema->string(),
                'is_default' => $schema->boolean(), 'scope' => $schema->string(),
            ])),
            'fields' => $schema->array()->items($schema->object([
                'field_id' => $schema->integer(), 'name' => $schema->string(), 'type' => $schema->string()->description('text, number, date, checkbox or select.'),
                'archived' => $schema->boolean(),
                'boards' => $schema->array()->items($schema->string())->description('Board slugs the field shows on; empty = every board.'),
                'options' => $schema->array()->items($schema->object([
                    'option_id' => $schema->integer(), 'label' => $schema->string(), 'color' => $schema->string(), 'archived' => $schema->boolean(),
                ])),
            ])),
            'members' => $schema->array()->items($schema->object([
                'name' => $schema->string(), 'email' => $schema->string(), 'role' => $schema->string(),
            ])),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
            'project' => $schema->string()->description('The project slug.')->required(),
        ];
    }
}
