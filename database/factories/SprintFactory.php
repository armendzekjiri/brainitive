<?php

namespace Database\Factories;

use App\Models\Board;
use App\Models\Sprint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sprint>
 */
class SprintFactory extends Factory
{
    /**
     * Define the model's default state: a planned sprint.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'board_id' => Board::factory(),
            'name' => 'Sprint '.fake()->unique()->numberBetween(1, 999),
            'status' => Sprint::STATUS_PLANNED,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Sprint::STATUS_ACTIVE,
            'start_date' => now()->subDays(3)->toDateString(),
            'end_date' => now()->addDays(11)->toDateString(),
            'started_at' => now()->subDays(3),
        ]);
    }
}
