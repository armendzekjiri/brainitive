<?php

namespace Database\Factories;

use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Item>
 */
class ItemFactory extends Factory
{
    /**
     * Define the model's default state: a task in the board's first column.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'board_id' => Board::factory(),
            'project_id' => fn (array $attributes) => Board::find($attributes['board_id'])->project_id,
            'column_id' => fn (array $attributes) => BoardColumn::where('board_id', $attributes['board_id'])->orderBy('position')->value('id'),
            'key' => fn (array $attributes) => Project::find($attributes['project_id'])->getNextItemKey(),
            'title' => fake()->sentence(4),
            'created_by' => User::factory(),
            'reporter_id' => fn (array $attributes) => $attributes['created_by'],
            'position' => 0,
        ];
    }
}
