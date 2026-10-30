<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Models\ItemField;
use App\Support\Boards\ItemFields;
use App\Support\Mcp\ToolError;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Create a custom field on a project\'s issues (project admins): type text, number, date, checkbox or select (with options). It shows on every board unless `boards` lists some. Same name again returns the existing field. Values are then set per card through `fields` on create_board_item(s) / update_board_item.')]
class CreateItemFieldTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
            'name' => 'required|string|max:100',
            'type' => ['required', Rule::in(ItemField::TYPES)],
            'options' => 'nullable|array|max:'.ItemField::MAX_OPTIONS,
            'options.*.label' => 'required|string|max:100',
            'options.*.color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
            'boards' => 'nullable|array|min:1|max:200',
            'boards.*' => 'string',
        ]);
        $user = $request->user();

        return $this->guard(function () use ($user, $v) {
            $project = $this->projectForUser($user, $v['workspace'], $v['project']);
            $this->authorizeFor($user, 'update', $project);

            $existing = $project->itemFields()->active()->get()->first(fn ($f) => $this->sameName($f->name, $v['name']));
            if ($existing) {
                return Response::structured(['created' => false] + $this->summary($existing));
            }
            if ($v['type'] === 'select' && empty($v['options'])) {
                throw new ToolError('A select field needs at least one option.');
            }

            $boardIds = null;
            if (! empty($v['boards'])) {
                $boards = $project->boards()->get();
                $boardIds = collect($v['boards'])->map(function (string $slug) use ($boards) {
                    $board = $boards->first(fn ($b) => $b->slug === $slug || $this->sameName($b->name, $slug));
                    if (! $board) {
                        throw new ToolError("No board \"{$slug}\" in this project. Boards: ".$boards->pluck('slug')->join(', ').'.');
                    }

                    return $board->id;
                })->all();
            }

            $field = ItemFields::create($project, [
                'name' => $v['name'],
                'type' => $v['type'],
                'board_ids' => $boardIds,
                'options' => $v['options'] ?? [],
            ]);

            return Response::structured(['created' => true] + $this->summary($field->load('options')));
        });
    }

    /** @return array<string, mixed> */
    private function summary(ItemField $field): array
    {
        return [
            'field_id' => $field->id,
            'name' => $field->name,
            'type' => $field->type,
            'options' => $field->options->whereNull('archived_at')->sortBy('position')->values()->map(fn ($o) => ['option_id' => $o->id, 'label' => $o->label])->all(),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'created' => $schema->boolean(),
            'field_id' => $schema->integer(),
            'name' => $schema->string(),
            'type' => $schema->string(),
            'options' => $schema->array()->items($schema->object(['option_id' => $schema->integer(), 'label' => $schema->string()])),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
            'project' => $schema->string()->description('The project slug.')->required(),
            'name' => $schema->string()->description('The field name (≤ 100 chars).')->required(),
            'type' => $schema->string()->description('text, number, date, checkbox or select.')->required(),
            'options' => $schema->array()->items($schema->object([
                'label' => $schema->string()->required(),
                'color' => $schema->string()->description('Optional hex color.'),
            ]))->description('Options for a select field, in order.'),
            'boards' => $schema->array()->items($schema->string())->description('Board slugs the field shows on. Default: every board.'),
        ];
    }
}
