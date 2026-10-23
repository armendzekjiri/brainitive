<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\TaskList;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TaskList>
 */
class TaskListFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'project_id' => Project::factory(),
            'name' => ucfirst($name),
            'slug' => Str::slug($name),
            'position' => 0,
            'created_by' => User::factory(),
        ];
    }
}
