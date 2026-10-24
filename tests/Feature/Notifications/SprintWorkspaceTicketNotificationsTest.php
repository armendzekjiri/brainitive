<?php

namespace Tests\Feature\Notifications;

use App\Models\Helpdesk;
use App\Models\Sprint;
use App\Models\TicketContact;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Notifications\InvitationAcceptedNotification;
use App\Notifications\SprintCompletedNotification;
use App\Notifications\SprintStartedNotification;
use App\Notifications\TicketStatusChangedNotification;
use App\Notifications\WorkspaceRoleChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/** BRAI-103: sprint_started / sprint_completed, invitation_accepted, workspace_role_changed, ticket_status_changed. */
class SprintWorkspaceTicketNotificationsTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Notification::fake();
    }

    private function boardRoute(string $name, array $extra = []): string
    {
        return route($name, [$this->workspace->slug, $this->project->slug, $this->board->slug, ...$extra]);
    }

    public function test_sprint_start_and_completion_reach_everyone_with_an_issue_in_it_except_the_actor(): void
    {
        $this->board->update(['type' => 'scrum']);
        $member = $this->projectMember('editor');
        $sprint = Sprint::factory()->create(['board_id' => $this->board->id, 'name' => 'Sprint 7']);
        $this->item(['sprint_id' => $sprint->id, 'assigned_to' => $member->id]);
        $this->item(['sprint_id' => $sprint->id, 'assigned_to' => $member->id, 'column_id' => $this->column('done'), 'completed_at' => now()]);
        $this->item(['sprint_id' => $sprint->id, 'assigned_to' => $this->editor->id]);
        $this->item(['sprint_id' => $sprint->id]);

        $this->actingAs($this->editor)
            ->post($this->boardRoute('boards.sprints.start', [$sprint->id]), ['start_date' => '2026-11-02', 'end_date' => '2026-11-16'])
            ->assertRedirect();

        Notification::assertSentToTimes($member, SprintStartedNotification::class, 1);
        Notification::assertNotSentTo($this->editor, SprintStartedNotification::class);
        Notification::assertSentTo($member, SprintStartedNotification::class, function (SprintStartedNotification $n) use ($member) {
            $d = $n->toDatabase($member);

            return $d['type'] === 'sprint_started' && $d['title'] === "{$this->editor->name} started Sprint 7" && $d['body'] === '3 open · 1 done · ends Nov 16';
        });

        $this->actingAs($this->editor)->post($this->boardRoute('boards.sprints.complete', [$sprint->id]))->assertRedirect();

        Notification::assertSentToTimes($member, SprintCompletedNotification::class, 1);
        Notification::assertNotSentTo($this->editor, SprintCompletedNotification::class);
        Notification::assertSentTo($member, SprintCompletedNotification::class,
            fn (SprintCompletedNotification $n) => $n->toDatabase($member)['body'] === '1 done · 3 open moved on');
    }

    public function test_the_inviter_hears_when_their_invitation_is_accepted(): void
    {
        $inviter = User::find($this->workspace->owner_id);
        $invitee = User::factory()->create(['email' => 'nina@example.com']);
        $invitation = WorkspaceInvitation::create(['workspace_id' => $this->workspace->id, 'email' => 'nina@example.com', 'role' => 'member', 'invited_by' => $inviter->id]);

        $this->actingAs($invitee)->post(route('invitations.accept', $invitation->token))->assertRedirect();

        Notification::assertSentToTimes($inviter, InvitationAcceptedNotification::class, 1);
        Notification::assertSentTo($inviter, InvitationAcceptedNotification::class, function (InvitationAcceptedNotification $n) use ($inviter, $invitee) {
            $d = $n->toDatabase($inviter);

            return $d['type'] === 'invitation_accepted' && $d['title'] === "{$invitee->name} joined {$this->workspace->name}" && $d['actor_name'] === $invitee->name;
        });
    }

    public function test_sign_up_with_a_pending_invitation_tells_the_inviter_too(): void
    {
        $inviter = User::find($this->workspace->owner_id);
        WorkspaceInvitation::create(['workspace_id' => $this->workspace->id, 'email' => 'otto@example.com', 'role' => 'editor', 'invited_by' => $inviter->id]);
        $otto = User::factory()->create(['email' => 'otto@example.com']);

        WorkspaceInvitation::acceptPendingForUser($otto);

        Notification::assertSentTo($inviter, InvitationAcceptedNotification::class, fn (InvitationAcceptedNotification $n) => $n->member->is($otto) && $n->role === 'editor');
    }

    public function test_a_member_hears_about_a_role_change_but_not_a_no_op(): void
    {
        $admin = User::find($this->workspace->owner_id);
        $route = route('workspaces.members.update', [$this->workspace->slug, $this->editor->id]);

        $this->actingAs($admin)->patch($route, ['role' => 'member'])->assertRedirect(); // unchanged
        $this->actingAs($admin)->patch($route, ['role' => 'admin'])->assertRedirect();

        Notification::assertSentToTimes($this->editor, WorkspaceRoleChangedNotification::class, 1);
        Notification::assertSentTo($this->editor, WorkspaceRoleChangedNotification::class,
            fn (WorkspaceRoleChangedNotification $n) => $n->oldRole === 'member' && $n->newRole === 'admin'
                && $n->toDatabase($this->editor)['title'] === "{$admin->name} made you admin in {$this->workspace->name}");
    }

    public function test_ticket_watchers_hear_about_a_status_change_except_the_mover(): void
    {
        $admin = User::find($this->workspace->owner_id);
        $this->project->users()->attach($admin->id, ['role' => 'admin']);
        $watcher = $this->projectMember('editor');
        $helpdesk = Helpdesk::create(['project_id' => $this->project->id, 'name' => 'Support', 'slug' => 'support', 'created_by' => $admin->id]);
        $columns = $helpdesk->columns()->orderBy('position')->get();
        $contact = TicketContact::create(['email' => 'cleo@example.com', 'uuid' => (string) Str::uuid()]);
        $ticket = $helpdesk->tickets()->create(['subject' => 'Printer on fire', 'column_id' => $columns[0]->id, 'ticket_contact_id' => $contact->id, 'key' => 'SUP-1', 'position' => 0]);
        $ticket->watchers()->sync([$watcher->id, $admin->id]);
        $route = route('tickets.move', [$this->workspace->slug, $this->project->slug, $helpdesk->slug, $ticket->id]);

        $this->actingAs($admin)->patch($route, ['column_id' => $columns[0]->id, 'position' => 1])->assertSessionHasNoErrors(); // same column
        $this->actingAs($admin)->patch($route, ['column_id' => $columns[1]->id, 'position' => 0])->assertSessionHasNoErrors();

        Notification::assertSentToTimes($watcher, TicketStatusChangedNotification::class, 1);
        Notification::assertNotSentTo($admin, TicketStatusChangedNotification::class);
        Notification::assertSentTo($watcher, TicketStatusChangedNotification::class, function (TicketStatusChangedNotification $n) use ($watcher, $columns) {
            $d = $n->toDatabase($watcher);

            return $d['type'] === 'ticket_status_changed' && $d['subject_key'] === 'SUP-1' && $d['title'] === "{$n->actor->name} moved SUP-1 to {$columns[1]->name}";
        });
    }
}
