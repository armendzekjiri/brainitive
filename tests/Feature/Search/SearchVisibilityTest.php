<?php

namespace Tests\Feature\Search;

use App\Models\Attachment;
use App\Models\Board;
use App\Models\Bookmark;
use App\Models\BookmarkItem;
use App\Models\Capture;
use App\Models\Comment;
use App\Models\Item;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Snippet;
use App\Models\SnippetCollection;
use App\Models\TaskList;
use App\Models\TaskListItem;
use App\Models\TicketReply;
use App\Models\User;
use App\Models\Workspace;

/**
 * Who finds what (BRAI-55): the search index applies the view policies inside its query, so a
 * hidden record is never a result, never counted.
 */
class SearchVisibilityTest extends SearchTestCase
{
    public function test_project_content_is_found_by_project_members_and_workspace_admins_only(): void
    {
        $item = $this->item(['title' => 'Zebracorn launch']);
        Comment::create(['item_id' => $item->id, 'user_id' => $this->editor->id, 'body' => '<p>zebracorn comment</p>']);
        Attachment::create(['item_id' => $item->id, 'user_id' => $this->editor->id, 'filename' => 'a.pdf', 'original_filename' => 'zebracorn-spec.pdf', 'path' => 'x/a.pdf', 'mime_type' => 'application/pdf', 'size' => 1]);
        Plan::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id, 'name' => 'Zebracorn plan']);
        $list = TaskList::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id]);
        TaskListItem::factory()->create(['task_list_id' => $list->id, 'created_by' => $this->owner->id, 'title' => 'Zebracorn task']);
        $ticket = $this->ticket(['subject' => 'Zebracorn ticket']);
        Page::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id, 'name' => 'Zebracorn doc', 'status' => 'published']);

        $expected = ['issues' => 1, 'docs' => 1, 'comments' => 1, 'tickets' => 1, 'tasks' => 1, 'plans' => 1, 'files' => 1];
        foreach ([$this->owner, $this->editor, $this->viewer] as $user) {
            $found = $this->search($user, 'zebracorn');
            $this->assertSame(7, $found['total']);
            $this->assertSame($expected, array_filter($found['counts']));
        }

        // a workspace member outside the project, and an outsider, find nothing — not even a count
        $member = User::factory()->create();
        $this->workspace->addUser($member, 'member');
        foreach ([$member, $this->outsider] as $user) {
            $found = $this->search($user, 'zebracorn');
            $this->assertSame(0, $found['total']);
            $this->assertSame([], array_filter($found['counts']));
        }

        // leaving the project takes it away at once
        $this->project->users()->detach($this->viewer->id);
        $this->assertSame(0, $this->search($this->viewer, 'zebracorn')['total']);
        $this->assertNotNull($ticket);
    }

    public function test_never_published_docs_are_found_by_their_author_and_project_admins_only(): void
    {
        $draft = Page::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id, 'name' => 'Secret reorg draft', 'status' => 'draft']);
        $adminDraft = Page::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id, 'name' => 'Admin reorg draft', 'status' => 'draft']);

        $this->assertSame(["page:{$draft->id}"], $this->ids($this->editor, 'reorg'));
        $this->assertEqualsCanonicalizing(["page:{$draft->id}", "page:{$adminDraft->id}"], $this->ids($this->owner, 'reorg'));
        $this->assertSame(0, $this->search($this->viewer, 'reorg')['total']);

        // a project admin (not a workspace admin) sees drafts too
        $projectAdmin = $this->member('admin');
        $this->assertCount(2, $this->ids($projectAdmin, 'reorg'));

        // publishing makes it findable for everyone in the project
        $adminDraft->update(['status' => 'published', 'published_at' => now()]);
        $this->assertSame(["page:{$adminDraft->id}"], $this->ids($this->viewer, 'reorg'));
    }

    public function test_unpublished_changes_of_a_published_doc_are_not_searchable(): void
    {
        $page = Page::factory()->create([
            'project_id' => $this->project->id, 'created_by' => $this->owner->id, 'name' => 'Handbook', 'status' => 'published',
            'content' => ['type' => 'lexical', 'html' => '<p>Vacation policy</p>'],
        ]);
        $page->update(['draft_content' => ['type' => 'lexical', 'html' => '<p>Layoffs planned</p>'], 'draft_name' => 'Layoffs', 'draft_updated_at' => now()]);

        $this->assertSame(["page:{$page->id}"], $this->ids($this->viewer, 'vacation'));
        $this->assertSame([], $this->ids($this->viewer, 'layoffs'));
        $this->assertSame([], $this->ids($this->owner, 'layoffs'));
    }

    public function test_captures_are_private_to_their_owner(): void
    {
        $capture = Capture::factory()->create(['user_id' => $this->editor->id, 'text' => "Moonshot idea\nfor later"]);

        $this->assertSame(["capture:{$capture->id}"], $this->ids($this->editor, 'moonshot'));
        foreach ([$this->owner, $this->viewer, $this->outsider] as $user) {
            $this->assertSame(0, $this->search($user, 'moonshot')['total']);
        }
    }

    public function test_bookmarks_follow_their_collection_and_snippets_the_workspace(): void
    {
        $private = Bookmark::create(['workspace_id' => $this->workspace->id, 'name' => 'Mine', 'visibility' => 'private', 'created_by' => $this->editor->id]);
        $shared = Bookmark::create(['workspace_id' => $this->workspace->id, 'name' => 'Team', 'visibility' => 'private', 'created_by' => $this->editor->id]);
        $shared->collaborators()->attach($this->viewer->id, ['role' => 'viewer']);
        $public = Bookmark::create(['workspace_id' => $this->workspace->id, 'name' => 'All', 'visibility' => 'public', 'created_by' => $this->editor->id]);
        $a = BookmarkItem::create(['bookmark_id' => $private->id, 'type' => 'link', 'title' => 'Quokka private', 'url' => 'https://example.com/a', 'position' => 0]);
        $b = BookmarkItem::create(['bookmark_id' => $shared->id, 'type' => 'link', 'title' => 'Quokka shared', 'url' => 'https://example.com/b', 'position' => 0]);
        $c = BookmarkItem::create(['bookmark_id' => $public->id, 'type' => 'link', 'title' => 'Quokka public', 'url' => 'https://example.com/c', 'position' => 0]);

        $this->assertEqualsCanonicalizing(["bookmark:{$a->id}", "bookmark:{$b->id}", "bookmark:{$c->id}"], $this->ids($this->editor, 'quokka'));
        $this->assertEqualsCanonicalizing(["bookmark:{$b->id}", "bookmark:{$c->id}"], $this->ids($this->viewer, 'quokka'));
        // workspace admins see every collection (BookmarkPolicy::view)
        $this->assertCount(3, $this->ids($this->owner, 'quokka'));
        // a public collection of another workspace is not "search everything" material
        $this->assertSame(0, $this->search($this->outsider, 'quokka')['total']);

        $collection = SnippetCollection::create(['workspace_id' => $this->workspace->id, 'created_by' => $this->owner->id, 'name' => 'Ops']);
        $snippet = Snippet::create(['collection_id' => $collection->id, 'created_by' => $this->owner->id, 'title' => 'Restart wombat', 'language' => 'bash', 'content' => 'systemctl restart wombat']);
        $member = User::factory()->create();
        $this->workspace->addUser($member, 'member'); // in no project at all
        $this->assertSame(["snippet:{$snippet->id}"], $this->ids($member, 'wombat'));
        $this->assertSame(0, $this->search($this->outsider, 'wombat')['total']);
    }

    public function test_trashed_content_and_content_of_trashed_boards_projects_or_lists_is_hidden(): void
    {
        $item = $this->item(['title' => 'Narwhal issue']);
        $other = Board::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id]);
        $onOther = $this->item(['board_id' => $other->id, 'title' => 'Narwhal on other board']);
        $list = TaskList::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id]);
        $task = TaskListItem::factory()->create(['task_list_id' => $list->id, 'created_by' => $this->owner->id, 'title' => 'Narwhal task']);
        $this->assertCount(3, $this->ids($this->editor, 'narwhal'));

        $item->delete();
        $other->delete();
        $list->delete();
        $this->assertSame([], $this->ids($this->editor, 'narwhal'));

        $item->restore();
        $other->restore();
        $list->restore();
        $this->assertEqualsCanonicalizing(["item:{$item->id}", "item:{$onOther->id}", "task:{$task->id}"], $this->ids($this->editor, 'narwhal'));

        // a trashed project hides everything in it, a restored one brings it back
        $this->project->delete();
        $this->assertSame([], $this->ids($this->owner, 'narwhal'));
        $this->project->restore();
        $this->assertCount(3, $this->ids($this->owner, 'narwhal'));
    }

    public function test_tickets_with_their_replies_are_found_by_agents_only(): void
    {
        $ticket = $this->ticket(['subject' => 'Printer broken']);
        TicketReply::create(['ticket_id' => $ticket->id, 'user_id' => $this->owner->id, 'body' => '<p>Ordered a new toner cartridge</p>', 'is_internal_note' => true]);

        $this->assertSame(["ticket:{$ticket->id}"], $this->ids($this->viewer, 'toner'));
        $this->assertSame(0, $this->search($this->outsider, 'toner')['total']);
    }

    public function test_content_of_another_workspace_is_never_found(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addUser($owner, 'admin');
        $project = Project::factory()->create(['workspace_id' => $workspace->id, 'created_by' => $owner->id]);
        $board = Board::factory()->create(['project_id' => $project->id, 'created_by' => $owner->id]);
        Item::factory()->create(['board_id' => $board->id, 'created_by' => $owner->id, 'title' => 'Acquisition platypus']);

        $this->assertSame(1, $this->search($owner, 'platypus')['total']);
        foreach ([$this->owner, $this->editor] as $user) {
            $this->assertSame(0, $this->search($user, 'platypus')['total']);
        }
    }
}
