<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Release;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Release>
 */
class ReleaseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => 'v'.fake()->unique()->numerify('#.#.#'),
            'status' => Release::STATUS_UNRELEASED,
        ];
    }
}
