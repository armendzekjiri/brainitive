<?php

namespace Database\Factories;

use App\Models\Page;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PageVersion>
 */
class PageVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'page_id' => Page::factory(),
            'version' => 1,
            'name' => fake()->sentence(3),
            'icon' => '📄',
            'content' => ['type' => 'lexical', 'html' => '<p>'.fake()->sentence().'</p>'],
            'message' => null,
            'created_by' => User::factory(),
        ];
    }
}
