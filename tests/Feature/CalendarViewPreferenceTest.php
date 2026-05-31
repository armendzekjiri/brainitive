<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CalendarViewPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_defaults_to_month_when_user_has_no_saved_view(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('calendar.index'));

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Calendar/Index')
                ->where('view', 'month'));
    }

    public function test_calendar_loads_the_users_saved_view(): void
    {
        $user = User::factory()->create([
            'calendar_view' => 'week',
        ]);

        $response = $this->actingAs($user)->get(route('calendar.index'));

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Calendar/Index')
                ->where('view', 'week'));
    }

    public function test_users_can_save_different_calendar_views(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->actingAs($user)
            ->patchJson(route('calendar.view.update'), ['view' => 'day'])
            ->assertNoContent();

        $this->actingAs($otherUser)
            ->patchJson(route('calendar.view.update'), ['view' => 'week'])
            ->assertNoContent();

        $this->assertSame('day', $user->refresh()->calendar_view);
        $this->assertSame('week', $otherUser->refresh()->calendar_view);
    }

    public function test_calendar_view_update_rejects_invalid_values(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson(route('calendar.view.update'), ['view' => 'agenda'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('view');

        $this->assertNull($user->refresh()->calendar_view);
    }
}
