<?php

namespace App\Http\Requests\AppApi\Concerns;

use App\Models\Board;
use App\Models\Item;
use App\Models\Project;
use App\Support\Boards\IssueMarkdown;
use App\Support\Boards\Issues;

/**
 * The issue fields of the App API (BRAI-404) → the web's rules and columns (Support\Boards\Issues).
 * The API names the people / type fields like the rest of the API (`assignee_id`, `type_id`) and takes
 * the description as Markdown.
 */
trait IssueFields
{
    /** API field → the column / web field. */
    private const COLUMN_NAMES = ['assignee_id' => 'assigned_to', 'type_id' => 'item_type_id'];

    /**
     * @return array<string, mixed>
     */
    protected function issueRules(?Project $project, ?Board $board, ?Item $item = null): array
    {
        $web = $project !== null && $board !== null ? Issues::rules($project, $board, $item) : self::documentedRules($item !== null);
        $names = array_flip(self::COLUMN_NAMES);

        $rules = [];
        foreach ($web as $field => $rule) {
            $rules[$names[$field] ?? $field] = $rule;
        }
        // Markdown; mentions as @[Name](user:<id>)
        $rules['description'] = ['nullable', 'string', 'max:100000'];
        if ($item === null) {
            // a column of the board (default: its first column)
            $rules['column_id'] = ['sometimes', ...array_slice((array) $rules['column_id'], 1)];
        } else {
            // the whole list of label ids the issue should have ([] = none)
            $rules['label_ids'] = ['sometimes', 'array', 'max:100'];
            $rules['label_ids.*'] = ['integer'];
        }
        // custom field values: {"<field id>": value | null}; select fields take an option id
        $rules['fields'] = ['sometimes', 'array', 'max:50'];

        return $rules;
    }

    /**
     * Validated API input → what Issues::create / update take.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function issueColumns(array $input, Project $project): array
    {
        $data = [];
        foreach ($input as $field => $value) {
            $data[self::COLUMN_NAMES[$field] ?? $field] = $value;
        }
        if (array_key_exists('description', $data)) {
            $data['description'] = IssueMarkdown::toHtml($data['description'], $project);
        }

        return $data;
    }

    /**
     * The rules' shape for the OpenAPI generator (it reads them without a board).
     *
     * @return array<string, mixed>
     */
    private static function documentedRules(bool $update): array
    {
        return [
            'title' => $update ? 'sometimes|required|string|max:255' : 'required|string|max:255',
            'description' => 'nullable|string',
            'item_type_id' => ['nullable', 'integer'],
            'priority_id' => ['nullable', 'integer'],
            'assigned_to' => ['nullable', 'integer'],
            'start_date' => 'nullable|date',
            'start_time' => 'nullable|date_format:H:i',
            'due_date' => 'nullable|date',
            'due_time' => 'nullable|date_format:H:i',
            'parent_id' => ['nullable', 'integer'],
            'story_points' => 'nullable|integer|min:0',
            'release_id' => ['nullable', 'integer'],
            'original_estimate' => ['nullable', 'string', 'max:50'],
        ] + ($update ? [] : [
            'column_id' => ['required', 'integer'],
            'label_ids' => 'nullable|array',
            'label_ids.*' => 'integer',
            'sprint_id' => ['nullable', 'integer'],
        ]);
    }
}
