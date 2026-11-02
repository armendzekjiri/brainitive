<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Codex web review N: sticky-note coordinates fit the int32 columns (422, not a 500).
 */
class StickyNoteCoordinatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_sticky_note_coordinates_are_bounded_on_create_and_update(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson(route('page-sticky-notes.store'), ['url' => '/x', 'x' => 2147483648])->assertUnprocessable()->assertJsonValidationErrors('x');
        $this->actingAs($user)->postJson(route('page-sticky-notes.store'), ['url' => '/x', 'y' => -1000001])->assertUnprocessable()->assertJsonValidationErrors('y');

        $id = $this->actingAs($user)->postJson(route('page-sticky-notes.store'), ['url' => '/x', 'x' => 1000000, 'y' => -1000000])->assertSuccessful()->json('id');
        $this->actingAs($user)->patchJson(route('page-sticky-notes.update', $id), ['x' => 2147483648])->assertUnprocessable()->assertJsonValidationErrors('x');
        $this->actingAs($user)->patchJson(route('page-sticky-notes.update', $id), ['y' => 1000001])->assertUnprocessable()->assertJsonValidationErrors('y');
    }
}
