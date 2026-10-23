<?php

namespace Tests\Feature\Pages;

use App\Models\Board;
use App\Models\Item;
use App\Models\ShareLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShareLinkTest extends TestCase
{
    use InteractsWithPages;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPages();
        $this->withoutVite();
    }

    private function create(string $type, string $id, array $extra = []): string
    {
        return $this->actingAs($this->editor)->postJson(route('share-links.store'), ['type' => $type, 'id' => $id] + $extra)
            ->assertCreated()->json('links.0.url');
    }

    public function test_share_a_doc_read_only_and_revoke_it(): void
    {
        $page = $this->publishedPage(['name' => 'Onboarding guide', 'content' => ['type' => 'lexical', 'html' => '<p>Welcome aboard</p>']]);
        $url = $this->create('page', $page->uuid);

        $this->app['auth']->forgetGuards();
        $this->get($url)
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn ($p) => $p->component('Share/Show')
                ->where('shared.title', 'Onboarding guide')
                ->where('shared.html', '<p>Welcome aboard</p>')
                ->missing('shared.uuid')
                ->missing('shared.project'));
        $this->assertSame(1, ShareLink::first()->view_count);

        $link = ShareLink::first();
        $this->actingAs($this->editor)->deleteJson(route('share-links.destroy', $link))->assertOk()->assertJson(['links' => []]);

        $this->app['auth']->forgetGuards();
        $this->get($url)->assertStatus(410)->assertInertia(fn ($p) => $p->where('shared', null));
    }

    public function test_share_an_issue_without_comments_or_people(): void
    {
        $board = Board::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $item = Item::factory()->create(['board_id' => $board->id, 'created_by' => $this->editor->id, 'title' => 'Export fails', 'assigned_to' => $this->editor->id]);
        $item->comments()->create(['user_id' => $this->editor->id, 'body' => 'internal note']);

        $url = $this->create('item', (string) $item->id);
        $this->app['auth']->forgetGuards();
        $response = $this->get($url)->assertOk()
            ->assertInertia(fn ($p) => $p->where('shared.title', 'Export fails')->where('shared.key', $item->key)->missing('shared.assignee')->missing('shared.comments'));
        $this->assertStringNotContainsString('internal note', $response->getContent());
        $this->assertStringNotContainsString($this->editor->email, $response->getContent());
    }

    public function test_only_people_who_can_edit_can_share(): void
    {
        $page = $this->publishedPage();

        $this->actingAs($this->viewer)->postJson(route('share-links.store'), ['type' => 'page', 'id' => $page->uuid])->assertNotFound();
        $this->actingAs($this->outsider)->getJson(route('share-links.index', ['type' => 'page', 'id' => $page->uuid]))->assertNotFound();

        $this->create('page', $page->uuid);
        $this->actingAs($this->viewer)->deleteJson(route('share-links.destroy', ShareLink::first()))->assertForbidden();
        $this->assertNull(ShareLink::first()->revoked_at);
    }

    public function test_expired_links_and_deleted_items_are_gone(): void
    {
        $page = $this->publishedPage();
        $url = $this->create('page', $page->uuid, ['expires_in_days' => 1]);

        $this->travel(2)->days();
        $this->app['auth']->forgetGuards();
        $this->get($url)->assertStatus(410);
        $this->travelBack();

        $other = $this->publishedPage(['name' => 'Soon deleted']);
        $otherUrl = $this->create('page', $other->uuid);
        $other->delete();
        $this->app['auth']->forgetGuards();
        $this->get($otherUrl)->assertStatus(410);
        $this->get('/s/'.str_repeat('a', 40))->assertStatus(410);
    }
}
