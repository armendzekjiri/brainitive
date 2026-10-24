<?php

namespace App\Http\Controllers;

use App\Models\Helpdesk;
use App\Models\HelpdeskColumn;
use App\Models\ItemPriority;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketContact;
use App\Models\TicketReply;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\TicketAssignedNotification;
use App\Notifications\TicketCreatedNotification;
use App\Notifications\TicketReplyNotification;
use App\Notifications\TicketResolvedNotification;
use App\Notifications\TicketStatusChangedNotification;
use App\Rules\NotExecutableUpload;
use App\Rules\ProjectMember;
use App\Support\Events\DomainEvents;
use App\Support\Events\EventPayloads;
use App\Support\Helpdesks\HelpdeskForm;
use App\Support\Helpdesks\Mail\TicketMailer;
use App\Support\Uploads;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TicketController extends Controller
{
    /**
     * An agent creates a ticket for a customer (call, email…): the customer's email is
     * required, and the support form's own required fields apply here too.
     */
    public function store(Request $request, Workspace $workspace, Project $project, Helpdesk $helpdesk)
    {
        $this->authorize('createTickets', $helpdesk);

        $settings = $helpdesk->settings ?? [];
        $nameRequired = empty($settings['default_field_contact_name']['hidden']) && ! empty($settings['default_field_contact_name']['is_required']);
        $messageRequired = empty($settings['default_field_description']['hidden']) && ! empty($settings['default_field_description']['is_required']);
        $fields = $helpdesk->fields()->orderBy('position')->get();

        $rules = [
            'subject' => 'required|string|max:255',
            'description' => [$messageRequired ? 'required' : 'nullable', 'string', 'max:50000'],
            'contact_email' => 'required|email|max:255',
            'contact_name' => [$nameRequired ? 'required' : 'nullable', 'string', 'max:255'],
            'column_id' => ['required', Rule::exists('helpdesk_columns', 'id')->where('helpdesk_id', $helpdesk->id)],
            'priority_id' => 'nullable|exists:item_priorities,id',
            // Only people in this workspace — any user id would email a stranger the ticket.
            'assigned_to' => ['nullable', Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->id)],
            'notify_contact' => 'nullable|boolean',
        ];
        $attributes = [];
        foreach ($fields as $field) {
            if ($field->type === 'file') {
                continue;
            }
            $key = 'field_'.$field->name;
            $attributes[$key] = $field->label;
            $rules[$key] = match ($field->type) {
                'checkboxes' => [$field->is_required ? 'required' : 'nullable', 'array'],
                'checkbox' => [$field->is_required ? 'accepted' : 'nullable', 'boolean'],
                'number' => [$field->is_required ? 'required' : 'nullable', 'numeric'],
                'date' => [$field->is_required ? 'required' : 'nullable', 'date'],
                'select', 'radio' => [$field->is_required ? 'required' : 'nullable', Rule::in($field->options ?? [])],
                default => [$field->is_required ? 'required' : 'nullable', 'string', 'max:10000'],
            };
        }
        $validated = $request->validate($rules, [], $attributes);

        $contact = TicketContact::firstOrCreate(['email' => strtolower($validated['contact_email'])], ['uuid' => (string) Str::uuid()]);
        if (! empty($validated['contact_name']) && ! $contact->name) {
            $contact->update(['name' => $validated['contact_name']]);
        }

        $ticket = DB::transaction(function () use ($helpdesk, $project, $validated, $contact, $fields, $request) {
            $maxPosition = Ticket::where('column_id', $validated['column_id'])->max('position') ?? 0;

            $ticket = $helpdesk->tickets()->create([
                'subject' => $validated['subject'],
                'description' => $validated['description'] ?? null,
                'column_id' => $validated['column_id'],
                'priority_id' => $validated['priority_id'] ?? ItemPriority::where('is_default', true)->first()?->id,
                'assigned_to' => $validated['assigned_to'] ?? null,
                'ticket_contact_id' => $contact->id,
                'key' => $project->getNextTicketKey(),
                'position' => $maxPosition + 1,
            ]);

            foreach ($fields as $field) {
                $value = $request->input('field_'.$field->name);
                if ($field->type === 'file' || $value === null || $value === '' || $value === []) {
                    continue;
                }
                $ticket->fieldValues()->create([
                    'helpdesk_field_id' => $field->id,
                    'value' => is_array($value) ? json_encode(array_values($value)) : (is_bool($value) ? ($value ? '1' : '0') : (string) $value),
                ]);
            }

            return $ticket;
        });

        if ($ticket->assigned_to && $ticket->assigned_to !== $request->user()->id) {
            User::find($ticket->assigned_to)?->notify(new TicketAssignedNotification($ticket, $request->user()));
        }

        DomainEvents::emit($workspace, 'ticket.created', fn () => ['ticket' => EventPayloads::ticket($ticket), 'source' => 'agent']); // webhooks (BRAI-104)

        if ($request->boolean('notify_contact', true)) {
            $contact->notify(new TicketCreatedNotification($ticket));
        }

        return back()->with('success', "Ticket {$ticket->key} created.");
    }

    /**
     * Public ticket submission (from widget/portal).
     */
    public function storePublic(Request $request, Helpdesk $helpdesk)
    {
        $project = $helpdesk->project;

        // Public endpoint doesn't require authentication. Default fields and custom fields are
        // checked here as configured — the browser checks are only a convenience (BRAI-92).
        $helpdesk->load(['fields' => fn ($q) => $q->orderBy('position')]);
        HelpdeskForm::prepare($request, $helpdesk);
        $validated = $request->validate(HelpdeskForm::publicRules($helpdesk));
        $subject = trim((string) ($validated['subject'] ?? ''));
        if ($subject === '') {
            $subject = HelpdeskForm::autoSubject($helpdesk, $validated['contact_name'] ?? null, $validated['contact_email']);
        }

        // Find or create contact
        $contact = TicketContact::firstOrCreate(
            ['email' => $validated['contact_email']],
            [
                'uuid' => (string) Str::uuid(),
                'name' => $validated['contact_name'] ?? null,
            ]
        );

        // Get default "New" column
        $newColumn = $helpdesk->columns()->where('slug', 'new')->first();
        if (! $newColumn) {
            $newColumn = $helpdesk->columns()->orderBy('position')->first();
        }

        // Get next position in column
        $maxPosition = Ticket::where('column_id', $newColumn->id)->max('position') ?? 0;

        $ticket = $helpdesk->tickets()->create([
            'subject' => $subject,
            'description' => $validated['description'] ?? null,
            'column_id' => $newColumn->id,
            'ticket_contact_id' => $contact->id,
            'key' => $project->getNextTicketKey(),
            'position' => $maxPosition + 1,
        ]);

        // Process custom fields (validated above; file fields are uploads, not values)
        foreach ($helpdesk->fields as $field) {
            $value = $validated['field_'.$field->name] ?? null;
            if ($value === null || $value === '' || $value === [] || HelpdeskForm::type($field) === 'file') {
                continue;
            }
            if (HelpdeskForm::type($field) === 'checkbox') {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
            }
            $ticket->fieldValues()->create([
                'helpdesk_field_id' => $field->id,
                'value' => is_array($value) ? json_encode(array_values($value)) : (string) $value,
            ]);
        }

        // Token for this ticket only — never the contact's other tickets (anyone can submit
        // with any email address).
        $accessToken = $ticket->publicAccessToken();

        DomainEvents::emit($project->workspace_id, 'ticket.created', fn () => ['ticket' => EventPayloads::ticket($ticket), 'source' => 'portal']); // webhooks (BRAI-104)

        // The public form must not become a mail cannon: a few confirmations per address and hour
        if (RateLimiter::attempt('ticket-confirmation:'.$helpdesk->id.':'.sha1(strtolower($contact->email)), 3, fn () => true, 3600)) {
            $contact->notify(new TicketCreatedNotification($ticket));
        }

        // Notify helpdesk admins (project admins)
        $admins = $project->admins()->get();
        foreach ($admins as $admin) {
            $admin->notify(new TicketCreatedNotification($ticket, $contact));
        }

        return response()->json([
            'success' => true,
            'ticket_key' => $ticket->key,
            'ticket_uuid' => $ticket->uuid,
            'access_token' => $accessToken,
            'message' => HelpdeskForm::texts($helpdesk)['success_message'],
        ]);
    }

    /**
     * Ticket link (emails, notifications): the helpdesk board with this ticket's panel open.
     * There is no separate ticket page; it used to render a missing `Tickets/Show` (500).
     */
    public function show(Request $request, Workspace $workspace, Project $project, Helpdesk $helpdesk, Ticket $ticket): Response
    {
        $this->authorize('view', $ticket);

        return app(HelpdeskController::class)
            ->show($request, $workspace, $project, $helpdesk)
            ->with('openTicketId', $ticket->id);
    }

    /**
     * Update ticket position (drag and drop).
     */
    public function move(Request $request, Workspace $workspace, Project $project, Helpdesk $helpdesk, Ticket $ticket)
    {
        $this->authorize('update', $ticket);

        $validated = $request->validate([
            'column_id' => ['required', Rule::exists('helpdesk_columns', 'id')->where('helpdesk_id', $helpdesk->id)],
            'position' => 'required|integer|min:0',
            // Resolving: closing message (saved as a reply) and whether to email the customer.
            'resolution_message' => 'nullable|string|max:20000',
            'notify_contact' => 'nullable|boolean',
        ]);

        $wasDone = (bool) $ticket->column?->is_done_column;
        $oldColumn = $ticket->column;
        $newColumn = HelpdeskColumn::findOrFail($validated['column_id']);

        $ticket->moveToColumn($newColumn, $validated['position']);

        // BRAI-103: watchers (except whoever moved it) hear about a status change
        if ($oldColumn?->id !== $newColumn->id) {
            foreach ($ticket->watchers()->where('users.id', '!=', $request->user()->id)->get() as $watcher) {
                $watcher->notify(new TicketStatusChangedNotification($ticket, $request->user(), $oldColumn?->name, $newColumn->name));
            }
        }

        if ($newColumn->is_done_column && ! $wasDone) {
            $message = trim((string) ($validated['resolution_message'] ?? ''));
            if ($message !== '') {
                $ticket->replies()->create([
                    'user_id' => $request->user()->id,
                    'body' => collect(preg_split('/\n{2,}/', $message))
                        ->map(fn (string $p) => '<p>'.nl2br(e(trim($p))).'</p>')
                        ->implode(''),
                    'is_internal_note' => false,
                ]);
            }
            if ($request->boolean('notify_contact') && $ticket->contact) {
                $ticket->contact->notify(new TicketResolvedNotification($ticket, $message ?: null));
            }

            DomainEvents::emit($workspace, 'ticket.resolved', fn () => ['ticket' => EventPayloads::ticket($ticket), 'resolution' => $message !== '' ? $message : null]); // webhooks (BRAI-104)
        }

        return back();
    }

    /**
     * Update a ticket.
     */
    public function update(Request $request, Workspace $workspace, Project $project, Helpdesk $helpdesk, Ticket $ticket)
    {
        $this->authorize('update', $ticket);

        $validated = $request->validate([
            'subject' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'priority_id' => 'nullable|exists:item_priorities,id',
            'assigned_to' => ['nullable', Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->id)],
        ]);

        $oldAssigneeId = $ticket->assigned_to;

        $ticket->update($validated);

        // Notify new assignee if changed
        $newAssigneeId = $ticket->assigned_to;
        if (
            $newAssigneeId
            && $newAssigneeId !== $oldAssigneeId
            && $newAssigneeId !== $request->user()->id
        ) {
            $assignee = User::find($newAssigneeId);
            if ($assignee) {
                $assignee->notify(new TicketAssignedNotification($ticket, $request->user()));
            }
        }

        return back()->with('success', 'Ticket updated successfully.');
    }

    /**
     * Delete a ticket.
     */
    public function destroy(Workspace $workspace, Project $project, Helpdesk $helpdesk, Ticket $ticket)
    {
        $this->authorize('delete', $ticket);

        $ticket->delete();

        return back()->with('success', 'Ticket deleted successfully.');
    }

    /**
     * Store a new reply on a ticket.
     */
    public function storeReply(Request $request, Workspace $workspace, Project $project, Helpdesk $helpdesk, Ticket $ticket)
    {
        $this->authorize('update', $ticket);

        $validated = $request->validate([
            'body' => 'required|string',
            'is_internal_note' => 'boolean',
        ]);

        $reply = $ticket->replies()->create([
            'user_id' => $request->user()->id,
            'body' => $validated['body'],
            'is_internal_note' => $validated['is_internal_note'] ?? false,
        ]);

        // Notify contact if reply is not internal. A helpdesk with a connected mailbox emails the
        // reply itself, threaded (BRAI-79) — then the notification would be a second mail.
        if (! $reply->is_internal_note && $ticket->contact && ! TicketMailer::queueReply($ticket, $reply)) {
            $ticket->contact->notify(new TicketReplyNotification($ticket, $reply, $request->user()));
        }

        // Notify other agents watching the ticket
        $watchers = $ticket->watchers()->where('user_id', '!=', $request->user()->id)->get();
        foreach ($watchers as $watcher) {
            $watcher->notify(new TicketReplyNotification($ticket, $reply, $request->user()));
        }

        DomainEvents::emit($workspace, 'ticket.replied', fn () => EventPayloads::ticketReply($ticket, $reply)); // webhooks (BRAI-104)

        return back()->with('success', 'Reply added successfully.');
    }

    /**
     * Public reply submission (from contact portal).
     * Accepts JSON (body, access_token) or multipart (body, access_token, optional file[]).
     */
    public function storePublicReply(Request $request, Helpdesk $helpdesk, Ticket $ticket)
    {
        $validated = $request->validate([
            'body' => 'required|string|max:50000',
            'access_token' => 'required|string',
            'file' => 'nullable|array|max:5',
            'file.*' => ['file', 'max:10240', new NotExecutableUpload],
        ]);
        if ($request->hasFile('file') && ! empty(($helpdesk->settings ?? [])['default_field_attachments']['hidden'])) {
            abort(403, 'This helpdesk does not accept attachments.');
        }
        if ($request->hasFile('file') && $ticket->attachments()->where('uploaded_by_contact', true)->count() + count((array) $request->file('file')) > 20) {
            abort(422, 'This request already has the maximum number of attachments.');
        }

        $contact = $ticket->contact;
        if (! $contact || ! $ticket->verifyPublicAccessToken($validated['access_token'])) {
            abort(403, 'Invalid access token');
        }

        $reply = $ticket->replies()->create([
            'ticket_contact_id' => $contact->id,
            'body' => $validated['body'],
            'is_internal_note' => false,
        ]);

        $files = $request->file('file');
        if (is_array($files)) {
            foreach ($files as $file) {
                $filename = Uploads::name($file);
                $path = $file->storeAs('ticket-attachments/'.$ticket->id, $filename, 'public');
                $reply->attachments()->create([
                    'ticket_id' => $ticket->id,
                    'filename' => $filename,
                    'original_filename' => Uploads::displayName($file),
                    'path' => $path,
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'uploaded_by_contact' => true,
                ]);
            }
        } elseif ($files) {
            $filename = Uploads::name($files);
            $path = $files->storeAs('ticket-attachments/'.$ticket->id, $filename, 'public');
            $reply->attachments()->create([
                'ticket_id' => $ticket->id,
                'filename' => $filename,
                'original_filename' => Uploads::displayName($files),
                'path' => $path,
                'mime_type' => $files->getMimeType(),
                'size' => $files->getSize(),
                'uploaded_by_contact' => true,
            ]);
        }

        // Notify agents watching the ticket
        $watchers = $ticket->watchers()->get();
        foreach ($watchers as $watcher) {
            $watcher->notify(new TicketReplyNotification($ticket, $reply, $contact));
        }

        DomainEvents::emit(EventPayloads::ticketWorkspaceId($ticket), 'ticket.replied', fn () => EventPayloads::ticketReply($ticket, $reply)); // webhooks (BRAI-104)

        return response()->json(['success' => true, 'message' => 'Reply submitted successfully.']);
    }

    /**
     * Store a new attachment for a ticket.
     */
    public function storeAttachment(Request $request, Workspace $workspace, Project $project, Helpdesk $helpdesk, Ticket $ticket)
    {
        $this->authorize('update', $ticket);

        $validated = $request->validate([
            'file' => 'required|file|max:10240', // 10MB max
        ]);

        $file = $request->file('file');
        $filename = Uploads::name($file);
        $path = $file->storeAs('ticket-attachments/'.$ticket->id, $filename, 'public');

        $attachment = $ticket->attachments()->create([
            'filename' => $filename,
            'original_filename' => Uploads::displayName($file),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'uploaded_by_contact' => false,
        ]);

        // Add URL for response
        $attachment->url = asset('storage/'.$path);

        return back()->with('success', 'Attachment uploaded successfully.');
    }

    /**
     * Public attachment upload (from contact portal).
     */
    public function storePublicAttachment(Request $request, Helpdesk $helpdesk, Ticket $ticket)
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:10240', new NotExecutableUpload],
            'access_token' => 'required|string',
        ]);

        if (! $ticket->verifyPublicAccessToken($validated['access_token'])) {
            abort(403, 'Invalid access token');
        }
        if (! empty(($helpdesk->settings ?? [])['default_field_attachments']['hidden'])) {
            abort(403, 'This helpdesk does not accept attachments.');
        }
        if ($ticket->attachments()->where('uploaded_by_contact', true)->count() >= 20) {
            abort(422, 'This request already has the maximum number of attachments.');
        }

        $file = $request->file('file');
        $filename = Uploads::name($file);
        $path = $file->storeAs('ticket-attachments/'.$ticket->id, $filename, 'public');

        $attachment = $ticket->attachments()->create([
            'filename' => $filename,
            'original_filename' => Uploads::displayName($file),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'uploaded_by_contact' => true,
        ]);

        return response()->json([
            'success' => true,
            'attachment' => [
                'id' => $attachment->id,
                'filename' => $attachment->original_filename,
                'url' => asset('storage/'.$path),
            ],
        ]);
    }

    /**
     * Delete an attachment.
     */
    public function destroyAttachment(Request $request, Workspace $workspace, Project $project, Helpdesk $helpdesk, Ticket $ticket, TicketAttachment $attachment)
    {
        $this->authorize('update', $ticket);

        // Ensure attachment belongs to the ticket
        if ($attachment->ticket_id !== $ticket->id) {
            abort(404);
        }

        // Delete file from storage
        \Storage::disk('public')->delete($attachment->path);

        $attachment->delete();

        return back()->with('success', 'Attachment deleted successfully.');
    }

    /**
     * Toggle watch status on a ticket.
     */
    public function toggleWatch(Request $request, Workspace $workspace, Project $project, Helpdesk $helpdesk, Ticket $ticket)
    {
        $this->authorize('view', $ticket);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'watch' => 'required|boolean',
        ]);
        // anyone who can see the ticket may (un)watch it themselves; managing others takes edit
        // rights, and only project members can be added (watchers get reply excerpts)
        if ((int) $validated['user_id'] !== $request->user()->id) {
            $this->authorize('update', $ticket);
        }
        if ($validated['watch']) {
            $request->validate(['user_id' => [new ProjectMember($project)]]);
        }

        $user = User::findOrFail($validated['user_id']);

        if ($validated['watch']) {
            $ticket->addWatcher($user);
        } else {
            $ticket->removeWatcher($user);
        }

        return back();
    }

    /**
     * Update custom field values.
     */
    public function updateFieldValues(Request $request, Workspace $workspace, Project $project, Helpdesk $helpdesk, Ticket $ticket)
    {
        $this->authorize('update', $ticket);

        $fields = $helpdesk->fields()->get();
        $updates = [];

        foreach ($fields as $field) {
            $value = $request->input($field->name);
            if ($value !== null) {
                $existing = $ticket->fieldValues()->where('helpdesk_field_id', $field->id)->first();
                if ($existing) {
                    $existing->update(['value' => $value]);
                } else {
                    $ticket->fieldValues()->create([
                        'helpdesk_field_id' => $field->id,
                        'value' => $value,
                    ]);
                }
            }
        }

        return back()->with('success', 'Fields updated successfully.');
    }

    /**
     * Get ticket + replies as JSON for the embeddable widget (requires ?token=).
     */
    public function getPublicTicketJson(Request $request, Ticket $ticket)
    {
        if (! $ticket->verifyPublicAccessToken($request->query('token'))) {
            return response()->json(['error' => 'Invalid or expired access token'], 403);
        }

        $ticket->load([
            'replies' => function ($q) {
                $q->where('is_internal_note', false)
                    ->orderBy('created_at')
                    ->with('contact:id,name,email');
            },
        ]);

        $replies = $ticket->replies->map(fn ($r) => [
            'id' => $r->id,
            'body' => $r->body,
            'created_at' => $r->created_at->toIso8601String(),
            'from_contact' => (bool) $r->ticket_contact_id,
        ]);

        return response()->json([
            'ticket' => [
                'uuid' => $ticket->uuid,
                'key' => $ticket->key,
                'subject' => $ticket->subject,
                'description' => $ticket->description,
            ],
            'replies' => $replies,
        ]);
    }

    /**
     * Show a ticket publicly (for contact portal).
     */
    public function showPublic(Request $request, Ticket $ticket)
    {
        $accessToken = $request->query('token');
        if (! $ticket->verifyPublicAccessToken($accessToken)) {
            abort(403, 'Invalid access token');
        }

        $ticket->load([
            'column',
            'helpdesk:id,uuid,settings',
            'replies' => function ($q) {
                $q->where('is_internal_note', false)
                    ->with(['contact:id,name,email', 'attachments'])
                    ->orderBy('created_at', 'asc');
            },
            'attachments' => function ($q) {
                $q->where('uploaded_by_contact', false) // Only show attachments from agents
                    ->orderBy('created_at', 'desc');
            },
        ]);

        // Add URLs to attachments (ticket-level and reply-level)
        foreach ($ticket->attachments as $attachment) {
            $attachment->url = asset('storage/'.$attachment->path);
        }
        foreach ($ticket->replies as $reply) {
            foreach ($reply->attachments as $att) {
                $att->url = asset('storage/'.$att->path);
            }
        }

        return Inertia::render('Tickets/PublicShow', [
            'attachments_enabled' => empty(($ticket->helpdesk?->settings ?? [])['default_field_attachments']['hidden']),
            // only what the visitor's page shows — no internal ids, metadata or assignee
            'ticket' => [
                'uuid' => $ticket->uuid,
                'key' => $ticket->key,
                'subject' => $ticket->subject,
                'description' => $ticket->description,
                'created_at' => $ticket->created_at?->toIso8601String(),
                'column' => $ticket->column ? ['name' => $ticket->column->name, 'color' => $ticket->column->color, 'is_done_column' => (bool) $ticket->column->is_done_column] : null,
                'helpdesk' => ['uuid' => $ticket->helpdesk?->uuid],
                'replies' => $ticket->replies->map(fn ($reply) => self::publicReply($reply))->values(),
            ],
            'access_token' => $accessToken,
            'app_name' => config('app.name'),
            'app_logo_url' => asset('images/logo.png'),
        ]);
    }

    /**
     * Get public replies for a ticket (JSON endpoint for portal).
     */
    public function publicReplies(Request $request, Ticket $ticket)
    {
        if (! $ticket->verifyPublicAccessToken($request->query('token'))) {
            abort(403, 'Invalid access token');
        }

        $replies = $ticket->replies()
            ->where('is_internal_note', false)
            ->with(['contact:id,name,email'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'replies' => $replies->map(fn ($reply) => self::publicReply($reply))->values(),
            'access_token' => $ticket->publicAccessToken(),
        ]);
    }

    /** A reply as the visitor sees it (portal page + JSON). */
    private static function publicReply(TicketReply $reply): array
    {
        return [
            'id' => $reply->id,
            'body' => $reply->body,
            'created_at' => $reply->created_at?->toIso8601String(),
            'ticket_contact_id' => $reply->ticket_contact_id,
            'contact' => $reply->contact ? ['name' => $reply->contact->name, 'email' => $reply->contact->email] : null,
            'attachments' => $reply->relationLoaded('attachments')
                ? $reply->attachments->map(fn ($a) => ['id' => $a->id, 'original_filename' => $a->original_filename, 'url' => asset('storage/'.$a->path)])->values()->all()
                : [],
        ];
    }
}
