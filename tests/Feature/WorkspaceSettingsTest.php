<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->owner->id]);
        $this->workspace->addUser($this->owner, 'admin');
    }

    public function test_the_settings_page_offers_every_timezone(): void
    {
        $this->actingAs($this->owner)
            ->get(route('workspaces.edit', $this->workspace))
            ->assertInertia(fn ($page) => $page
                ->where('timezones', \DateTimeZone::listIdentifiers())
                ->where('timezones', fn ($zones) => collect($zones)->contains('Pacific/Auckland') && collect($zones)->contains('Europe/Berlin')));
    }

    public function test_any_timezone_can_be_saved_but_not_a_made_up_one(): void
    {
        $this->actingAs($this->owner)
            ->put(route('workspaces.update', $this->workspace), ['name' => $this->workspace->name, 'timezone' => 'Pacific/Auckland'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Pacific/Auckland', $this->workspace->fresh()->timezone);

        $this->actingAs($this->owner)
            ->put(route('workspaces.update', $this->workspace), ['name' => $this->workspace->name, 'timezone' => 'Mars/Olympus_Mons'])
            ->assertSessionHasErrors('timezone');
        $this->assertSame('Pacific/Auckland', $this->workspace->fresh()->timezone);
    }
}
