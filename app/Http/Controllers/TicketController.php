<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketContact;
use App\Models\TicketReply;
use App\Models\TicketAttachment;
use App\Models\TicketFieldValue;
use App\Models\Helpdesk;
use App\Models\HelpdeskColumn;
use App\Models\HelpdeskField;
use App\Models\Project;
use App\Models\Workspace;
use App\Models\User;
use App\Models\ItemPriority;
use App\Notifications\TicketAssignedNotification;
use App\Notifications\TicketReplyNotification;
use App\Notifications\TicketCreatedNotification;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Str;

class TicketController extends Controller
{
    /**
     * Store a new ticket (internal creation).
     */
    public function store(Request $request, Workspace $workspace, Project $project, Helpdesk $helpdesk)
    {
        $this->authorize('createTickets', $helpdesk);

        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'description' => 'nullable|string',
            'column_id' => 'required|exists:helpdesk_columns,id',
            'priority_id' => 'nullable|exists:item_priorities,id',
            'assigned_to' => 'nullable|exists:users,id',
            'ticket_contact_id' => 'nullable|exists:ticket_contacts,id',
        ]);

        // Create ticket contact if email provided
        $ticketContactId = $validated['ticket_contact_id'] ?? null;
        if (!$ticketContactId && $request->has('contact_email')) {
            $contactEmail = $request->validate(['contact_email' => 'email|max:255'])['contact_email'];
            $contact = TicketContact::firstOrCreate(
                ['email' => $contactEmail],
                ['uuid' => (string) Str::uuid()]
            );
            $ticketContactId = $contact->id;
        }

        // Get next position in column
        $maxPosition = Ticket::where('column_id', $validated['column_id'])->max('position') ?? 0;

        $ticket = $helpdesk->tickets()->create([
            'subject' => $validated['subject'],
            'description' => $validated['description'] ?? null,
            'column_id' => $validated['column_id'],
            'priority_id' => $validated['priority_id'] ?? ItemPriority::where('is_default', true)->first()?->id,
            'assigned_to' => $validated['assigned_to'] ?? null,
            'ticket_contact_id' => $ticketContactId,
            'key' => $project->getNextTicketKey(),
            'position' => $maxPosition + 1,
        ]);

        // Process custom fields
        $fields = $helpdesk->fields()->where('is_required', true)->get();
        foreach ($fields as $field) {
            $value = $request->input('field_' . $field->name);
            if ($value !== null) {
                $ticket->fieldValues()->create([
                    'helpdesk_field_id' => $field->id,
                    'value' => $value,
                ]);
            }
        }

        // Notify assignee if assigned to someone else
        $newAssigneeId = $ticket->assigned_to;
        if ($newAssigneeId && $newAssigneeId !== $request->user()->id) {
            $assignee = User::find($newAssigneeId);
            if ($assignee) {
                $assignee->notify(new TicketAssignedNotification($ticket, $request->user()));
            }
        }

        // Notify contact if ticket created with contact
        if ($ticketContactId) {
            $contact = TicketContact::find($ticketContactId);
            if ($contact) {
                $contact->notify(new TicketCreatedNotification($ticket));
            }
        }

        return back()->with('success', 'Ticket created successfully.');
    }

    /**
     * Public ticket submission (from widget/portal).
     */
    public function storePublic(Request $request, Helpdesk $helpdesk)
    {
        $project = $helpdesk->project;

        // Public endpoint doesn't require authentication
        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'description' => 'nullable|string',
            'contact_email' => 'required|email|max:255',
            'contact_name' => 'nullable|string|max:255',
        ]);

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
        if (!$newColumn) {
            $newColumn = $helpdesk->columns()->orderBy('position')->first();
        }

        // Get next position in column
        $maxPosition = Ticket::where('column_id', $newColumn->id)->max('position') ?? 0;

        $ticket = $helpdesk->tickets()->create([
            'subject' => $validated['subject'],
            'description' => $validated['description'] ?? null,
            'column_id' => $newColumn->id,
            'ticket_contact_id' => $contact->id,
            'key' => $project->getNextTicketKey(),
            'position' => $maxPosition + 1,
        ]);

        // Process custom fields (public form fields — all fields, not just required)
        $fields = $helpdesk->fields()->orderBy('position')->get();
        foreach ($fields as $field) {
            $value = $request->input('field_' . $field->name);
            if ($value !== null && $value !== '') {
                $ticket->fieldValues()->create([
                    'helpdesk_field_id' => $field->id,
                    'value' => is_array($value) ? json_encode($value) : $value,
                ]);
            }
        }

        // Generate token once for contact (view ticket link in email + API response)
        $accessToken = $contact->generateAccessToken();

        // Notify contact (pass token so email link uses same token)
        $contact->notify(new TicketCreatedNotification($ticket, null, $accessToken));

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
            'message' => 'Ticket submitted successfully. Check your email for updates.',
        ]);
    }

    /**
     * Show a single ticket (full page view).
     */
    public function show(Request $request, Workspace $workspace, Project $project, Helpdesk $helpdesk, Ticket $ticket): Response
    {
        $this->authorize('view', $ticket);

        $ticket->load([
            'assignee:id,name,email,avatar',
            'priority',
            'contact',
            'column',
            'fieldValues.field',
            'replies' => function ($q) {
                $q->with(['user:id,name,email', 'contact:id,name,email'])
                    ->orderBy('created_at', 'desc');
            },
            'attachments' => function ($q) {
                $q->orderBy('created_at', 'desc');
            },
        ]);

        // Add URLs to attachments
        foreach ($ticket->attachments as $attachment) {
            $attachment->url = asset('storage/' . $attachment->path);
            $attachment->formatted_size = $attachment->getFormattedSizeAttribute();
        }

        $priorities = ItemPriority::forProject($project)->orderBy('order')->get();
        $projectUsers = $project->workspace->users()->get(['users.id', 'name', 'email', 'avatar']);
        $columns = $helpdesk->columns()->orderBy('position')->get();
        $fields = $helpdesk->fields()->orderBy('position')->get();

        return Inertia::render('Tickets/Show', [
            'workspace' => $workspace,
            'project' => $project,
            'helpdesk' => $helpdesk,
            'ticket' => $ticket,
            'priorities' => $priorities,
            'projectUsers' => $projectUsers,
            'columns' => $columns,
            'fields' => $fields,
        ]);
    }

    /**
     * Update ticket position (drag and drop).
     */
    public function move(Request $request, Workspace $workspace, Project $project, Helpdesk $helpdesk, Ticket $ticket)
    {
        $this->authorize('update', $ticket);

        $validated = $request->validate([
            'column_id' => 'required|exists:helpdesk_columns,id',
            'position'  => 'required|integer|min:0',
        ]);

        $oldColumn = $ticket->column;
        $newColumn = HelpdeskColumn::findOrFail($validated['column_id']);

        $ticket->moveToColumn($newColumn, $validated['position']);

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
            'assigned_to' => 'nullable|exists:users,id',
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

        // Notify contact if reply is not internal
        if (!$reply->is_internal_note && $ticket->contact) {
            $ticket->contact->notify(new TicketReplyNotification($ticket, $reply, $request->user()));
        }

        // Notify other agents watching the ticket
        $watchers = $ticket->watchers()->where('user_id', '!=', $request->user()->id)->get();
        foreach ($watchers as $watcher) {
            $watcher->notify(new TicketReplyNotification($ticket, $reply, $request->user()));
        }

        return back()->with('success', 'Reply added successfully.');
    }

    /**
     * Public reply submission (from contact portal).
     */
    public function storePublicReply(Request $request, Helpdesk $helpdesk, Ticket $ticket)
    {
        $validated = $request->validate([
            'body' => 'required|string',
            'access_token' => 'required|string',
        ]);

        // Verify access token
        $contact = $ticket->contact;
        if (! $contact || ! $contact->verifyAccessToken($validated['access_token'])) {
            abort(403, 'Invalid access token');
        }

        $reply = $ticket->replies()->create([
            'ticket_contact_id' => $contact->id,
            'body' => $validated['body'],
            'is_internal_note' => false,
        ]);

        // Notify agents watching the ticket
        $watchers = $ticket->watchers()->get();
        foreach ($watchers as $watcher) {
            $watcher->notify(new TicketReplyNotification($ticket, $reply, $contact));
        }

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
        $filename = uniqid() . '_' . $file->getClientOriginalName();
        $path = $file->storeAs('ticket-attachments/' . $ticket->id, $filename, 'public');

        $attachment = $ticket->attachments()->create([
            'filename' => $filename,
            'original_filename' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'uploaded_by_contact' => false,
        ]);

        // Add URL for response
        $attachment->url = asset('storage/' . $path);

        return back()->with('success', 'Attachment uploaded successfully.');
    }

    /**
     * Public attachment upload (from contact portal).
     */
    public function storePublicAttachment(Request $request, Helpdesk $helpdesk, Ticket $ticket)
    {
        $validated = $request->validate([
            'file' => 'required|file|max:10240',
            'access_token' => 'required|string',
        ]);

        // Verify access token
        $contact = $ticket->contact;
        if (! $contact || ! $contact->verifyAccessToken($validated['access_token'])) {
            abort(403, 'Invalid access token');
        }

        $file = $request->file('file');
        $filename = uniqid() . '_' . $file->getClientOriginalName();
        $path = $file->storeAs('ticket-attachments/' . $ticket->id, $filename, 'public');

        $attachment = $ticket->attachments()->create([
            'filename' => $filename,
            'original_filename' => $file->getClientOriginalName(),
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
                'url' => asset('storage/' . $path),
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
            'user_id' => 'required|exists:users,id',
            'watch' => 'required|boolean',
        ]);

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
        $token = $request->query('token');
        $contact = $ticket->contact;

        if (! $contact || ! $token || ! $contact->verifyAccessToken($token)) {
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
        $contact = $ticket->contact;

        if (! $contact || ! $accessToken || ! $contact->verifyAccessToken($accessToken)) {
            abort(403, 'Invalid access token');
        }

        $ticket->load([
            'column',
            'helpdesk:id,uuid',
            'replies' => function ($q) {
                $q->where('is_internal_note', false)
                  ->with(['contact:id,name,email'])
                  ->orderBy('created_at', 'asc');
            },
            'attachments' => function ($q) {
                $q->where('uploaded_by_contact', false) // Only show attachments from agents
                  ->orderBy('created_at', 'desc');
            },
        ]);

        // Add URLs to attachments
        foreach ($ticket->attachments as $attachment) {
            $attachment->url = asset('storage/' . $attachment->path);
        }

        return Inertia::render('Tickets/PublicShow', [
            'ticket' => $ticket,
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
        $accessToken = $request->query('token');
        $contact = $ticket->contact;

        if (! $contact || ! $accessToken || ! $contact->verifyAccessToken($accessToken)) {
            abort(403, 'Invalid access token');
        }

        $replies = $ticket->replies()
            ->where('is_internal_note', false)
            ->with(['contact:id,name,email'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'replies' => $replies,
            'access_token' => $contact->generateAccessToken($ticket),
        ]);
    }
}