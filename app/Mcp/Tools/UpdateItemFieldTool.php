<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Support\Boards\ItemFields;
use App\Support\Mcp\ToolError;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Change a custom field: rename it, replace its select options (pass option_id to keep / rename an option; options left out are archived, never deleted), limit it to some boards, or archive / restore it. The type can\'t change once the field has values.')]
class UpdateItemFieldTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
            'field_id' => 'required|integer',
            'name' => 'sometimes|string|max:100',
            'options' => 'sometimes|array|max:100',
            'options.*.option_id' => 'nullable|integer',
            'options.*.label' => 'required|string|max:100',
            'options.*.color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
            'boards' => 'sometimes|nullable|array|max:200',
            'boards.*' => 'string',
            'archived' => 'sometimes|boolean',
        ]);
        $user = $request->user();

        return $this->guard(function () use ($user, $v) {
            $project = $this->projectForUser($user, $v['workspace'], $v['project']);
            $this->authorizeFor($user, 'update', $project);
            $field = $project->itemFields()->find($v['field_id']);
            if (! $field) {
                throw new ToolError('No custom field with that id in this project. See get_project_config.');
            }

            $data = ['name' => $v['name'] ?? $field->name];
            if (array_key_exists('archived', $v)) {
                $data['archived'] = $v['archived'];
            }
            if (array_key_exists('options', $v)) {
                if ($field->type !== 'select') {
                    throw new ToolError("\"{$field->name}\" is a {$field->type} field; only select fields have options.");
                }
                $data['options'] = array_map(fn ($o) => ['id' => $o['option_id'] ?? null, 'label' => $o['label'], 'color' => $o['color'] ?? null], $v['options']);
            }
            if (array_key_exists('boards', $v)) {
                if (empty($v['boards'])) {
                    $data['board_ids'] = null;
                } else {
                    $boards = $project->boards()->get();
                    $data['board_ids'] = collect($v['boards'])->map(function (string $slug) use ($boards) {
                        $board = $boards->first(fn ($b) => $b->slug === $slug || $this->sameName($b->name, $slug));
                        if (! $board) {
                            throw new ToolError("No board \"{$slug}\" in this project. Boards: ".$boards->pluck('slug')->join(', ').'.');
                        }

                        return $board->id;
                    })->all();
                }
            }

            $message = ItemFields::update($project, $field, $data);
            $field->refresh()->load('options');

            return Response::structured([
                'updated' => true,
                'message' => $message,
                'field_id' => $field->id,
                'name' => $field->name,
                'archived' => $field->isArchived(),
                'options' => $field->options->whereNull('archived_at')->sortBy('position')->values()->map(fn ($o) => ['option_id' => $o->id, 'label' => $o->label])->all(),
            ]);
        });
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'updated' => $schema->boolean(),
            'message' => $schema->string(),
            'field_id' => $schema->integer(),
            'name' => $schema->string(),
            'archived' => $schema->boolean(),
            'options' => $schema->array()->items($schema->object(['option_id' => $schema->integer(), 'label' => $schema->string()])),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
            'project' => $schema->string()->description('The project slug.')->required(),
            'field_id' => $schema->integer()->description('The field id (from get_project_config).')->required(),
            'name' => $schema->string()->description('New name.'),
            'options' => $schema->array()->items($schema->object([
                'option_id' => $schema->integer()->description('Keep / rename this existing option.'),
                'label' => $schema->string()->required(),
                'color' => $schema->string(),
            ]))->description('The full option list in order (select fields).'),
            'boards' => $schema->array()->items($schema->string())->description('Board slugs; an empty list = every board.'),
            'archived' => $schema->boolean()->description('true archives the field (values are kept), false restores it.'),
        ];
    }
}
