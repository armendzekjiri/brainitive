<?php

namespace App\Http\Controllers;

use App\Models\Helpdesk;
use App\Models\HelpdeskColumn;
use App\Models\HelpdeskField;
use App\Models\ItemPriority;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class HelpdeskController extends Controller
{
    /**
     * Store a new helpdesk.
     */
    public function store(Request $request, Workspace $workspace, Project $project)
    {
        $this->authorize('create', [Helpdesk::class, $project]);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $slug = Str::slug($validated['name']);
        $originalSlug = $slug;
        $counter = 1;

        while ($project->helpdesks()->where('slug', $slug)->exists()) {
            $slug = $originalSlug.'-'.$counter++;
        }

        // Helpdesk model's created event creates default columns (Open, In Progress, Resolved)
        $helpdesk = $project->helpdesks()->create([
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('helpdesks.show', [$workspace->slug, $project->slug, $helpdesk->slug])
            ->with('success', 'Helpdesk created successfully.');
    }

    /**
     * Display the specified helpdesk (Kanban view).
     */
    public function show(Request $request, Workspace $workspace, Project $project, Helpdesk $helpdesk): Response
    {
        $this->authorize('view', $helpdesk);

        $helpdesk->load([
            'columns' => function ($query) {
                $query->orderBy('position')->with([
                    'tickets' => function ($q) {
                        $q->orderBy('position')
                            ->with([
                                'assignee:id,name,email,avatar',
                                'priority',
                                'contact:id,name,email',
                                'fieldValues.field',
                                'attachments' => function ($a) {
                                    $a->orderBy('created_at', 'desc');
                                },
                                'replies' => function ($r) {
                                    $r->with(['user:id,name,email', 'contact:id,name,email'])
                                        ->orderBy('created_at', 'desc')
                                        ->limit(5);
                                },
                            ]);
                    },
                ]);
            },
            'fields' => function ($q) {
                $q->orderBy('position');
            },
        ]);

        // Add URLs to attachments
        foreach ($helpdesk->columns as $column) {
            foreach ($column->tickets as $ticket) {
                foreach ($ticket->attachments as $attachment) {
                    $attachment->url = asset('storage/'.$attachment->path);
                    $attachment->formatted_size = $attachment->getFormattedSizeAttribute();
                }
            }
        }

        $priorities = ItemPriority::forProject($project)->orderBy('order')->get();
        $projectUsers = $project->workspace->users()->get(['users.id', 'name', 'email', 'avatar']);

        return Inertia::render('Helpdesks/Show', [
            'workspace' => $workspace,
            'project' => $project,
            'helpdesk' => $helpdesk,
            'priorities' => $priorities,
            'projectUsers' => $projectUsers,
        ]);
    }

    /**
     * Show the helpdesk settings page.
     */
    public function edit(Request $request, Workspace $workspace, Project $project, Helpdesk $helpdesk): Response
    {
        $this->authorize('update', $helpdesk);

        $helpdesk->load([
            'columns' => function ($query) {
                $query->orderBy('position')->withCount('tickets');
            },
            'fields' => function ($q) {
                $q->orderBy('position');
            },
        ]);

        return Inertia::render('Helpdesks/Edit', [
            'workspace' => $workspace,
            'project' => $project,
            'helpdesk' => $helpdesk,
            'appUrl' => $request->getSchemeAndHttpHost(),
        ]);
    }

    /**
     * Update helpdesk settings.
     */
    public function update(Request $request, Workspace $workspace, Project $project, Helpdesk $helpdesk)
    {
        $this->authorize('update', $helpdesk);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'settings' => 'nullable|array',
            'public_slug' => ['nullable', 'string', 'min:3', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('helpdesks', 'public_slug')->ignore($helpdesk->id)],
        ], [
            'public_slug.regex' => 'Use lowercase letters, numbers and single dashes, e.g. acme-support.',
            'public_slug.unique' => 'This address is already taken.',
        ]);

        if (isset($validated['settings'])) {
            $validated['settings'] = array_merge($helpdesk->settings ?? [], $validated['settings']);
        }

        $helpdesk->update($validated);

        return redirect()->back()->with('success', 'Helpdesk updated successfully.');
    }

    /**
     * Delete a helpdesk.
     */
    public function destroy(Workspace $workspace, Project $project, Helpdesk $helpdesk)
    {
        $this->authorize('delete', $helpdesk);

        $helpdesk->delete();

        return redirect()->route('projects.show', [$workspace->slug, $project->slug])
            ->with('success', 'Helpdesk deleted successfully.');
    }

    /**
     * Store a new column.
     */
    public function storeColumn(Request $request, Workspace $workspace, Project $project, Helpdesk $helpdesk)
    {
        $this->authorize('update', $helpdesk);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'color' => 'nullable|string|max:20',
            'is_done_column' => 'nullable|boolean',
        ]);

        $slug = Str::slug($validated['name']);
        $maxPosition = $helpdesk->columns()->max('position') ?? 0;

        $helpdesk->columns()->create([
            'name' => $validated['name'],
            'slug' => $slug,
            'color' => $validated['color'] ?? null,
            'is_done_column' => $validated['is_done_column'] ?? false,
            'position' => $maxPosition + 1,
        ]);

        return redirect()->back()->with('success', 'Column created successfully.');
    }

    /**
     * Update a column.
     */
    public function updateColumn(Request $request, Workspace $workspace, Project $project, Helpdesk $helpdesk, HelpdeskColumn $column)
    {
        $this->authorize('update', $helpdesk);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'color' => 'nullable|string|max:20',
            'wip_limit' => 'nullable|integer|min:0',
            'is_done_column' => 'nullable|boolean',
        ]);

        $column->update($validated);

        return redirect()->back()->with('success', 'Column updated successfully.');
    }

    /**
     * Reorder columns.
     */
    public function reorderColumns(Request $request, Workspace $workspace, Project $project, Helpdesk $helpdesk)
    {
        $this->authorize('update', $helpdesk);

        $validated = $request->validate([
            'columns' => 'required|array',
            'columns.*.id' => 'required|integer|exists:helpdesk_columns,id',
            'columns.*.position' => 'required|integer|min:0',
        ]);

        foreach ($validated['columns'] as $columnData) {
            HelpdeskColumn::where('id', $columnData['id'])
                ->where('helpdesk_id', $helpdesk->id)
                ->update(['position' => $columnData['position']]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Reorder tickets within a helpdesk.
     */
    public function reorderTickets(Request $request, Workspace $workspace, Project $project, Helpdesk $helpdesk)
    {
        $this->authorize('update', $helpdesk);

        $validated = $request->validate([
            'tickets' => 'required|array',
            'tickets.*.id' => 'required|integer|exists:tickets,id',
            'tickets.*.position' => 'required|integer|min:0',
        ]);

        foreach ($validated['tickets'] as $ticketData) {
            Ticket::where('id', $ticketData['id'])
                ->where('helpdesk_id', $helpdesk->id)
                ->update(['position' => $ticketData['position']]);
        }

        return back()->with('success', 'Tickets reordered successfully.');
    }

    /**
     * Delete a column.
     */
    public function destroyColumn(Workspace $workspace, Project $project, Helpdesk $helpdesk, HelpdeskColumn $column)
    {
        $this->authorize('update', $helpdesk);

        // Check if column has tickets
        if ($column->tickets()->count() > 0) {
            return redirect()->back()->withErrors(['column' => 'Cannot delete column with tickets. Move or delete tickets first.']);
        }

        $column->delete();

        return redirect()->back()->with('success', 'Column deleted successfully.');
    }

    /**
     * Store a new custom field.
     */
    public function storeField(Request $request, Workspace $workspace, Project $project, Helpdesk $helpdesk)
    {
        $this->authorize('update', $helpdesk);

        $validated = $request->validate([
            'label' => 'required|string|max:255',
            'type' => 'required|string|in:text,rich_text,select,checkbox,file,number,radio,date,checkboxes',
            'options' => 'nullable|array',
            'options.*' => 'string',
            'is_required' => 'boolean',
            'is_visible_on_card' => 'boolean',
        ]);

        $maxPosition = $helpdesk->fields()->max('position') ?? 0;

        $helpdesk->fields()->create([
            'label' => $validated['label'],
            'type' => $validated['type'],
            'options' => $validated['options'] ?? null,
            'is_required' => $validated['is_required'] ?? false,
            'is_visible_on_card' => $validated['is_visible_on_card'] ?? false,
            'position' => $maxPosition + 1,
        ]);

        return redirect()->back()->with('success', 'Field created successfully.');
    }

    /**
     * Update a custom field.
     */
    public function updateField(Request $request, Workspace $workspace, Project $project, Helpdesk $helpdesk, HelpdeskField $field)
    {
        $this->authorize('update', $helpdesk);

        $validated = $request->validate([
            'label' => 'required|string|max:255',
            'type' => 'required|string|in:text,rich_text,select,checkbox,file,number,radio,date,checkboxes',
            'options' => 'nullable|array',
            'options.*' => 'string',
            'is_required' => 'boolean',
            'is_visible_on_card' => 'boolean',
        ]);

        $field->update($validated);

        return redirect()->back()->with('success', 'Field updated successfully.');
    }

    /**
     * Reorder fields.
     */
    public function reorderFields(Request $request, Workspace $workspace, Project $project, Helpdesk $helpdesk)
    {
        $this->authorize('update', $helpdesk);

        $validated = $request->validate([
            'fields' => 'required|array',
            'fields.*.id' => 'required|integer|exists:helpdesk_fields,id',
            'fields.*.position' => 'required|integer|min:0',
        ]);

        foreach ($validated['fields'] as $fieldData) {
            HelpdeskField::where('id', $fieldData['id'])
                ->where('helpdesk_id', $helpdesk->id)
                ->update(['position' => $fieldData['position']]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Delete a custom field.
     */
    public function destroyField(Workspace $workspace, Project $project, Helpdesk $helpdesk, HelpdeskField $field)
    {
        $this->authorize('update', $helpdesk);

        $field->delete();

        return redirect()->back()->with('success', 'Field deleted successfully.');
    }

    /**
     * Return root tickets for a helpdesk as JSON.
     */
    public function rootTicketsJson(Request $request, Workspace $workspace, Project $project, Helpdesk $helpdesk)
    {
        $this->authorize('view', $helpdesk);

        $tickets = $helpdesk->tickets()
            ->select('id', 'key', 'subject', 'column_id')
            ->orderBy('position')
            ->get();

        return response()->json($tickets);
    }
}
