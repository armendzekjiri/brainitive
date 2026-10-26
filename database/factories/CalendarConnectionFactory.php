<?php

namespace Database\Factories;

use App\Models\CalendarConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A connected Google calendar with a token that is still valid (no refresh call).
 *
 * @extends Factory<CalendarConnection>
 */
class CalendarConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => 'google',
            'kind' => 'calendar',
            'account_email' => fake()->unique()->safeEmail(),
            'account_name' => fake()->name(),
            'external_calendar_id' => fake()->unique()->bothify('cal-####??'),
            'external_calendar_name' => 'Work',
            'external_calendar_color' => '#33B679',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'is_active' => true,
        ];
    }

    /** A Google Tasks list. */
    public function tasks(): static
    {
        return $this->state(['kind' => 'tasks', 'external_calendar_name' => 'My Tasks', 'external_calendar_color' => null]);
    }

    /** A connection of another (e.g. fake) provider, without OAuth tokens. */
    public function provider(string $key): static
    {
        return $this->state(['provider' => $key, 'access_token' => null, 'refresh_token' => null, 'token_expires_at' => null]);
    }
}
