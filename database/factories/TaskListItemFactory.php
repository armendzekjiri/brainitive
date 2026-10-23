<?php

namespace Database\Factories;

use App\Models\TaskList;
use App\Models\TaskListItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskListItem>
 */
class TaskListItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'task_list_id' => TaskList::factory(),
            'title' => ucfirst(fake()->words(3, true)),
            'status' => 'todo',
            'position' => 0,
            'created_by' => User::factory(),
        ];
    }

    public function done(): static
    {
        return $this->state(['status' => 'done']);
    }
}
