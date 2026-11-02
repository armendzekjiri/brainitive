<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DesktopDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_download_page_is_public_and_empty_by_default(): void
    {
        $this->get('/download')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Download')
                ->where('downloads.macos', null)
                ->where('downloads.windows', null)
                ->where('downloads.linux', null));
    }

    public function test_configured_urls_become_download_links_and_junk_is_dropped(): void
    {
        config([
            'desktop.downloads.macos' => 'https://downloads.example.com/Brainitive.dmg',
            'desktop.downloads.windows' => 'javascript:alert(1)',
            'desktop.downloads.linux' => '',
        ]);

        $this->get('/download')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('downloads.macos', 'https://downloads.example.com/Brainitive.dmg')
                ->where('downloads.windows', null)
                ->where('downloads.linux', null));
    }
}
