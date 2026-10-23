<?php

namespace Tests\Feature\Pages;

use App\Models\Board;
use App\Models\Item;
use App\Models\PollVote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PollVoteTest extends TestCase
{
    use InteractsWithPages;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPages();
    }

    public function test_readers_vote_once_change_and_take_back_their_vote(): void
    {
        $page = $this->publishedPage();
        $url = "/polls/page/{$page->uuid}/p-abc123";

        $this->actingAs($this->viewer)->getJson($url)->assertOk()->assertJson(['total' => 0, 'my_vote' => null]);

        $this->actingAs($this->viewer)->postJson($url, ['option_uid' => 'opt1'])->assertOk()
            ->assertJson(['total' => 1, 'my_vote' => 'opt1', 'counts' => ['opt1' => 1]]);
        $this->actingAs($this->editor)->postJson($url, ['option_uid' => 'opt1'])->assertOk()->assertJsonPath('counts.opt1', 2);

        // Changing the vote moves it; it never counts twice.
        $this->actingAs($this->viewer)->postJson($url, ['option_uid' => 'opt2'])->assertOk()
            ->assertJson(['total' => 2, 'my_vote' => 'opt2', 'counts' => ['opt1' => 1, 'opt2' => 1]]);

        $this->actingAs($this->viewer)->postJson($url, ['option_uid' => null])->assertOk()->assertJson(['total' => 1, 'my_vote' => null]);
        $this->assertSame(1, PollVote::count());
    }

    public function test_votes_need_access_to_the_doc_or_issue(): void
    {
        $page = $this->publishedPage();
        $draft = $this->draftPage(['created_by' => $this->editor->id]);

        $this->actingAs($this->outsider)->postJson("/polls/page/{$page->uuid}/p-1", ['option_uid' => 'a'])->assertNotFound();
        // Someone else's unpublished draft is invisible to the viewer.
        $this->actingAs($this->viewer)->getJson("/polls/page/{$draft->uuid}/p-1")->assertNotFound();
        $this->actingAs($this->viewer)->postJson("/polls/page/{$page->uuid}/p-1", ['option_uid' => '<script>'])->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->getJson("/polls/page/{$page->uuid}/p-1")->assertUnauthorized();

        $this->assertSame(0, PollVote::count());
    }

    public function test_polls_in_issue_descriptions(): void
    {
        $board = Board::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $item = Item::factory()->create(['board_id' => $board->id, 'created_by' => $this->editor->id]);

        $this->actingAs($this->viewer)->postJson("/polls/item/{$item->id}/p-xyz", ['option_uid' => 'yes'])->assertOk()->assertJsonPath('my_vote', 'yes');
        $this->actingAs($this->outsider)->getJson("/polls/item/{$item->id}/p-xyz")->assertNotFound();
        $this->actingAs($this->viewer)->getJson('/polls/item/abc/p-xyz')->assertNotFound();
    }
}
