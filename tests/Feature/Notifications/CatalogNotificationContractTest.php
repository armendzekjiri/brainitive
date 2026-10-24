<?php

namespace Tests\Feature\Notifications;

use App\Models\Comment;
use App\Models\Helpdesk;
use App\Models\LeadForm;
use App\Models\Sprint;
use App\Models\TaskList;
use App\Models\TaskListItem;
use App\Models\TicketContact;
use App\Models\Webhook;
use App\Notifications\CatalogNotification;
use App\Notifications\InvitationAcceptedNotification;
use App\Notifications\ItemCommentedNotification;
use App\Notifications\ItemDueNotification;
use App\Notifications\LeadAssignedNotification;
use App\Notifications\NotificationCatalog;
use App\Notifications\SprintCompletedNotification;
use App\Notifications\SprintStartedNotification;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TicketSlaBreachedNotification;
use App\Notifications\TicketSlaWarningNotification;
use App\Notifications\TicketStatusChangedNotification;
use App\Notifications\WebhookDisabledNotification;
use App\Notifications\WorkspaceRoleChangedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Every BRAI-103 type follows the payload contract of release-v1.39.0.md exactly, is in the catalog,
 * is queued, and picks its channels from the user's category preferences.
 */
class CatalogNotificationContractTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    private const KEYS = ['type', 'title', 'body', 'url', 'actor_name', 'actor_avatar', 'project_name', 'subject_key'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
    }

    /** @return list<CatalogNotification> one of each new type */
    private function notifications(): array
    {
        $item = $this->item(['key' => 'BRAI-1']);
        $comment = Comment::create(['item_id' => $item->id, 'user_id' => $this->editor->id, 'body' => '<p>Hi <a href="https://evil.test">[x](y)</a></p>']);
        $list = TaskList::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $task = TaskListItem::factory()->create(['task_list_id' => $list->id, 'created_by' => $this->editor->id]);
        $this->actingAs($this->editor)->post(route('lead-forms.store', [$this->workspace->slug, $this->project->slug]), ['name' => 'Demo', 'template' => 'demo_request']);
        $form = LeadForm::where('name', 'Demo')->firstOrFail();
        $lead = $form->leads()->create(['column_id' => $form->columns()->first()->id, 'number' => 1, 'email' => 'jane@example.com', 'position' => 0]);
        $sprint = Sprint::factory()->create(['board_id' => $this->board->id]);
        $helpdesk = Helpdesk::create(['project_id' => $this->project->id, 'name' => 'Support', 'slug' => 'support', 'created_by' => $this->editor->id]);
        $contact = TicketContact::create(['email' => 'c@example.com', 'uuid' => (string) Str::uuid()]);
        $ticket = $helpdesk->tickets()->create(['subject' => 'Help', 'column_id' => $helpdesk->columns()->first()->id, 'ticket_contact_id' => $contact->id, 'key' => 'SUP-1', 'position' => 0]);
        $webhook = Webhook::create(['workspace_id' => $this->workspace->id, 'name' => 'Zapier', 'url' => 'https://hooks.example.com/x?token=secret', 'secret' => Webhook::generateSecret(), 'events' => ['*'], 'created_by' => $this->editor->id]);
        auth()->logout();

        return [
            new ItemCommentedNotification($item, $comment, $this->editor),
            new ItemDueNotification($item, ItemDueNotification::OVERDUE, 'Was due Mon, Nov 2'),
            new TaskAssignedNotification($task, $this->editor),
            new LeadAssignedNotification($lead, $this->editor),
            new SprintStartedNotification($sprint, $this->editor, 3, 1),
            new SprintCompletedNotification($sprint, null, 1, 3),
            new InvitationAcceptedNotification($this->workspace, $this->editor, 'member'),
            new WorkspaceRoleChangedNotification($this->workspace, $this->editor, 'member', 'admin'),
            new TicketStatusChangedNotification($ticket, $this->editor, 'New', 'Done'),
            new WebhookDisabledNotification($webhook, 'HTTP 500'), // BRAI-104
            new TicketSlaWarningNotification($ticket, 'first_response', now()->addHour()), // BRAI-125
            new TicketSlaBreachedNotification($ticket, 'resolution', now()->subHour()),
        ];
    }

    public function test_every_new_type_follows_the_payload_contract(): void
    {
        foreach ($this->notifications() as $notification) {
            $class = $notification::class;
            $data = $notification->toDatabase($this->viewer);

            $this->assertSame(self::KEYS, array_keys($data), $class);
            $this->assertSame($notification->catalogType(), $data['type'], $class);
            $this->assertArrayHasKey($data['type'], NotificationCatalog::TYPES, $class);
            $this->assertNotSame('', $data['title'], $class);
            $this->assertStringStartsWith(config('app.url'), $data['url'], $class);
            $this->assertTrue($data['body'] === null || mb_strlen($data['body']) <= 200, $class);
            $this->assertInstanceOf(ShouldQueue::class, $notification, $class);
            $this->assertInstanceOf(MailMessage::class, $notification->toMail($this->viewer), $class);
        }
    }

    public function test_html_in_bodies_becomes_plain_text_and_mail_has_no_markdown_links(): void
    {
        $comment = $this->notifications()[0];

        $this->assertSame('Hi [x](y)', $comment->toDatabase($this->viewer)['body']);
        $this->assertStringNotContainsString('](', implode(' ', $comment->toMail($this->viewer)->introLines));
    }

    public function test_channels_follow_the_category_defaults_and_the_users_choice(): void
    {
        [$commented, , $task] = $this->notifications();

        // defaults: comments = in-app only; assignments = in-app + push
        $this->assertSame(['database'], $commented->via($this->viewer));
        $this->assertSame(['database', WebPushChannel::class], $task->via($this->viewer));

        $this->viewer->update(['notification_preferences' => ['assignments' => ['in_app' => false, 'push' => false, 'email' => true]]]);
        $this->assertSame(['mail'], $task->via($this->viewer->fresh()));
    }
}
