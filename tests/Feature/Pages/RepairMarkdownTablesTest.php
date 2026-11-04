<?php

namespace Tests\Feature\Pages;

use App\Models\ActivityLog;
use App\Models\Page;
use App\Models\PageCollabState;
use App\Notifications\PageUpdatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * pages:repair-markdown-tables (v1.51.0): tables the old Markdown converter stored as `| a | b |` text become
 * real tables — dry run by default, --force writes, --items covers issue descriptions, idempotent.
 */
class RepairMarkdownTablesTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    /** What the MCP stored for "Intro\n| a | b |\n|---|---|\n| 1 | 2 |" before tables were supported. */
    private const BROKEN = '<h2>Prices</h2><p>Intro | a | b | |---|---| | 1 | <strong>2</strong> |</p><p>Other | text</p>';

    private const FIXED = '<h2>Prices</h2><p>Intro</p><table><tbody><tr><th><p>a</p></th><th><p>b</p></th></tr><tr><td><p>1</p></td><td><p><strong>2</strong></p></td></tr></tbody></table><p>Other | text</p>';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
    }

    private function doc(string $html, array $attributes = []): Page
    {
        return Page::factory()->create($attributes + [
            'project_id' => $this->project->id,
            'created_by' => $this->editor->id,
            'name' => 'Price list',
            'content' => ['type' => 'lexical', 'html' => $html],
        ]);
    }

    public function test_a_dry_run_lists_the_docs_and_changes_nothing(): void
    {
        $broken = $this->doc(self::BROKEN);
        $fine = $this->doc('<p>No tables | here</p>', ['name' => 'Fine']);

        $this->artisan('pages:repair-markdown-tables')
            ->expectsOutputToContain("page {$broken->id} {$broken->uuid} \"Price list\": 1 table(s)")
            ->doesntExpectOutputToContain('Fine')
            ->expectsOutputToContain('1 doc(s) with 1 Markdown table(s) stored as text. Dry run: nothing changed.')
            ->assertSuccessful();

        $this->assertSame(self::BROKEN, $broken->fresh()->content['html']);
        $this->assertSame('<p>No tables | here</p>', $fine->fresh()->content['html']);
    }

    public function test_force_repairs_content_and_draft_without_a_version_or_notifications_and_is_idempotent(): void
    {
        Notification::fake();
        $page = $this->doc(self::BROKEN, ['status' => 'published', 'published_at' => now()]);
        $page->watchers()->syncWithoutDetaching([$this->viewer->id]);
        // an unpublished draft holding the same broken table (plus a change of its own)
        $page->saveDraft($this->editor, ['content' => ['type' => 'lexical', 'html' => self::BROKEN.'<p>Draft only</p>']]);
        $page->refresh();
        $revision = $page->edit_revision;
        $updatedAt = $page->updated_at;
        $versions = $page->versions()->count();
        PageCollabState::create(['page_id' => $page->id, 'epoch' => 2]);
        $this->travel(5)->minutes();

        $this->artisan('pages:repair-markdown-tables --force')
            ->expectsOutputToContain('Repaired 1 doc(s) with 2 Markdown table(s) stored as text.')
            ->assertSuccessful();

        $page->refresh();
        $this->assertSame(self::FIXED, $page->content['html']);
        $this->assertSame(self::FIXED.'<p>Draft only</p>', $page->draft_content['html']);
        $this->assertTrue($page->hasUnpublishedChanges(), 'the draft stays a draft');
        $this->assertSame($revision + 1, $page->edit_revision);
        $this->assertSame($updatedAt->toIso8601String(), $page->updated_at->toIso8601String());
        $this->assertSame($versions, $page->versions()->count());
        $this->assertSame(3, PageCollabState::where('page_id', $page->id)->value('epoch'), 'open editors reload');
        Notification::assertNotSentTo($this->viewer, PageUpdatedNotification::class);

        $this->artisan('pages:repair-markdown-tables --force')
            ->expectsOutputToContain('Repaired 0 doc(s) with 0 Markdown table(s) stored as text.')
            ->assertSuccessful();
        $this->assertSame($revision + 1, $page->fresh()->edit_revision);
    }

    public function test_what_cannot_be_rebuilt_safely_is_reported_and_left_alone(): void
    {
        $ragged = '<p>| a | b | |---|---| | 1 | | 3 | 4 | 5 |</p>';
        $page = $this->doc($ragged);

        $this->artisan('pages:repair-markdown-tables --force')
            ->expectsOutputToContain("skipped page {$page->id}")
            ->expectsOutputToContain('1 table-like text(s) left alone (fix by hand)')
            ->assertSuccessful();

        $this->assertSame($ragged, $page->fresh()->content['html']);
    }

    public function test_issue_descriptions_only_with_the_items_option_and_without_history(): void
    {
        $item = $this->item(['description' => self::BROKEN]);
        $this->doc('<p>fine</p>');
        $history = ActivityLog::count();

        $this->artisan('pages:repair-markdown-tables --force')->assertSuccessful();
        $this->assertSame(self::BROKEN, $item->fresh()->description);

        $this->artisan('pages:repair-markdown-tables --items')
            ->expectsOutputToContain("issue {$item->id} {$item->key}")
            ->expectsOutputToContain('0 doc(s) and 1 issue(s) with 1 Markdown table(s) stored as text. Dry run')
            ->assertSuccessful();
        $this->assertSame(self::BROKEN, $item->fresh()->description);

        $this->artisan('pages:repair-markdown-tables --items --force')->assertSuccessful();
        $this->assertSame(self::FIXED, $item->fresh()->description);
        $this->assertSame($history, ActivityLog::count());
    }
}
