<?php

namespace Tests\Feature\ClientPortal;

use App\Models\ClientDeliverable;
use App\Models\ClientDeliverableComment;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Project;
use App\Models\User;
use App\Notifications\ClientCommentedNotification;
use App\Notifications\ClientDecisionNotification;
use App\Notifications\ClientPortalGuestNotification;
use App\Support\ClientPortal\DeliverableHtml;
use App\Support\ClientPortal\Deliverables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Client deliverables (v1.42.0, BRAI-73): frozen content, no leaks, version-specific decisions,
 * supersede on a new version, comments and notifications.
 */
class ClientDeliverablesTest extends TestCase
{
    use InteractsWithClientPortal, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->setUpClientPortal();
    }

    private function publishRoute(): string
    {
        return route('client-portal.manage.deliverables.store', [$this->workspace->slug, $this->project->slug]);
    }

    public function test_an_editor_publishes_the_latest_page_version_and_clients_are_mailed(): void
    {
        $client = $this->client();
        $page = $this->publishedPage(['name' => 'Proposal', 'content' => $this->html('<p>The live proposal</p>')]);

        $this->actingAs($this->viewer)->post($this->publishRoute(), ['kind' => 'page', 'id' => $page->uuid])->assertForbidden();
        $this->actingAs($this->editor)->post($this->publishRoute(), ['kind' => 'page', 'id' => $page->uuid, 'message' => 'Please review'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $deliverable = ClientDeliverable::firstOrFail();
        $this->assertSame('page', $deliverable->kind);
        $this->assertSame(1, $deliverable->version);
        $this->assertSame($page->versions()->first()->id, $deliverable->page_version_id);
        $this->assertSame('Proposal', $deliverable->title);
        $this->assertSame(ClientDeliverable::STATUS_AWAITING, $deliverable->status);
        $this->assertStringContainsString('The live proposal', $deliverable->content['html']);

        Notification::assertSentTo($client, ClientPortalGuestNotification::class, fn ($n) => $n->kind === ClientPortalGuestNotification::PUBLISHED && $n->deliverable->is($deliverable));

        // publishing the same version again changes nothing
        $this->actingAs($this->editor)->post($this->publishRoute(), ['kind' => 'page', 'id' => $page->uuid])->assertSessionHas('success', 'This version is already in the client portal.');
        $this->assertSame(1, ClientDeliverable::count());
    }

    public function test_drafts_never_reach_clients(): void
    {
        $client = $this->client();
        $draft = $this->draftPage(['content' => $this->html('<p>Secret draft plan</p>')]);
        $this->actingAs($this->author)->post($this->publishRoute(), ['kind' => 'page', 'id' => $draft->uuid])->assertSessionHasErrors('id');
        $this->assertSame(0, ClientDeliverable::count());

        // unpublished changes on a published page stay out: the deliverable is the published version
        $page = $this->publishedPage(['content' => $this->html('<p>Published words</p>')]);
        $page->saveDraft($this->author, ['content' => $this->html('<p>Unpublished draft words</p>')]);
        $this->actingAs($this->author)->post($this->publishRoute(), ['kind' => 'page', 'id' => $page->uuid])->assertSessionHasNoErrors();
        auth()->logout();

        $deliverable = ClientDeliverable::firstOrFail();
        $response = $this->as($client)->get($this->deliverableUrl($deliverable))->assertOk();
        $response->assertSee('Published words')->assertDontSee('Unpublished draft words')->assertDontSee('Secret draft plan');

        // a page another editor can't see (someone else's draft) is refused by the page policy too
        $foreign = $this->draftPage(['created_by' => $this->admin->id]);
        $this->actingAs($this->editor)->post($this->publishRoute(), ['kind' => 'page', 'id' => $foreign->uuid])->assertSessionHasErrors('id');
    }

    public function test_internal_links_mentions_and_sticky_notes_are_stripped(): void
    {
        config(['app.url' => 'https://brainitive.test']);
        $html = '<p>See <a href="/workspaces/w/projects/p/pages/abc">the internal spec</a>, '
            .'<a href="https://brainitive.test/workspaces/w/boards/b#item-1">the board</a>, '
            .'<a href="https://www.brainitive.test/x">www app</a>, '
            .'<a href="javascript:alert(1)">bad</a> and <a href="https://example.com/docs">public docs</a> '
            .'or <a href="mailto:hi@example.com">mail us</a>.</p>'
            .'<p>Ask <span data-mention-user-id="42" data-mention-name="Erin" class="mention">@Erin</span>.</p>'
            .'<div data-lexical-sticky="{&quot;caption&quot;:&quot;internal note&quot;}" style="display:none">internal sticky text</div>'
            .'<div data-lexical-excalidraw="{&quot;elements&quot;:[]}"></div>'
            .'<p><img src="/storage/uploads/a.png" alt="ok"><img src="/files/private.png" alt="no"><img src="https://cdn.example.com/b.png" alt="ext"></p>'
            .'<script>alert(1)</script><!-- internal comment -->';

        $clean = DeliverableHtml::clean($html);

        $this->assertStringContainsString('the internal spec', $clean);
        $this->assertStringNotContainsString('/workspaces/', $clean);
        $this->assertStringNotContainsString('www.brainitive.test', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringContainsString('href="https://example.com/docs"', $clean);
        $this->assertStringContainsString('href="mailto:hi', $clean);
        $this->assertStringContainsString('&#64;Erin', $clean); // plain text (the sanitizer encodes @)
        $this->assertStringNotContainsString('data-mention', $clean);
        $this->assertStringNotContainsString('internal sticky text', $clean);
        $this->assertStringNotContainsString('internal note', $clean);
        $this->assertStringNotContainsString('excalidraw', $clean);
        $this->assertStringContainsString('/storage/uploads/a.png', $clean);
        $this->assertStringNotContainsString('/files/private.png', $clean);
        $this->assertStringContainsString('https://cdn.example.com/b.png', $clean);
        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('internal comment', $clean);
    }

    public function test_approving_records_identity_time_and_version_and_is_idempotent(): void
    {
        $client = $this->client();
        [, $deliverable] = $this->sharedPage();

        $this->as($client)->post($this->deliverableUrl($deliverable, '/decision'), ['decision' => 'approved'])->assertRedirect()->assertSessionHasNoErrors();
        $this->as($client)->post($this->deliverableUrl($deliverable, '/decision'), ['decision' => 'approved'])->assertSessionHas('success', 'Already approved.');

        $this->assertSame(1, $deliverable->decisions()->count());
        $decision = $deliverable->decisions()->first();
        $this->assertSame($client->id, $decision->portal_identity_id);
        $this->assertSame('Clara Client', $decision->identity_name);
        $this->assertSame('client@acme.test', $decision->identity_email);
        $this->assertSame(1, $decision->version);
        $this->assertNotNull($decision->created_at);
        $this->assertSame(ClientDeliverable::STATUS_APPROVED, $deliverable->fresh()->status);

        // to the publisher (author) and the project admins, once; not to editors / viewers
        Notification::assertSentTo($this->author, ClientDecisionNotification::class, 1);
        Notification::assertSentTo($this->admin, ClientDecisionNotification::class, 1);
        Notification::assertNotSentTo($this->editor, ClientDecisionNotification::class);
        Notification::assertNotSentTo($this->viewer, ClientDecisionNotification::class);
        Notification::assertNotSentTo($this->outsider, ClientDecisionNotification::class);

        // changing one's mind is a new, recorded decision
        $this->as($client)->post($this->deliverableUrl($deliverable, '/decision'), ['decision' => 'changes_requested', 'note' => 'Swap the <b>hero</b> image'])->assertSessionHasNoErrors();
        $this->assertSame(ClientDeliverable::STATUS_CHANGES, $deliverable->fresh()->status);
        $this->assertSame('Swap the hero image', $deliverable->decisions()->get()->last()->note);

        $this->as($client)->post($this->deliverableUrl($deliverable, '/decision'), ['decision' => 'maybe'])->assertSessionHasErrors('decision');
    }

    public function test_a_new_page_version_supersedes_the_approved_one_and_needs_a_fresh_approval(): void
    {
        $client = $this->client();
        [$page, $v1] = $this->sharedPage('<p>First wording</p>');
        $this->as($client)->post($this->deliverableUrl($v1, '/decision'), ['decision' => 'approved'])->assertSessionHasNoErrors();

        $this->newVersion($page, '<p>Second wording</p>');
        $this->actingAs($this->author)->post($this->publishRoute(), ['kind' => 'page', 'id' => $page->uuid])->assertSessionHasNoErrors();
        auth()->logout();

        $v1->refresh();
        $v2 = ClientDeliverable::whereKeyNot($v1->id)->firstOrFail();
        $this->assertSame(2, $v2->version);
        $this->assertSame(ClientDeliverable::STATUS_AWAITING, $v2->status);
        $this->assertSame(0, $v2->decisions()->count());
        $this->assertNotNull($v1->superseded_at);
        $this->assertSame($v2->id, $v1->superseded_by_id);
        $this->assertSame(ClientDeliverable::STATUS_APPROVED, $v1->status); // kept as history
        $this->assertSame('superseded', $v1->displayStatus());

        // the list shows only the current version
        $this->as($client)->get('/client/acme-site')->assertInertia(fn (AssertableInertia $p) => $p
            ->has('deliverables', 1)->where('deliverables.0.uuid', $v2->uuid)->where('deliverables.0.status', 'awaiting'));

        // the old version is readable history, but can't be decided on any more (no race onto the wrong version)
        $this->as($client)->get($this->deliverableUrl($v1))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('deliverable.can_decide', false)
            ->where('deliverable.latest.uuid', $v2->uuid)
            ->where('deliverable.html', fn ($html) => str_contains($html, 'First wording'))
            ->has('versions', 2));
        $this->as($client)->post($this->deliverableUrl($v1, '/decision'), ['decision' => 'changes_requested'])->assertSessionHasErrors('decision');
        $this->assertSame(1, $v1->decisions()->count());

        $this->as($client)->post($this->deliverableUrl($v2, '/decision'), ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame(2, $v2->decisions()->first()->version);
    }

    public function test_internal_notes_never_reach_clients_and_replies_do(): void
    {
        $client = $this->client();
        [, $deliverable] = $this->sharedPage();
        $comments = route('client-portal.manage.deliverables.comments.store', [$this->workspace->slug, $this->project->slug, $deliverable->id]);

        $this->as($client)->post($this->deliverableUrl($deliverable, '/comments'), ['body' => 'Can the logo be bigger?'])->assertSessionHasNoErrors();
        Notification::assertSentTo($this->author, ClientCommentedNotification::class);
        Notification::assertSentTo($this->admin, ClientCommentedNotification::class);

        $this->actingAs($this->viewer)->post($comments, ['body' => 'x', 'visibility' => 'internal'])->assertForbidden();
        $this->actingAs($this->editor)->post($comments, ['body' => 'They always want a bigger logo', 'visibility' => 'internal'])->assertSessionHasNoErrors();
        Notification::assertNotSentTo($client, ClientPortalGuestNotification::class, fn ($n) => $n->kind === ClientPortalGuestNotification::REPLIED);

        $this->actingAs($this->editor)->post($comments, ['body' => 'Sure, done in the next version', 'visibility' => 'client'])->assertSessionHasNoErrors();
        Notification::assertSentTo($client, ClientPortalGuestNotification::class, fn ($n) => $n->kind === ClientPortalGuestNotification::REPLIED);
        auth()->logout();

        $this->as($client)->get($this->deliverableUrl($deliverable))->assertOk()
            ->assertDontSee('They always want a bigger logo')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->has('deliverable.comments', 2)
                ->where('deliverable.comments.0.body', 'Can the logo be bigger?')
                ->where('deliverable.comments.0.is_me', true)
                ->where('deliverable.comments.1.body', 'Sure, done in the next version')
                ->where('deliverable.comments.1.from_team', true)
                ->missing('deliverable.comments.1.visibility'));

        // the team sees everything, internal marked
        $this->actingAs($this->viewer)->get(route('client-portal.manage.deliverables.show', [$this->workspace->slug, $this->project->slug, $deliverable->id]))
            ->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Projects/ClientPortalDeliverable')
            ->has('deliverable.comments', 3)
            ->where('deliverable.comments.1.visibility', ClientDeliverableComment::VISIBILITY_INTERNAL));
    }

    public function test_a_plan_snapshot_is_frozen_without_hidden_columns_or_issue_links(): void
    {
        $client = $this->client();
        $plan = Plan::create([
            'project_id' => $this->project->id,
            'name' => 'Launch plan',
            'slug' => 'launch-plan',
            'created_by' => $this->author->id,
            'settings' => ['columns' => ['who' => true, 'status' => false, 'start' => true, 'end' => true, 'duration' => true, 'progress' => false, 'notes' => false]],
            'data' => ['rows' => [[
                'id' => 'r1', 'name' => 'Design', 'level' => 0, 'who' => 'Erin', 'notes' => 'internal: client is slow', 'itemId' => 99, 'status' => 'at_risk',
                'bars' => [['id' => 'b1', 'start' => '2026-11-02', 'end' => '2026-11-06', 'type' => 'bar', 'color' => '#0E83DC', 'progress' => 10]],
            ]], 'dependencies' => []],
        ]);
        $plans = route('client-portal.manage.deliverables.store', [$this->workspace->slug, $this->project->slug]);

        $this->actingAs($this->author)->post($plans, ['kind' => 'plan', 'id' => (string) $plan->id])->assertSessionHasNoErrors();
        $this->actingAs($this->author)->post($plans, ['kind' => 'plan', 'id' => (string) $plan->id])->assertSessionHas('success', 'This version is already in the client portal.');
        $deliverable = ClientDeliverable::firstOrFail();
        $row = $deliverable->content['plan']['data']['rows'][0];
        $this->assertSame('Design', $row['name']);
        $this->assertSame('Erin', $row['who']);
        $this->assertArrayNotHasKey('notes', $row);
        $this->assertArrayNotHasKey('itemId', $row);
        $this->assertArrayNotHasKey('status', $row);
        $this->assertArrayNotHasKey('progress', $row['bars'][0]);
        $this->assertSame('2026-11-02', $row['bars'][0]['start']);

        // the plan changes later; the client keeps seeing the snapshot until a new one is published
        $plan->update(['data' => ['rows' => [['id' => 'r1', 'name' => 'Design v2', 'level' => 0, 'bars' => []]], 'dependencies' => []]]);
        auth()->logout();
        $this->as($client)->get($this->deliverableUrl($deliverable))->assertOk()->assertDontSee('Design v2')->assertDontSee('client is slow');

        $this->actingAs($this->author)->post($plans, ['kind' => 'plan', 'id' => (string) $plan->id])->assertSessionHasNoErrors();
        $this->assertSame(2, ClientDeliverable::latest('id')->first()->version);
        $this->assertNotNull($deliverable->fresh()->superseded_at);

        // another project's plan id is rejected
        $foreign = Plan::create(['project_id' => Project::factory()->create()->id, 'name' => 'X', 'slug' => 'x', 'created_by' => $this->author->id]);
        $this->actingAs($this->admin)->post($plans, ['kind' => 'plan', 'id' => (string) $foreign->id])->assertSessionHasErrors('id');
    }

    public function test_the_team_is_notified_without_the_acting_member_and_without_people_who_lost_access(): void
    {
        [, $deliverable] = $this->sharedPage();
        // the project admin is also invited as a client (same email): they don't notify themselves
        $adminAsClient = $this->client(null, $this->admin->email);
        $this->project->users()->detach($this->author->id); // the publisher left the project

        Deliverables::decide($deliverable, $adminAsClient->fresh(), 'approved', null);

        Notification::assertNotSentTo($this->admin, ClientDecisionNotification::class);
        Notification::assertNotSentTo($this->author, ClientDecisionNotification::class);
        $owner = User::find($this->workspace->owner_id);
        Notification::assertSentTo($owner, ClientDecisionNotification::class);
    }

    public function test_the_notifications_follow_the_payload_contract(): void
    {
        $client = $this->client();
        [, $deliverable] = $this->sharedPage();
        $decision = Deliverables::decide($deliverable, $client, 'changes_requested', 'Too long');

        $data = (new ClientDecisionNotification($deliverable, $decision))->toDatabase($this->admin);
        $this->assertSame('client_decision', $data['type']);
        $this->assertSame('Clara Client requested changes on “Homepage copy” (v1)', $data['title']);
        $this->assertSame('Too long', $data['body']);
        $this->assertStringEndsWith('/client-portal/deliverables/'.$deliverable->id, $data['url']);

        $mail = (new ClientPortalGuestNotification(ClientPortalGuestNotification::PUBLISHED, $this->portal, $deliverable, 'Erin [x](http://evil)', 'Look **here**'))->toMail($client);
        $this->assertStringNotContainsString('](', implode(' ', $mail->introLines));
        $this->assertStringContainsString('/client/acme-site/d/'.$deliverable->uuid, $mail->actionUrl);
        $this->assertInstanceOf(Page::class, $deliverable->page);
    }
}
