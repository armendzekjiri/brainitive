<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Page>
 */
class PageFactory extends Factory
{
    /**
     * Define the model's default state: a never-published document.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'created_by' => User::factory(),
            'name' => fake()->sentence(3),
            'icon' => '📄',
            'type' => 'page',
            'status' => 'draft',
            'content' => ['type' => 'lexical', 'html' => '<p>'.fake()->sentence().'</p>'],
        ];
    }

    /**
     * A published page with its first version recorded.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'published',
            'published_at' => now(),
        ])->afterCreating(function ($page) {
            $page->recordVersion($page->creator);
        });
    }

    /**
     * A folder (always visible to project members).
     */
    public function folder(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'folder',
            'icon' => '📁',
            'content' => null,
        ]);
    }
}
