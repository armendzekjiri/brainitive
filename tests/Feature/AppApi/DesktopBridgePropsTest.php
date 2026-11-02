<?php

namespace Tests\Feature\AppApi;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The web ↔ desktop bridge (BRAI-352, resources/js/lib/desktop.ts) reads the oldest supported
 * Brainitive Desktop version from the shared Inertia props, so the web app inside an outdated
 * shell can ask for an update.
 */
class DesktopBridgePropsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_every_page_shares_the_minimum_desktop_version(): void
    {
        config(['app-api.min_versions.desktop' => '0.4.2']);

        $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('desktopMinVersion', '0.4.2'));

        $this->actingAs(User::factory()->create())->get('/profile')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('desktopMinVersion', '0.4.2'));
    }
}
