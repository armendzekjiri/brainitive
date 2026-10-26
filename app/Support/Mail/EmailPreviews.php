<?php

namespace App\Support\Mail;

use App\Mail\BillingReportMail;
use App\Mail\InstanceTestMail;
use App\Models\BillingReport;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\ClientDeliverable;
use App\Models\ClientDeliverableComment;
use App\Models\ClientDeliverableDecision;
use App\Models\ClientPortal;
use App\Models\Comment;
use App\Models\FeedbackPortal;
use App\Models\FeedbackPost;
use App\Models\GithubConnection;
use App\Models\Helpdesk;
use App\Models\Item;
use App\Models\Lead;
use App\Models\LeadForm;
use App\Models\Page;
use App\Models\PageComment;
use App\Models\PageReview;
use App\Models\PortalIdentity;
use App\Models\Project;
use App\Models\ProjectExport;
use App\Models\Sprint;
use App\Models\TaskList;
use App\Models\TaskListItem;
use App\Models\Ticket;
use App\Models\TicketContact;
use App\Models\TicketReply;
use App\Models\User;
use App\Models\Webhook;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Notifications\AutomationNoticeNotification;
use App\Notifications\ClientCommentedNotification;
use App\Notifications\ClientDecisionNotification;
use App\Notifications\ClientPortalGuestNotification;
use App\Notifications\FeedbackStatusChangedNotification;
use App\Notifications\FeedbackSubmittedNotification;
use App\Notifications\GithubSyncFailedNotification;
use App\Notifications\InvitationAcceptedNotification;
use App\Notifications\ItemAssignedNotification;
use App\Notifications\ItemCommentedNotification;
use App\Notifications\ItemDueNotification;
use App\Notifications\ItemReminderNotification;
use App\Notifications\ItemStatusChangedNotification;
use App\Notifications\LeadAssignedNotification;
use App\Notifications\LeadConfirmationNotification;
use App\Notifications\MentionNotification;
use App\Notifications\NewLeadNotification;
use App\Notifications\PageUpdatedNotification;
use App\Notifications\PortalLoginLinkNotification;
use App\Notifications\ProjectExportReadyNotification;
use App\Notifications\ReviewCommentedNotification;
use App\Notifications\ReviewDecidedNotification;
use App\Notifications\ReviewRequestedNotification;
use App\Notifications\SprintCompletedNotification;
use App\Notifications\SprintStartedNotification;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskDigestNotification;
use App\Notifications\TicketAssignedNotification;
use App\Notifications\TicketCreatedNotification;
use App\Notifications\TicketReplyNotification;
use App\Notifications\TicketResolvedNotification;
use App\Notifications\TicketSlaBreachedNotification;
use App\Notifications\TicketSlaWarningNotification;
use App\Notifications\TicketStatusChangedNotification;
use App\Notifications\VerifyEmailNotification;
use App\Notifications\WebhookDisabledNotification;
use App\Notifications\WorkspaceInvitationNotification;
use App\Notifications\WorkspaceRoleChangedNotification;
use App\Support\Helpdesks\Mail\TicketMailer;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;

/**
 * Every email the app sends, with sample data, for Instance admin → Emails (BRAI-155) and the
 * template tests. Samples are unsaved models with made-up names (never real users or content),
 * rendered inside a transaction that is always rolled back, so nothing a template touches is kept.
 */
final class EmailPreviews
{
    public const GROUPS = [
        'member' => 'Member notifications',
        'guest' => 'Customers, clients & visitors',
        'account' => 'Account & admin',
    ];

