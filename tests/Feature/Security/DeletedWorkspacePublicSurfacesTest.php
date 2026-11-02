<?php

namespace Tests\Feature\Security;

use App\Models\Bookmark;
use App\Models\FeedbackPortal;
use App\Models\FeedbackVote;
use App\Models\Helpdesk;
use App\Models\Page;
use App\Models\Plan;
use App\Models\PortalIdentity;
use App\Models\Release;
use App\Models\ShareLink;
use App\Models\SnippetCollection;
use App\Models\Ticket;
use App\Support\Portal\PortalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Codex web review P: once a workspace is deleted (soft-deleted by an instance admin), none of its public surfaces
 * answer any more — feedback portal reads and writes, the helpdesk widget and support page, public tickets, share
 * links, release notes and changelogs, plan shares, public snippets and bookmarks. Restoring it brings them back.
 */
class DeletedWorkspacePublicSurfacesTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Notification::fake();
    }

    /** @return array<string, array{0: string, 1: string, 2?: array<string, mixed>}> method, url, body */
    private function surfaces(): array
    {
        $portal = FeedbackPortal::create(['project_id' => $this->project->id, 'enabled' => true, 'slug' => 'acme-ideas', 'title' => 'Acme ideas']);
        $post = $portal->posts()->create(['number' => $portal->nextNumber(), 'title' => 'Dark mode', 'body' => 'x', 'moderation' => 'approved', 'status' => 'open', 'approved_at' => now()]);
        $helpdesk = Helpdesk::create(['project_id' => $this->project->id, 'name' => 'Support', 'slug' => 'support', 'public_slug' => 'acme-support', 'created_by' => $this->editor->id]);
        $ticket = $this->postJson(route('helpdesks.tickets.store-public', $helpdesk->uuid), ['subject' => 'Q', 'description' => 'Help', 'contact_email' => 'c@example.com'])->assertOk()->json();
        $page = Page::factory()->published()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $share = ShareLink::create(['shareable_type' => Page::class, 'shareable_id' => $page->id, 'token' => Str::random(40), 'created_by' => $this->editor->id]);
        $release = Release::factory()->create(['project_id' => $this->project->id, 'published_at' => now(), 'public_token' => Str::random(32), 'published_notes' => '<p>Notes</p>']);
        $this->project->forceFill(['changelog_token' => Str::random(32)])->save();
        $plan = Plan::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id, 'share_token' => Str::random(40)]);
        $collection = SnippetCollection::create(['workspace_id' => $this->workspace->id, 'created_by' => $this->editor->id, 'name' => 'Public', 'visibility' => 'public']);
        $snippet = $collection->snippets()->create(['created_by' => $this->editor->id, 'title' => 'S', 'content' => 'x', 'language' => 'bash', 'visibility' => 'inherit']);
        $bookmark = Bookmark::create(['workspace_id' => $this->workspace->id, 'created_by' => $this->editor->id, 'name' => 'Links', 'visibility' => 'public']);

        return [
            'feedback portal' => ['GET', route('feedback.portal', 'acme-ideas')],
            'feedback roadmap' => ['GET', route('feedback.roadmap', 'acme-ideas')],
            'feedback post' => ['GET', route('feedback.post', ['acme-ideas', $post->number])],
            'feedback vote' => ['POST', route('feedback.vote', ['acme-ideas', $post->number])],
            'feedback submit' => ['POST', route('feedback.posts.store', 'acme-ideas'), ['title' => 'Another idea']],
            'widget form' => ['GET', route('helpdesks.widget.form', $helpdesk->uuid)],
            'widget ticket' => ['POST', route('helpdesks.tickets.store-public', $helpdesk->uuid), ['subject' => 'Q2', 'description' => 'Help', 'contact_email' => 'd@example.com']],
            'support page' => ['GET', route('helpdesks.support-page', 'acme-support')],
            'public ticket' => ['GET', route('helpdesks.tickets.show-public', $ticket['ticket_uuid']).'?token='.$ticket['access_token']],
            'share link' => ['GET', route('share.show', $share->token)],
            'release notes' => ['GET', route('releases.public', $release->public_token)],
            'changelog' => ['GET', route('changelog.public', $this->project->changelog_token)],
            'plan share' => ['GET', route('plans.public', $plan->share_token)],
            'snippet collection' => ['GET', route('snippet-collections.public.show', $collection->uuid)],
            'snippet' => ['GET', route('snippets.public.show', $snippet->uuid)],
            'bookmark' => ['GET', route('bookmarks.public.show', $bookmark->uuid)],
        ];
    }

    private function hit(array $surface, PortalIdentity $guest)
    {
        [$method, $url, $body] = $surface + [2 => []];
        $this->app['auth']->forgetGuards();

        return $this->withSession([PortalAuth::SESSION_KEY => $guest->id])->json($method, $url, $body);
    }

    public function test_public_surfaces_fail_closed_while_the_workspace_is_deleted(): void
    {
        $surfaces = $this->surfaces();
        $guest = PortalIdentity::create(['email' => 'guest@example.com', 'name' => 'Guest', 'email_verified_at' => now()]);
        $tickets = Ticket::count();

        $this->workspace->delete();
        foreach ($surfaces as $name => $surface) {
            $status = $this->hit($surface, $guest)->status();
            $this->assertContains($status, [404, 410], "{$name} answered {$status} for a deleted workspace");
        }
        $this->assertSame(0, FeedbackVote::count());
        $this->assertSame(1, FeedbackPortal::sole()->posts()->count());
        $this->assertSame($tickets, Ticket::count());

        $this->workspace->restore();
        foreach ($surfaces as $name => $surface) {
            $status = $this->hit($surface, $guest)->status();
            $this->assertLessThan(400, $status, "{$name} answered {$status} after the workspace was restored");
        }
    }
}
