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

    /** A poll as the editor saves it (PollNode::exportDOM). */
    private function poll(string $pollId, array $uids): string
    {
        $state = ['question' => 'Which?', 'options' => array_map(fn ($uid) => ['uid' => $uid, 'text' => $uid, 'votes' => []], $uids), 'pollId' => $pollId];

        return '<div data-lexical-poll="true" data-poll-state="'.e(json_encode($state)).'"></div>';
    }

    public function test_readers_vote_once_change_and_take_back_their_vote(): void
    {
        $page = $this->publishedPage(['content' => $this->html('<p>Vote</p>'.$this->poll('p-abc123', ['opt1', 'opt2']))]);
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
        $item = Item::factory()->create(['board_id' => $board->id, 'created_by' => $this->editor->id, 'description' => $this->poll('p-xyz', ['yes', 'no'])]);

        $this->actingAs($this->viewer)->postJson("/polls/item/{$item->id}/p-xyz", ['option_uid' => 'yes'])->assertOk()->assertJsonPath('my_vote', 'yes');
        $this->actingAs($this->outsider)->getJson("/polls/item/{$item->id}/p-xyz")->assertNotFound();
        $this->actingAs($this->viewer)->getJson('/polls/item/abc/p-xyz')->assertNotFound();
    }

    /** Codex web review E: only polls and options of the saved document take votes; orphaned votes can be pruned. */
    public function test_made_up_polls_and_options_are_refused(): void
    {
        $page = $this->publishedPage(['content' => $this->html($this->poll('p-real', ['a', 'b']))]);
        $board = Board::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $item = Item::factory()->create(['board_id' => $board->id, 'created_by' => $this->editor->id]);

        $this->actingAs($this->viewer)->postJson("/polls/item/{$item->id}/imaginary-poll", ['option_uid' => 'invented'])->assertUnprocessable();
        $this->actingAs($this->viewer)->postJson("/polls/page/{$page->uuid}/p-real", ['option_uid' => 'invented'])->assertUnprocessable();
        $this->actingAs($this->viewer)->postJson("/polls/page/{$page->uuid}/p-other", ['option_uid' => 'a'])->assertUnprocessable();
        $this->assertSame(0, PollVote::count());

        $this->actingAs($this->viewer)->postJson("/polls/page/{$page->uuid}/p-real", ['option_uid' => 'b'])->assertOk()->assertJsonPath('my_vote', 'b');

        // a poll only in the unpublished draft: its editors can try it, readers can't
        $page->update(['draft_content' => $this->html($this->poll('p-draft', ['x'])), 'draft_updated_at' => now()]);
        $this->actingAs($this->viewer)->postJson("/polls/page/{$page->uuid}/p-draft", ['option_uid' => 'x'])->assertUnprocessable();
        $this->actingAs($this->author)->postJson("/polls/page/{$page->uuid}/p-draft", ['option_uid' => 'x'])->assertOk();

        // polls saved before pollId existed use the editor's fallback id
        $legacy = '<div data-poll-state="'.e(json_encode(['question' => 'Q', 'options' => [['uid' => 'u1'], ['uid' => 'u2']]])).'"></div>';
        $item->update(['description' => $legacy]);
        $this->actingAs($this->viewer)->postJson("/polls/item/{$item->id}/o-u1-u2", ['option_uid' => 'u2'])->assertOk();
    }

    public function test_orphaned_votes_are_pruned_with_force_only(): void
    {
        $page = $this->publishedPage(['content' => $this->html($this->poll('p-real', ['a']))]);
        PollVote::create(['scope_type' => 'page', 'scope_id' => $page->uuid, 'poll_id' => 'p-real', 'option_uid' => 'a', 'user_id' => $this->viewer->id]);
        PollVote::create(['scope_type' => 'page', 'scope_id' => $page->uuid, 'poll_id' => 'imaginary', 'option_uid' => 'x', 'user_id' => $this->viewer->id]);
        PollVote::create(['scope_type' => 'item', 'scope_id' => '999999', 'poll_id' => 'p', 'option_uid' => 'x', 'user_id' => $this->viewer->id]);

        $this->artisan('polls:prune-orphans')->expectsOutputToContain('2 orphaned poll vote(s)')->assertSuccessful();
        $this->assertSame(3, PollVote::count());
        $this->artisan('polls:prune-orphans', ['--force' => true])->assertSuccessful();
        $this->assertSame(['p-real'], PollVote::pluck('poll_id')->all());
    }
}
