<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Link previews (v1.39.0, BRAI-109): the homepage HTML carries Open Graph + X card tags for
 * crawlers that don't run JavaScript; other pages only get the site-wide defaults, so a pasted
 * link to a private board never shows its name.
 */
class SocialMetaTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    public function test_the_homepage_has_open_graph_and_x_card_tags(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<meta property="og:title" content="Brainitive – Workspaces, Boards &amp; Wiki">', $html);
        $this->assertStringContainsString('<meta property="og:url" content="'.url('/').'">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.url('/').'">', $html);
        $this->assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $html);
        $this->assertMatchesRegularExpression('#<meta property="og:image" content="'.preg_quote(asset('images/og-image.png'), '#').'\?v=\d+">#', $html);
        $this->assertMatchesRegularExpression('#<meta name="description" content="Boards, sprints, docs[^"]{40,200}">#', $html);
        $this->assertStringContainsString('<title inertia>Brainitive – Workspaces, Boards &amp; Wiki</title>', $html);
        $this->assertFileExists(public_path('images/og-image.png'));
        $this->assertSame([1200, 630], array_slice(getimagesize(public_path('images/og-image.png')), 0, 2));
    }

    public function test_other_pages_get_only_the_site_defaults(): void
    {
        $this->setUpBoard();
        $this->board->update(['name' => 'Secret Launch Board']);

        $html = $this->actingAs($this->editor)
            ->get(route('boards.show', [$this->workspace->slug, $this->project->slug, $this->board->slug]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('<meta property="og:title" content="'.e(config('app.name')).'">', $html);
        $this->assertStringContainsString('<meta property="og:image" content="'.asset('images/og-image.png'), $html);
        $this->assertStringNotContainsString('og:url', $html);
        $this->assertDoesNotMatchRegularExpression('#<meta[^>]+content="[^"]*Secret Launch Board#', $html);
    }
}
