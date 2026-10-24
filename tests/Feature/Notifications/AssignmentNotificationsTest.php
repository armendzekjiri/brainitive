<?php

namespace Tests\Feature\Notifications;

use App\Mcp\Servers\BrainitiveServer;
use App\Mcp\Tools\CreateBoardItemTool;
use App\Mcp\Tools\UpdateBoardItemTool;
use App\Models\Item;
use App\Models\LeadForm;
use App\Models\TaskList;
use App\Models\TaskListItem;
use App\Notifications\ItemAssignedNotification;
use App\Notifications\LeadAssignedNotification;
use App\Notifications\TaskAssignedNotification;
use App\Support\Boards\ProjectIssues;
use App\Support\MeetingNotes\ActionItemCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * BRAI-103: "assigned you" fires from one place per model (ItemObserver, TaskListItemObserver,
 * LeadController) — so every path notifies, exactly once, never the person who assigned themselves.
 */
class AssignmentNotificationsTest extends TestCase
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

    public function test_creating_and_reassigning_in_the_board_notifies_the_new_assignee_once(): void
    {
        $other = $this->projectMember('editor');

        $this->actingAs($this->editor)->post($this->boardRoute('boards.items.store'), [
            'title' => 'Ship it', 'column_id' => $this->column('to-do'), 'assigned_to' => $this->viewer->id,
        ])->assertSessionHasNoErrors();
        $item = Item::where('title', 'Ship it')->firstOrFail();

        Notification::assertSentToTimes($this->viewer, ItemAssignedNotification::class, 1);

        // Re-saving with the same assignee is not a new assignment.
        $this->actingAs($this->editor)->patch($this->itemRoute('boards.items.update', $item), ['title' => 'Ship it now', 'assigned_to' => $this->viewer->id])
            ->assertSessionHasNoErrors();
        Notification::assertSentToTimes($this->viewer, ItemAssignedNotification::class, 1);

        $this->actingAs($this->editor)->patch($this->itemRoute('boards.items.update', $item), ['assigned_to' => $other->id])
            ->assertSessionHasNoErrors();
        Notification::assertSentToTimes($other, ItemAssignedNotification::class, 1);
        Notification::assertSentTo($other, ItemAssignedNotification::class, fn (ItemAssignedNotification $n) => $n->actor->is($this->editor));
    }

    public function test_assigning_yourself_and_system_writes_notify_nobody(): void
    {
        $this->actingAs($this->editor)->post($this->boardRoute('boards.items.store'), [
            'title' => 'Mine', 'column_id' => $this->column('to-do'), 'assigned_to' => $this->editor->id,
        ])->assertSessionHasNoErrors();

        auth()->logout();
        $this->item(['assigned_to' => $this->viewer->id]); // seeder / console: no actor

        Notification::assertNothingSent();
    }

    public function test_mcp_create_and_update_notify_the_assignee(): void
    {
        $args = ['workspace' => $this->workspace->slug, 'project' => $this->project->slug, 'board' => $this->board->slug];

        BrainitiveServer::actingAs($this->editor)->tool(CreateBoardItemTool::class, $args + ['title' => 'From the assistant', 'assignee' => $this->viewer->email])
            ->assertOk();
        Notification::assertSentToTimes($this->viewer, ItemAssignedNotification::class, 1);

        $other = $this->projectMember('editor');
        $item = Item::where('title', 'From the assistant')->firstOrFail();
        BrainitiveServer::actingAs($this->editor)->tool(UpdateBoardItemTool::class, $args + ['item_id' => $item->id, 'assignee' => $other->email])
            ->assertOk();
        Notification::assertSentToTimes($other, ItemAssignedNotification::class, 1);
    }

    public function test_meeting_note_action_items_and_plan_imports_notify_their_assignees(): void
    {
        $this->actingAs($this->editor);

        ActionItemCreator::create($this->editor, $this->workspace, $this->project, $this->board, null, false, 'key-1', [
            ['title' => 'Send the minutes', 'assigned_to' => $this->viewer->id],
            ['title' => 'Book the room', 'assigned_to' => $this->editor->id],
        ]);
        Notification::assertSentToTimes($this->viewer, ItemAssignedNotification::class, 1);
        Notification::assertNotSentTo($this->editor, ItemAssignedNotification::class);

        $other = $this->projectMember('editor');
        ProjectIssues::create($this->board, $this->project, $this->editor, [['title' => 'Imported row', 'assigned_to' => $other->id]]);
        Notification::assertSentToTimes($other, ItemAssignedNotification::class, 1);
    }

    public function test_people_without_project_access_are_not_told(): void
    {
        $this->actingAs($this->editor);
        $this->item(['assigned_to' => $this->outsider->id]);

        Notification::assertNotSentTo($this->outsider, ItemAssignedNotification::class);
    }

    public function test_task_list_tasks_notify_the_assignee_on_create_and_reassign(): void
    {
        $list = TaskList::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $route = fn (string $name, array $extra = []) => route($name, [$this->workspace->slug, $this->project->slug, $list->slug, ...$extra]);

        $this->actingAs($this->editor)->post($route('task-lists.items.store'), ['title' => 'Water plants', 'assignee_id' => $this->viewer->id])
            ->assertSessionHasNoErrors();
        $task = TaskListItem::where('title', 'Water plants')->firstOrFail();
        Notification::assertSentToTimes($this->viewer, TaskAssignedNotification::class, 1);

        $this->actingAs($this->editor)->patch($route('task-lists.items.update', [$task->id]), ['title' => 'Water all plants'])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->patch($route('task-lists.items.update', [$task->id]), ['assignee_id' => $this->editor->id])->assertSessionHasNoErrors();
        Notification::assertSentToTimes($this->viewer, TaskAssignedNotification::class, 1);
        Notification::assertNotSentTo($this->editor, TaskAssignedNotification::class);

        $payload = (new TaskAssignedNotification($task->fresh(), $this->editor))->toDatabase($this->viewer);
        $this->assertSame('task_assigned', $payload['type']);
        $this->assertSame('Water all plants', $payload['body']);
        $this->assertStringContainsString("#task-{$task->id}", $payload['url']);
    }

    public function test_assigning_a_lead_notifies_the_new_assignee_once(): void
    {
        $this->actingAs($this->editor)->post(route('lead-forms.store', [$this->workspace->slug, $this->project->slug]), ['name' => 'Demo requests', 'template' => 'demo_request']);
        $form = LeadForm::where('name', 'Demo requests')->firstOrFail();
        $lead = $form->leads()->create(['column_id' => $form->columns()->first()->id, 'number' => 7, 'name' => 'Jane Doe', 'position' => 0]);
        $route = route('leads.update', [$this->workspace->slug, $this->project->slug, $form->slug, $lead->id]);

        $this->actingAs($this->editor)->patch($route, ['assigned_to' => $this->viewer->id])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->patch($route, ['assigned_to' => $this->viewer->id, 'company' => 'Acme'])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->patch($route, ['assigned_to' => $this->editor->id])->assertSessionHasNoErrors();

        Notification::assertSentToTimes($this->viewer, LeadAssignedNotification::class, 1);
        Notification::assertNotSentTo($this->editor, LeadAssignedNotification::class);
        Notification::assertSentTo($this->viewer, LeadAssignedNotification::class, function (LeadAssignedNotification $n) {
            $d = $n->toDatabase($this->viewer);

            return $d['title'] === "{$this->editor->name} assigned you lead #7" && $d['body'] === 'Jane Doe' && $d['subject_key'] === '#7';
        });
    }
}
