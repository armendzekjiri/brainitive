<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Plan>
 */
class PlanFactory extends Factory
{
    /**
     * Define the model's default state: one group with two tasks, a milestone and a dependency.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'project_id' => Project::factory(),
            'name' => ucfirst($name),
            'slug' => Str::slug($name),
            'created_by' => User::factory(),
            'data' => [
                'rows' => [
                    ['id' => 'r1', 'name' => 'Phase 1', 'level' => 0, 'bars' => []],
                    ['id' => 'r2', 'name' => 'Design', 'level' => 1, 'who' => 'Nora', 'status' => 'in_progress', 'bars' => [
                        ['id' => 'b1', 'start' => '2026-11-02', 'end' => '2026-11-13', 'type' => 'bar', 'color' => '#0E83DC', 'progress' => 50],
                    ]],
                    ['id' => 'r3', 'name' => 'Build', 'level' => 1, 'bars' => [
                        ['id' => 'b2', 'start' => '2026-11-16', 'end' => '2026-12-04', 'type' => 'bar', 'color' => '#22C55E'],
                    ]],
                    ['id' => 'r4', 'name' => 'Go live', 'level' => 0, 'bars' => [
                        ['id' => 'b3', 'start' => '2026-12-07', 'end' => '2026-12-07', 'type' => 'milestone', 'color' => '#EF4444'],
                    ]],
                ],
                'dependencies' => [
                    ['id' => 'd1', 'from' => 'b1', 'to' => 'b2'],
                ],
            ],
        ];
    }

    public function empty(): static
    {
        return $this->state(fn () => ['data' => ['rows' => [], 'dependencies' => []]]);
    }

    public function shared(): static
    {
        return $this->state(fn () => ['share_token' => Str::random(40)]);
    }
}
