<?php

namespace Tests\Feature\Search;

use App\Models\Attachment;
use App\Models\Board;
use App\Models\Comment;
use App\Models\Item;
use App\Models\ItemField;
use App\Models\Label;
use App\Models\Page;
use App\Models\Project;
use App\Models\TicketAttachment;
use App\Models\TicketReply;
use App\Support\Search\SearchIndexer;
use App\Support\Search\SearchReindex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The index follows the database (BRAI-55): model hooks on every write path, the rebuild
 * command, and the automatic first build after an update.
 */
class SearchIndexTest extends SearchTestCase
{
    public function test_issues_are_indexed_on_create_update_delete_and_restore(): void
    {
        $item = $this->item(['title' => 'Ocelot', 'description' => '<p>Body <b>text</b></p>']);
        $entry = $this->entry('item', $item->id);
        $this->assertSame('Ocelot', $entry->title);
        $this->assertSame('Body text', $entry->body);
        $this->assertSame($item->key, $entry->key);
        $this->assertSame($this->project->id, (int) $entry->project_id);
        $this->assertSame($this->workspace->id, (int) $entry->workspace_id);
        $this->assertSame('open', $entry->status);

        $item->update(['title' => 'Jaguar', 'assigned_to' => $this->editor->id]);
        $entry = $this->entry('item', $item->id);
        $this->assertSame('Jaguar', $entry->title);
        $this->assertSame($this->editor->id, (int) $entry->assignee_id);

        $item->delete();
        $this->assertNull($this->entry('item', $item->id));
        $item->restore();
        $this->assertNotNull($this->entry('item', $item->id));
        $item->forceDelete();
        $this->assertNull($this->entry('item', $item->id));
    }

    public function test_labels_and_custom_field_text_are_keywords(): void
    {
        $item = $this->item(['title' => 'Plain']);
        $label = Label::create(['project_id' => $this->project->id, 'name' => 'Frontend', 'slug' => 'frontend', 'color' => '#000']);

        // the board's label endpoint: sync + history entry
        $this->actingAs($this->editor)
            ->patch(route('boards.items.labels', [$this->workspace->slug, $this->project->slug, $this->board->slug, $item->id]), ['labels' => [$label->id]])
            ->assertRedirect();
        $this->assertStringContainsString('Frontend', $this->entry('item', $item->id)->keywords);

        $label->update(['name' => 'Backend']);
        $this->assertStringContainsString('Backend', $this->entry('item', $item->id)->keywords);

        $field = ItemField::create(['project_id' => $this->project->id, 'name' => 'Customer', 'type' => 'text', 'position' => 0]);
        $item->fieldValues()->create(['item_field_id' => $field->id, 'value_text' => 'Acme Corp']);
        $this->assertStringContainsString('Acme Corp', $this->entry('item', $item->id)->keywords);
    }