    /**
     * key => [label, group, recipient, build]. `build` returns [Notification|Mailable|Email, notifiable].
     *
     * @return array<string, array{label: string, group: string, recipient: string, build: \Closure}>
     */
    public static function entries(): array
    {
        $s = new self;

        $member = fn (string $label, \Closure $notification) => ['label' => $label, 'group' => 'member', 'recipient' => 'Member', 'build' => fn () => [$notification(), $s->alex()]];

        return [
            // members — CatalogNotification
            'automation-notice' => $member('Automation notice', fn () => new AutomationNoticeNotification($s->item(), 'Escalate urgent bugs', 'Priority was raised to Urgent.', $s->sam())),
            'client-commented' => $member('Client commented', fn () => new ClientCommentedNotification($s->deliverable(), $s->make(ClientDeliverableComment::class, ['author_name' => 'Jordan Lee', 'body' => 'Could the hero image be a little brighter?']))),
            'client-decision' => $member('Client decision', fn () => new ClientDecisionNotification($s->deliverable(), $s->make(ClientDeliverableDecision::class, ['decision' => ClientDeliverable::STATUS_APPROVED, 'identity_name' => 'Jordan Lee', 'version' => 2, 'note' => 'Looks great — ship it.']))),
            'feedback-submitted' => $member('Feedback idea waiting', fn () => new FeedbackSubmittedNotification($s->feedbackPost())),
            'github-sync-failed' => $member('GitHub sync failed', fn () => new GithubSyncFailedNotification($s->make(GithubConnection::class, [], ['project' => $s->project()]), 'The GitHub App was uninstalled from the repository.')),
            'invitation-accepted' => $member('Invitation accepted', fn () => new InvitationAcceptedNotification($s->workspace(), $s->sam(), 'editor')),
            'item-commented' => $member('Issue commented', fn () => new ItemCommentedNotification($s->item(), $s->make(Comment::class, ['body' => '<p>I can reproduce it on Safari 18 — the redirect drops the query string.</p>', 'parent_id' => null]), $s->sam())),
            'item-due' => $member('Issue overdue', fn () => new ItemDueNotification($s->item(), ItemDueNotification::OVERDUE, 'was due Mon, Oct 26')),
            'lead-assigned' => $member('Lead assigned', fn () => new LeadAssignedNotification($s->lead(), $s->sam())),
            'project-export-ready' => $member('Project export ready', fn () => new ProjectExportReadyNotification($s->make(ProjectExport::class, [
                'uuid' => (string) Str::uuid(), 'status' => ProjectExport::READY, 'file_path' => 'exports/sample.zip', 'file_size' => 4_812_000, 'expires_at' => now()->addHours(ProjectExport::TTL_HOURS),
            ], ['project' => $s->project(), 'workspace' => $s->workspace()]))),
            'review-commented' => $member('Review comment', fn () => new ReviewCommentedNotification($s->review(), $s->make(PageComment::class, ['body' => 'Can we say “weekly” instead of “regularly” here?', 'parent_id' => null]), $s->sam())),
            'review-decided' => $member('Review decided', fn () => new ReviewDecidedNotification($s->review(), 'approved', 'Clear and complete. Approved.', $s->sam())),
            'review-requested' => $member('Review requested', fn () => new ReviewRequestedNotification($s->review(), $s->sam())),
            'sprint-completed' => $member('Sprint completed', fn () => new SprintCompletedNotification($s->sprint(), $s->sam(), 3, 14)),
            'sprint-started' => $member('Sprint started', fn () => new SprintStartedNotification($s->sprint(), $s->sam(), 12, 2)),
            'task-assigned' => $member('Task assigned', fn () => new TaskAssignedNotification($s->task(), $s->sam())),
            'ticket-sla-breached' => $member('SLA breached', fn () => new TicketSlaBreachedNotification($s->ticket(), 'first_response', now()->subMinutes(40))),
            'ticket-sla-warning' => $member('SLA warning', fn () => new TicketSlaWarningNotification($s->ticket(), 'resolution', now()->addHours(2))),
            'ticket-status-changed' => $member('Ticket status changed', fn () => new TicketStatusChangedNotification($s->ticket(), $s->sam(), 'Open', 'In progress')),
            'webhook-disabled' => $member('Webhook disabled', fn () => new WebhookDisabledNotification($s->make(Webhook::class, ['name' => 'Deploy hook', 'url' => 'https://hooks.example.com/brainitive'], ['workspace' => $s->workspace()]), 'HTTP 500 Internal Server Error')),
            'workspace-role-changed' => $member('Workspace role changed', fn () => new WorkspaceRoleChangedNotification($s->workspace(), $s->sam(), 'viewer', 'editor')),
            // members — own toMail
            'item-assigned' => $member('Issue assigned', fn () => new ItemAssignedNotification($s->item(), $s->sam())),
            'item-status-changed' => $member('Issue moved', fn () => new ItemStatusChangedNotification($s->item(), $s->make(BoardColumn::class, ['name' => 'In progress']), $s->make(BoardColumn::class, ['name' => 'Review']), $s->sam())),
            'item-reminder' => $member('Issue reminder', fn () => new ItemReminderNotification('WEB-42', 'Fix the login redirect', 'starts in 15 minutes', '/calendar')),
            'mention' => $member('Mention', fn () => new MentionNotification('item', 'Fix the login redirect', url('/'), $s->sam())),
            'page-updated' => $member('Page updated', fn () => new PageUpdatedNotification($s->page(), $s->sam())),
            'task-digest' => $member('Daily task digest', fn () => new TaskDigestNotification(2, 1, ['Send the October invoice', 'Book the venue', 'Review the onboarding copy'], url('/my-work'))),
            'new-lead' => $member('New lead', fn () => new NewLeadNotification($s->lead())),
            'ticket-assigned' => $member('Ticket assigned', fn () => new TicketAssignedNotification($s->ticket(), $s->sam())),
            'ticket-created-team' => $member('New ticket (team)', fn () => new TicketCreatedNotification($s->ticket(), $s->contact())),
            'ticket-reply-team' => $member('Ticket reply (team)', fn () => new TicketReplyNotification($s->ticket(), $s->ticketReply('<p>Thanks! It works again after clearing the cache.</p>'), $s->contact())),

            // customers, clients, visitors
            'ticket-created-customer' => ['label' => 'Ticket received', 'group' => 'guest', 'recipient' => 'Helpdesk customer', 'build' => fn () => [new TicketCreatedNotification($s->ticket()), $s->contact()]],
            'ticket-reply-customer' => ['label' => 'Ticket reply', 'group' => 'guest', 'recipient' => 'Helpdesk customer', 'build' => fn () => [new TicketReplyNotification($s->ticket(), $s->ticketReply('<p>Hi Jordan,</p><p>we found the cause: the reset link expired too early. It is fixed now — could you try again?</p>'), $s->sam()), $s->contact()]],
            'ticket-resolved' => ['label' => 'Ticket resolved', 'group' => 'guest', 'recipient' => 'Helpdesk customer', 'build' => fn () => [new TicketResolvedNotification($s->ticket(), '<p>Hi Jordan,</p><p>the fix is live. Thanks for your patience!</p>'), $s->contact()]],
            'helpdesk-mail-reply' => ['label' => 'Mailbox reply (threaded)', 'group' => 'guest', 'recipient' => 'Helpdesk customer', 'build' => fn () => [TicketMailer::compose($s->ticket(), $s->ticketReply('<p>Hi Jordan,</p><p>we found the cause: the reset link expired too early. It is <b>fixed</b> now.</p><p>Best,<br>Sam</p>')), $s->contact()]],
            'helpdesk-mail-acknowledgment' => ['label' => 'Mailbox acknowledgment', 'group' => 'guest', 'recipient' => 'Helpdesk customer', 'build' => fn () => [TicketMailer::compose($s->ticket(), null), $s->contact()]],
            'lead-confirmation' => ['label' => 'Lead confirmation', 'group' => 'guest', 'recipient' => 'Form visitor', 'build' => fn () => [new LeadConfirmationNotification($s->lead()), (new AnonymousNotifiable)->route('mail', 'jordan@example.com')]],
            'portal-login-link' => ['label' => 'Portal sign-in link', 'group' => 'guest', 'recipient' => 'Portal guest', 'build' => fn () => [new PortalLoginLinkNotification(Str::random(48)), $s->guest()]],
            'client-portal-invited' => ['label' => 'Client portal invitation', 'group' => 'guest', 'recipient' => 'Client', 'build' => fn () => [new ClientPortalGuestNotification(ClientPortalGuestNotification::INVITED, $s->portal(), null, 'Sam Rivera'), $s->guest()]],
            'client-portal-published' => ['label' => 'Client deliverable ready', 'group' => 'guest', 'recipient' => 'Client', 'build' => fn () => [new ClientPortalGuestNotification(ClientPortalGuestNotification::PUBLISHED, $s->portal(), $s->deliverable(), 'Sam Rivera', 'Version 2 has the brighter hero image you asked for.'), $s->guest()]],
            'client-portal-replied' => ['label' => 'Client portal reply', 'group' => 'guest', 'recipient' => 'Client', 'build' => fn () => [new ClientPortalGuestNotification(ClientPortalGuestNotification::REPLIED, $s->portal(), $s->deliverable(), 'Sam Rivera', 'Good point — we will update it tomorrow.'), $s->guest()]],
            'feedback-status-changed' => ['label' => 'Feedback status changed', 'group' => 'guest', 'recipient' => 'Idea follower', 'build' => fn () => [new FeedbackStatusChangedNotification($s->feedbackPost(), $s->feedbackPortal(), 'planned', 'We plan to ship this in November.'), $s->guest()]],
            'billing-report' => ['label' => 'Billing report / invoice', 'group' => 'guest', 'recipient' => 'Client', 'build' => fn () => [$s->billingMail(), null]],

            // account & admin
            'verify-email' => ['label' => 'Verify email', 'group' => 'account', 'recipient' => 'New account', 'build' => fn () => [new VerifyEmailNotification, $s->alex()]],
            'reset-password' => ['label' => 'Reset password', 'group' => 'account', 'recipient' => 'Account holder', 'build' => fn () => [new ResetPassword(Str::random(64)), $s->alex()]],
            'workspace-invitation' => ['label' => 'Workspace invitation', 'group' => 'account', 'recipient' => 'Invitee', 'build' => fn () => [new WorkspaceInvitationNotification($s->make(WorkspaceInvitation::class, ['token' => Str::random(64), 'role' => 'editor']), $s->workspace(), $s->sam()), (new AnonymousNotifiable)->route('mail', 'jordan@example.com')]],
            'instance-test' => ['label' => 'Admin test email', 'group' => 'account', 'recipient' => 'Instance admin', 'build' => fn () => [new InstanceTestMail('smtp'), null]],
        ];
    }

