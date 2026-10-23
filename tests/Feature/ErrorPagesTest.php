<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_unknown_url_shows_the_error_page_for_guests(): void
    {
        $this->get('/this/does/not/exist')
            ->assertNotFound()
            ->assertInertia(fn ($page) => $page->component('Error')->where('status', 404)->where('auth.user', null));
        $this->assertFileExists(resource_path('js/Pages/Error.tsx'));
    }

    public function test_unknown_url_knows_you_are_signed_in(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/nothing-here')
            ->assertNotFound()
            ->assertInertia(fn ($page) => $page->component('Error')->where('auth.user.id', $user->id));
    }

    public function test_forbidden_and_missing_models_use_the_error_page(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addUser($owner, 'admin');

        $this->actingAs(User::factory()->create())->get(route('workspaces.show', $workspace->slug))
            ->assertForbidden()
            ->assertInertia(fn ($page) => $page->component('Error')->where('status', 403));

        // A missing model fails in route binding, before the shared props middleware.
        $this->actingAs($owner)->get(route('workspaces.show', 'no-such-workspace'))
            ->assertNotFound()
            ->assertInertia(fn ($page) => $page->component('Error')->where('status', 404)->where('auth.user.id', $owner->id));
    }

    public function test_server_errors_hide_details_in_production(): void
    {
        config(['app.debug' => false]);
        Route::middleware('web')->get('/_boom', fn () => throw new \RuntimeException('secret stack detail'));

        $response = $this->get('/_boom')->assertStatus(500);
        $response->assertInertia(fn ($page) => $page->component('Error')->where('status', 500));
        $this->assertStringNotContainsString('secret stack detail', $response->getContent());
    }

    public function test_expired_session_sends_guests_to_login_with_a_message(): void
    {
        Route::middleware('web')->post('/_expired', fn () => throw new TokenMismatchException);

        $this->post('/_expired')->assertRedirect(route('login'))->assertSessionHas('status', 'Your session expired. Please sign in again.');

        $this->actingAs(User::factory()->create())
            ->from('/dashboard')->post('/_expired')
            ->assertRedirect('/dashboard')
            ->assertSessionHas('error');
    }

    public function test_api_errors_stay_json(): void
    {
        $this->getJson('/api/does-not-exist')->assertNotFound()->assertJsonStructure(['message']);
    }

    public function test_a_wrong_http_method_shows_the_405_page_not_a_crash(): void
    {
        // No route matched, so there is no session — shared flash props must not need one.
        $this->withoutVite();
        $this->put('/login')->assertStatus(405)->assertInertia(fn ($page) => $page->component('Error')->where('status', 405));
    }
}