    public function test_moving_an_issue_to_another_project_moves_its_comments_files_and_subtasks(): void
    {
        $item = $this->item(['title' => 'Mover']);
        $child = $this->item(['title' => 'Mover child', 'parent_id' => $item->id]);
        $comment = Comment::create(['item_id' => $item->id, 'user_id' => $this->editor->id, 'body' => 'hello']);
        $file = Attachment::create(['item_id' => $item->id, 'user_id' => $this->editor->id, 'filename' => 'a.png', 'original_filename' => 'mover.png', 'path' => 'x/a.png', 'mime_type' => 'image/png', 'size' => 1]);
        $this->flush();

        $other = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->owner->id]);
        $other->users()->attach($this->editor->id, ['role' => 'admin']);
        $target = Board::factory()->create(['project_id' => $other->id, 'created_by' => $this->owner->id]);

        $this->actingAs($this->editor)
            ->patch(route('boards.items.move-to-board', [$this->workspace->slug, $this->project->slug, $this->board->slug, $item->id]), ['target_board_id' => $target->id])
            ->assertRedirect();

        foreach ([['item', $item->id], ['item', $child->id], ['comment', $comment->id], ['attachment', $file->id]] as [$type, $id]) {
            $entry = $this->entry($type, $id);
            $this->assertSame($other->id, (int) $entry->project_id, "{$type} {$id}");
        }
        // the viewer of the old project no longer finds any of it
        $this->assertSame(0, $this->search($this->viewer, 'mover')['total']);
    }

    public function test_comments_docs_tickets_and_files_follow_their_records(): void
    {
        $item = $this->item(['title' => 'Host']);
        $comment = Comment::create(['item_id' => $item->id, 'user_id' => $this->editor->id, 'body' => '<p>first</p>']);
        $this->assertSame('first', $this->entry('comment', $comment->id)->body);
        $comment->update(['body' => 'second']);
        $this->assertSame('second', $this->entry('comment', $comment->id)->body);
        $comment->delete();
        $this->assertNull($this->entry('comment', $comment->id));

        $folder = Page::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id, 'type' => 'folder', 'name' => 'Folder', 'content' => null]);
        $doc = Page::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id, 'parent_id' => $folder->id, 'name' => 'Child doc', 'status' => 'published']);
        $this->assertNull($this->entry('page', $folder->id), 'folders are not content');
        $this->assertNotNull($this->entry('page', $doc->id));
        $folder->delete(); // the database deletes the child with it
        $this->assertNull($this->entry('page', $doc->id));

        $ticket = $this->ticket(['subject' => 'Help']);
        TicketReply::create(['ticket_id' => $ticket->id, 'body' => 'Customer reply about invoices', 'is_internal_note' => false]);
        $this->assertStringContainsString('invoices', $this->entry('ticket', $ticket->id)->body);
        $file = TicketAttachment::create(['ticket_id' => $ticket->id, 'filename' => 'f.pdf', 'original_filename' => 'contract.pdf', 'path' => 'x/f.pdf', 'mime_type' => 'application/pdf', 'size' => 1]);
        $this->assertSame('contract.pdf', $this->entry('ticket_attachment', $file->id)->title);
        $ticket->delete();
        $this->assertNull($this->entry('ticket', $ticket->id));
        $this->assertNull($this->entry('ticket_attachment', $file->id));
        $ticket->restore();
        $this->assertNotNull($this->entry('ticket_attachment', $file->id));
    }

    public function test_the_done_column_decides_the_status(): void
    {
        $item = $this->item(['title' => 'Status']);
        $column = $item->column;
        $column->update(['is_done_column' => true]);
        $this->assertSame('done', $this->entry('item', $item->id)->status);
    }

    public function test_reindex_rebuilds_everything_and_drops_orphans(): void
    {
        $item = $this->item(['title' => 'Rebuild me']);
        $doc = Page::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id, 'name' => 'Rebuild doc']);
        $this->flush();
        DB::table('search_entries')->delete();
        // an orphan: its record is gone without any hook
        DB::table('search_entries')->insert(['type' => 'item', 'source_id' => 999999, 'project_id' => $this->project->id, 'title' => 'Ghost', 'is_draft' => false]);

        $this->artisan('search:reindex')->assertSuccessful();

        $this->assertNotNull(DB::table('search_entries')->where('type', 'item')->where('source_id', $item->id)->first());
        $this->assertNotNull(DB::table('search_entries')->where('type', 'page')->where('source_id', $doc->id)->first());
        $this->assertNull(DB::table('search_entries')->where('source_id', 999999)->first());
        $this->assertFalse(SearchReindex::needsBuild());

        // one type, queued (the test queue runs it at once)
        DB::table('search_entries')->where('type', 'page')->delete();
        $this->artisan('search:reindex', ['--type' => ['page'], '--queue' => true, '--chunk' => 1])->assertSuccessful();
        $this->assertSame(1, DB::table('search_entries')->where('type', 'page')->count());

        $this->artisan('search:reindex', ['--type' => ['nope']])->assertFailed();
    }

    public function test_the_first_build_after_an_update_runs_by_itself_once(): void
    {
        $item = $this->item(['title' => 'Before the update']);
        $this->flush();
        DB::table('search_entries')->delete(); // as after the migration: nothing indexed yet
        $this->assertTrue(SearchReindex::needsBuild());

        $this->artisan('search:ensure')->assertSuccessful();
        $this->assertNotNull(DB::table('search_entries')->where('type', 'item')->where('source_id', $item->id)->first());
        $this->assertFalse(SearchReindex::needsBuild());

        // nothing to do the next time
        DB::table('search_entries')->delete();
        $this->artisan('search:ensure')->assertSuccessful();
        $this->assertSame(0, DB::table('search_entries')->count());

        // a newer index version (a later release) builds again
        DB::table('search_index_state')->update(['version' => SearchReindex::VERSION - 1]);
        $this->artisan('search:ensure')->assertSuccessful();
        $this->assertSame(1, DB::table('search_entries')->count());

        // a build that died hours ago is retried; a running one is left alone
        DB::table('search_index_state')->update(['status' => 'building', 'started_at' => now()->subMinutes(5)]);
        $this->assertFalse(SearchReindex::needsBuild());
        DB::table('search_index_state')->update(['started_at' => now()->subHours(7)]);
        $this->assertTrue(SearchReindex::needsBuild());
    }

    public function test_a_failing_index_write_never_breaks_the_request(): void
    {
        $indexer = app(SearchIndexer::class);
        $indexer->touch('unknown-type', 1);
        $indexer->flush(); // reports, doesn't throw
        $this->assertFalse($indexer->hasPending());

        $this->actingAs($this->editor)->get(route('search', ['q' => 'anything']))->assertOk();
    }

    public function test_items_created_without_hooks_are_found_after_a_reindex(): void
    {
        Item::withoutEvents(fn () => $this->item(['title' => 'Quiet insert', 'uuid' => (string) Str::uuid()]));
        $this->assertSame(0, $this->search($this->editor, 'quiet')['total']);
        $this->artisan('search:reindex', ['--type' => ['item']])->assertSuccessful();
        $this->assertSame(1, $this->search($this->editor, 'quiet')['total']);
    }
}
