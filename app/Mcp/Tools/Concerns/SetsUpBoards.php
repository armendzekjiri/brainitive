<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Board;
use App\Models\Project;
use App\Models\User;
use App\Support\Mcp\ToolError;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\ArrayType;
use Illuminate\Support\Str;

/**
 * Creating a board with a given set of columns (create_project, create_board). The model
 * creates To Do / In Progress / Done on its own; when the AI names the statuses, those are
 * replaced before any card exists.
 */
trait SetsUpBoards
{
    /**
     * @return array<string, mixed>
     */
    protected function columnRules(): array
    {
        return [
            'columns' => 'nullable|array|min:1|max:30',
            'columns.*.name' => 'required|string|max:255',
            'columns.*.color' => 'nullable|string|max:20',
            'columns.*.wip_limit' => 'nullable|integer|min:0',
            'columns.*.is_done' => 'nullable|boolean',
        ];
    }

    protected function columnsArg(JsonSchema $schema): ArrayType
    {
        return $schema->array()->items($schema->object([
            'name' => $schema->string()->required(),
            'color' => $schema->string()->description('Optional hex color, e.g. #3b82f6.'),
            'wip_limit' => $schema->integer()->description('Optional work-in-progress limit.'),
            'is_done' => $schema->boolean()->description('Cards in this column count as done (usually the last one).'),
        ]))->description('Optional statuses for the board, in order; replaces the default To Do / In Progress / Done.');
    }

    /**
     * @param  list<array{name: string, color?: string|null, wip_limit?: int|null, is_done?: bool|null}>|null  $columns
     */
    protected function createBoard(Project $project, User $user, string $name, ?string $description, string $type, ?array $columns): Board
    {
        $slug = Str::slug($name) ?: 'board';
        for ($base = $slug, $n = 1; $project->boards()->withTrashed()->where('slug', $slug)->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        $board = $project->boards()->create([
            'name' => trim($name),
            'slug' => $slug,
            'description' => $description,
            'type' => $type,
            'created_by' => $user->id,
        ]);

        if ($columns !== null) {
            $this->replaceColumns($board, $columns);
        }

        return $board;
    }

    /**
     * @param  list<array{name: string, color?: string|null, wip_limit?: int|null, is_done?: bool|null}>  $columns
     */
    protected function replaceColumns(Board $board, array $columns): void
    {
        if ($board->items()->exists()) {
            throw new ToolError('This board already has cards; use create_board_column / update_board_column instead.');
        }

        $board->columns()->delete();

        $slugs = [];
        foreach (array_values($columns) as $position => $column) {
            $base = Str::slug($column['name']) ?: 'column';
            $slug = $base;
            for ($n = 2; isset($slugs[$slug]); $n++) {
                $slug = "{$base}-{$n}";
            }
            $slugs[$slug] = true;

            $board->columns()->create([
                'name' => trim($column['name']),
                'slug' => $slug,
                'color' => $column['color'] ?? null,
                'wip_limit' => $column['wip_limit'] ?? null,
                'is_done_column' => (bool) ($column['is_done'] ?? false),
                'position' => $position,
            ]);
        }
    }
}
