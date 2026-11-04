<?php

namespace Tests\Feature\AppApi;

use App\Models\Board;
use App\Models\Item;
use App\Models\PollVote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Pages\InteractsWithPages;
use Tests\TestCase;

/** App API v1 poll votes: the web's /polls/… (Support\Polls\PollVotes), wrapped in {data}. */
class PollsTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use InteractsWithPages;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPages();
    }

    private function poll(string $pollId, array $uids): string
    {
        $state = ['question' => 'Which?', 'options' => array_map(fn ($uid) => ['uid' => $uid, 'text' => $uid, 'votes' => []], $uids), 'pollId' => $pollId];

        return '<div data-lexical-poll="true" data-poll-state="'.e(json_encode($state)).'"></div>';
    }

    public function test_readers_see_results_vote_change_and_take_back_their_vote(): void
    {
        $page = $this->publishedPage(['content' => $this->html('<p>Vote</p>'.$this->poll('p-abc123', ['opt1', 'opt2']))]);
        $url = "/api/v1/polls/page/{$page->uuid}/p-abc123";
        $viewer = $this->appHeaders($this->appToken($this->viewer));

        $this->getJson($url, $viewer)->assertOk()->assertJson(['data' => ['total' => 0, 'my_vote' => null]]);
        $this->postJson($url, ['option_uid' => 'opt1'], $viewer)->assertOk()
            ->assertJson(['data' => ['total' => 1, 'my_vote' => 'opt1', 'counts' => ['opt1' => 1]]]);
        $this->postJson($url, ['option_uid' => 'opt2'], $viewer)->assertOk()
            ->assertJson(['data' => ['total' => 1, 'my_vote' => 'opt2', 'counts' => ['opt2' => 1]]]);
        $this->postJson($url, ['option_uid' => null], $viewer)->assertOk()->assertJson(['data' => ['total' => 0, 'my_vote' => null]]);
        $this->assertSame(0, PollVote::count());
    }

    public function test_votes_need_access_and_a_real_poll_option(): void
    {
        $page = $this->publishedPage(['content' => $this->html($this->poll('p-real', ['a', 'b']))]);
        $draft = $this->draftPage(['created_by' => $this->editor->id]);

        $this->postJson("/api/v1/polls/page/{$page->uuid}/p-real", ['option_uid' => 'a'], $this->appHeaders($this->appToken($this->outsider)))->assertNotFound();
        $viewer = $this->appHeaders($this->appToken($this->viewer));
        $this->getJson("/api/v1/polls/page/{$draft->uuid}/p-1", $viewer)->assertNotFound();
        $this->postJson("/api/v1/polls/page/{$page->uuid}/p-real", ['option_uid' => 'zzz'], $viewer)->assertUnprocessable();
        $this->postJson("/api/v1/polls/page/{$page->uuid}/p-made-up", ['option_uid' => 'a'], $viewer)->assertUnprocessable();
        $this->postJson("/api/v1/polls/page/{$page->uuid}/p-real", ['option_uid' => '<script>'], $viewer)->assertUnprocessable();
        $this->getJson("/api/v1/polls/page/{$page->uuid}/p-real")->assertUnauthorized();
        $this->assertSame(0, PollVote::count());
    }

    public function test_polls_in_issue_descriptions(): void
    {
        $board = Board::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $item = Item::factory()->create(['board_id' => $board->id, 'created_by' => $this->editor->id, 'description' => $this->poll('p-xyz', ['yes', 'no'])]);
        $viewer = $this->appHeaders($this->appToken($this->viewer));

        $this->postJson("/api/v1/polls/item/{$item->id}/p-xyz", ['option_uid' => 'yes'], $viewer)->assertOk()->assertJsonPath('data.my_vote', 'yes');
        $this->getJson("/api/v1/polls/item/{$item->id}/p-xyz", $this->appHeaders($this->appToken($this->outsider)))->assertNotFound();
        $this->getJson('/api/v1/polls/item/abc/p-xyz', $viewer)->assertNotFound();

        // the record's own id, however the client spells it: one tally
        $this->postJson("/api/v1/polls/item/00{$item->id}/p-xyz", ['option_uid' => 'no'], $viewer)->assertOk()
            ->assertJson(['data' => ['total' => 1, 'my_vote' => 'no']]);
        $this->assertSame([(string) $item->id], PollVote::pluck('scope_id')->all());
    }

    public function test_a_malformed_doc_id_is_a_404_not_a_server_error(): void
    {
        $viewer = $this->appHeaders($this->appToken($this->viewer));
        $this->getJson('/api/v1/polls/page/not-a-uuid/p1', $viewer)->assertNotFound();
        $this->postJson('/api/v1/polls/page/not-a-uuid/p1', ['option_uid' => 'a'], $viewer)->assertNotFound();

        $page = $this->publishedPage(['content' => $this->html($this->poll('p-real', ['a', 'b']))]);
        $this->postJson('/api/v1/polls/page/'.strtoupper($page->uuid).'/p-real', ['option_uid' => 'a'], $viewer)->assertOk();
        $this->assertSame([(string) $page->uuid], PollVote::pluck('scope_id')->all());
    }
}