    /** @return list<array{key: string, label: string, group: string, recipient: string}> */
    public static function list(): array
    {
        return collect(self::entries())
            ->map(fn (array $e, string $key) => ['key' => $key, 'label' => $e['label'], 'group' => $e['group'], 'recipient' => $e['recipient']])
            ->values()->all();
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::entries());
    }

    /**
     * Subject, HTML and text of one email, rendered from sample data. Nothing is saved: the render runs
     * in a transaction that is always rolled back.
     *
     * @return array{subject: string, html: string, text: string}
     */
    public static function render(string $key): array
    {
        $entry = self::entries()[$key] ?? throw new \InvalidArgumentException("Unknown email preview [{$key}].");

        DB::beginTransaction();
        try {
            [$message, $notifiable] = ($entry['build'])();

            return match (true) {
                $message instanceof Email => [
                    'subject' => (string) $message->getSubject(),
                    'html' => (string) $message->getHtmlBody(),
                    'text' => (string) $message->getTextBody(),
                ],
                $message instanceof Mailable => self::renderMailable($message),
                $message instanceof Notification => self::renderMailMessage($message->toMail($notifiable)),
            };
        } finally {
            DB::rollBack();
        }
    }

    /** @return array{subject: string, html: string, text: string} */
    private static function renderMailMessage(MailMessage $mail): array
    {
        $markdown = app(Markdown::class);

        return [
            'subject' => (string) $mail->subject,
            'html' => (string) $mail->render(),
            'text' => (string) $markdown->renderText($mail->markdown, $mail->data()),
        ];
    }

    /** @return array{subject: string, html: string, text: string} */
    private static function renderMailable(Mailable $mail): array
    {
        $content = $mail->content();
        $data = array_merge($mail->buildViewData(), $content->with);

        return [
            'subject' => (string) $mail->envelope()->subject,
            'html' => (string) $mail->render(),
            'text' => $content->text
                ? (string) view($content->text, $data)->render()
                : (string) app(Markdown::class)->renderText((string) $content->markdown, $data),
        ];
    }

    // ── sample data (unsaved models, made-up people) ────────────────────────────────────────

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function make(string $class, array $attributes, array $relations = []): Model
    {
        $model = new $class;
        $model->forceFill($attributes);
        foreach ($relations as $name => $related) {
            $model->setRelation($name, $related);
        }

        return $model;
    }

    private function alex(): User
    {
        return $this->make(User::class, ['id' => 0, 'name' => 'Alex Morgan', 'email' => 'alex@example.com']);
    }

    private function sam(): User
    {
        return $this->make(User::class, ['id' => 0, 'name' => 'Sam Rivera', 'email' => 'sam@example.com']);
    }

    private function contact(): TicketContact
    {
        return $this->make(TicketContact::class, ['uuid' => (string) Str::uuid(), 'name' => 'Jordan Lee', 'email' => 'jordan@example.com']);
    }

    private function guest(): PortalIdentity
    {
        return $this->make(PortalIdentity::class, ['uuid' => (string) Str::uuid(), 'name' => 'Jordan Lee', 'email' => 'jordan@example.com']);
    }

    private function workspace(): Workspace
    {
        return $this->make(Workspace::class, ['id' => 0, 'name' => 'Northwind Studio', 'slug' => 'northwind-studio']);
    }

    private function project(): Project
    {
        return $this->make(Project::class, ['id' => 0, 'name' => 'Website relaunch', 'slug' => 'website-relaunch', 'key' => 'WEB'], ['workspace' => $this->workspace()]);
    }

    private function item(): Item
    {
        $board = $this->make(Board::class, ['id' => 0, 'name' => 'Main board', 'slug' => 'main-board'], ['project' => $this->project()]);

        return $this->make(Item::class, ['id' => 42, 'key' => 'WEB-42', 'title' => 'Fix the login redirect'], ['board' => $board]);
    }

    private function helpdesk(): Helpdesk
    {
        return $this->make(Helpdesk::class, ['id' => 0, 'name' => 'Northwind Support', 'slug' => 'support'], ['project' => $this->project(), 'mailbox' => null]);
    }

    private function ticket(): Ticket
    {
        return $this->make(Ticket::class, ['id' => 12, 'uuid' => (string) Str::uuid(), 'key' => 'SUP-12', 'subject' => 'Password reset link does not work'], [
            'helpdesk' => $this->helpdesk(),
            'contact' => $this->contact(),
        ]);
    }

    private function ticketReply(string $body): TicketReply
    {
        return $this->make(TicketReply::class, ['body' => $body, 'is_internal_note' => false]);
    }

    private function lead(): Lead
    {
        $form = $this->make(LeadForm::class, [
            'name' => 'Project enquiry',
            'slug' => 'project-enquiry',
            'settings' => ['confirmation_email' => true],
            'definition' => ['steps' => [['fields' => [
                ['id' => 'name', 'key' => 'name', 'type' => 'text', 'label' => 'Name'],
                ['id' => 'budget', 'key' => 'budget', 'type' => 'text', 'label' => 'Budget'],
                ['id' => 'message', 'key' => 'message', 'type' => 'textarea', 'label' => 'Message'],
            ]]]],
        ], ['project' => $this->project()]);

        return $this->make(Lead::class, [
            'uuid' => (string) Str::uuid(),
            'number' => 7,
            'name' => 'Jordan Lee',
            'email' => 'jordan@example.com',
            'answers' => ['name' => 'Jordan Lee', 'budget' => '10–20k', 'message' => 'We need a new website by spring.'],
        ], ['form' => $form]);
    }

    private function portal(): ClientPortal
    {
        return $this->make(ClientPortal::class, ['id' => 0, 'title' => 'Northwind × Acme', 'slug' => 'northwind-acme'], ['project' => $this->project()]);
    }

    private function deliverable(): ClientDeliverable
    {
        return $this->make(ClientDeliverable::class, ['id' => 0, 'uuid' => (string) Str::uuid(), 'title' => 'Homepage design', 'version' => 2], ['portal' => $this->portal()]);
    }

    private function feedbackPortal(): FeedbackPortal
    {
        return $this->make(FeedbackPortal::class, ['id' => 0, 'title' => 'Northwind ideas', 'slug' => 'northwind-ideas'], ['project' => $this->project()]);
    }

    private function feedbackPost(): FeedbackPost
    {
        return $this->make(FeedbackPost::class, ['id' => 0, 'number' => 18, 'title' => 'Dark mode for the dashboard', 'body' => 'Working late, the bright dashboard hurts my eyes.'], ['portal' => $this->feedbackPortal()]);
    }

    private function page(): Page
    {
        return $this->make(Page::class, ['id' => 0, 'uuid' => (string) Str::uuid(), 'name' => 'Onboarding guide'], ['project' => $this->project()]);
    }

    private function review(): PageReview
    {
        return $this->make(PageReview::class, ['uuid' => (string) Str::uuid(), 'snapshot_name' => 'Onboarding guide', 'message' => 'Please check the new pricing section.'], ['page' => $this->page()]);
    }

    private function sprint(): Sprint
    {
        $board = $this->make(Board::class, ['id' => 0, 'name' => 'Main board', 'slug' => 'main-board'], ['project' => $this->project()]);

        return $this->make(Sprint::class, ['id' => 0, 'name' => 'Sprint 14', 'end_date' => now()->addDays(10)], ['board' => $board]);
    }

    private function task(): TaskListItem
    {
        $list = $this->make(TaskList::class, ['id' => 0, 'name' => 'Launch checklist', 'slug' => 'launch-checklist'], ['project' => $this->project()]);

        return $this->make(TaskListItem::class, ['id' => 0, 'title' => 'Book the venue'], ['taskList' => $list]);
    }

    private function billingMail(): BillingReportMail
    {
        $report = $this->make(BillingReport::class, ['id' => 0, 'workspace_id' => 0, 'status' => 'finalized', 'snapshot' => [
            'kind' => 'invoice',
            'number' => 'INV-2026-014',
            'period' => ['from' => '2026-10-01', 'to' => '2026-10-31'],
            'client' => ['name' => 'Acme GmbH', 'email' => 'accounts@example.com'],
            'issuer' => ['company_name' => 'Northwind Studio'],
        ]]);

        return new BillingReportMail($report, 'Invoice INV-2026-014', "Hello Acme team,\n\nplease find our October invoice attached.\n\nKind regards", 'sam@example.com', 'Sam Rivera');
    }
}
