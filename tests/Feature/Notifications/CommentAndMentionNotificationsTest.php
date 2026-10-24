<?php

namespace Tests\Feature\Notifications;

use App\Models\Page;
use App\Notifications\ItemCommentedNotification;
use App\Notifications\MentionNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * BRAI-103: comments reach everyone involved in an issue; @mentions are only notified when they are
 * new (re-saving a description or page never re-notifies), and nobody hears about the same comment twice.
 */
class CommentAndMentionNotificationsTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Notification::fake();
    }

    private function mention(int $userId): string
    {
        return '<span data-mention-user-id="'.$userId.'">@someone</span>';
    }

    public function test_a_comment_reaches_watchers_the_assignee_and_the_reporter_but_never_the_author(): void
    {
        $reporter = $this->projectMember('editor');
        $assignee = $this->projectMember('editor');
        $watcher = $this->projectMember('viewer');
        $item = $this->item(['reporter_id' => $reporter->id, 'assigned_to' => $assignee->id, 'key' => 'BRAI-12']);
        $item->addWatcher($watcher);
        $item->addWatcher($this->editor);
        $item->addWatcher($this->outsider); // left the project meanwhile: not told

        $this->actingAs($this->editor)
            ->post($this->itemRoute('boards.items.comments.store', $item), ['body' => '<p>Looks <b>good</b> to me</p>'])
            ->assertSessionHasNoErrors();

        foreach ([$reporter, $assignee, $watcher] as $user) {
            Notification::assertSentToTimes($user, ItemCommentedNotification::class, 1);
        }
        Notification::assertNotSentTo($this->editor, ItemCommentedNotification::class);
        Notification::assertNotSentTo($this->outsider, ItemCommentedNotification::class);

        Notification::assertSentTo($watcher, ItemCommentedNotification::class, function (ItemCommentedNotification $n) use ($watcher) {
            $d = $n->toDatabase($watcher);

            return $d['type'] === 'item_commented'
                && $d['title'] === "{$this->editor->name} commented on BRAI-12"
                && $d['body'] === 'Looks good to me'
                && $d['subject_key'] === 'BRAI-12'
                && str_contains($d['url'], "/items/{$n->item->id}")
                && $d['project_name'] === "{$this->project->name} · {$this->board->name}";
        });
    }

    public function test_a_mentioned_watcher_gets_the_mention_only(): void
    {
        $watcher = $this->projectMember('editor');
        $item = $this->item(['reporter_id' => $this->editor->id]);
        $item->addWatcher($watcher);

        $this->actingAs($this->editor)
            ->post($this->itemRoute('boards.items.comments.store', $item), ['body' => '<p>'.$this->mention($watcher->id).' please check</p>'])
            ->assertSessionHasNoErrors();

        Notification::assertSentToTimes($watcher, MentionNotification::class, 1);
        Notification::assertNotSentTo($watcher, ItemCommentedNotification::class);
    }

    public function test_resaving_a_description_only_notifies_newly_mentioned_people(): void
    {
        $first = $this->projectMember('editor');
        $second = $this->projectMember('viewer');
        $item = $this->item();
        $update = fn (string $html) => $this->actingAs($this->editor)
            ->patch($this->itemRoute('boards.items.update', $item), ['description' => $html])
            ->assertSessionHasNoErrors();

        $update('<p>'.$this->mention($first->id).' owns this</p>');
        $update('<p>'.$this->mention($first->id).' owns this, really</p>');
        $update('<p>'.$this->mention($first->id).' and '.$this->mention($second->id).'</p>');

        Notification::assertSentToTimes($first, MentionNotification::class, 1);
        Notification::assertSentToTimes($second, MentionNotification::class, 1);
    }

    public function test_page_mentions_are_notified_on_save_once(): void
    {
        $mentioned = $this->projectMember('viewer');
        $page = Page::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $save = fn (string $html) => $this->actingAs($this->editor)
            ->putJson(route('pages.draft.save', [$this->workspace->slug, $this->project->slug, $page->uuid]), ['content' => ['type' => 'lexical', 'html' => $html]])
            ->assertOk();

        $save('<p>Hello '.$this->mention($mentioned->id).'</p>');
        $save('<p>Hello '.$this->mention($mentioned->id).', see below</p>');
        $save('<p>Hello '.$this->mention($this->editor->id).'</p>'); // yourself: never

        Notification::assertSentToTimes($mentioned, MentionNotification::class, 1);
        Notification::assertSentTo($mentioned, MentionNotification::class, fn (MentionNotification $n) => $n->contextType === 'page' && $n->actor->is($this->editor));
        Notification::assertNotSentTo($this->editor, MentionNotification::class);
        Notification::assertNotSentTo($this->outsider, MentionNotification::class);
    }

    public function test_page_mentions_in_lexical_json_are_found_too(): void
    {
        $mentioned = $this->projectMember('viewer');
        $page = Page::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);

        $page->saveDraft($this->editor, ['content' => ['root' => ['children' => [['type' => 'mention', 'userId' => $mentioned->id, 'displayName' => 'X']]]]]);

        Notification::assertSentToTimes($mentioned, MentionNotification::class, 1);
    }
}
