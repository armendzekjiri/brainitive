<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadForm;
use App\Models\LeadFormColumn;
use App\Models\LeadFormEvent;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\LeadForms\LeadFormDefinition;
use App\Support\LeadForms\LeadFormOptions;
use App\Support\LeadForms\LeadFormTemplates;
use App\Support\Uploads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lead generation forms (BRAI-93): create from a template, build (definition / theme /
 * settings), publish at /f/{public_slug}, the leads board's columns and the analytics.
 */
class LeadFormController extends Controller
{
    public const PUBLIC_SLUG = '/^[a-z0-9](?:[a-z0-9-]{1,58}[a-z0-9])$/';

    /** Template catalog for the picker. */
    public function templates(Workspace $workspace): JsonResponse
    {
        $this->authorize('view', $workspace);

        return response()->json([
            'categories' => LeadFormTemplates::CATEGORIES,
            'templates' => LeadFormTemplates::catalog(),
        ]);
    }

    public function store(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        $this->authorize('create', [LeadForm::class, $project]);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'template' => ['nullable', 'string', Rule::in(LeadFormTemplates::keys())],
        ]);

        $built = LeadFormTemplates::build($validated['template'] ?? 'blank');
        $built['settings']['notify_user_ids'] = [$request->user()->id];

        $form = DB::transaction(function () use ($project, $validated, $built, $request) {
            $form = $project->leadForms()->create([
                'name' => $validated['name'],
                'slug' => $this->uniqueSlug($project, $validated['name']),
                'status' => LeadForm::STATUS_DRAFT,
                'definition' => $built['definition'],
                'theme' => $built['theme'],
                'settings' => $built['settings'],
                'position' => (int) LeadForm::where('project_id', $project->id)->max('position') + 1,
                'created_by' => $request->user()->id,
            ]);
            foreach ($built['columns'] as $i => $column) {
                $form->columns()->create($column + ['position' => $i]);
            }

            return $form;
        });

        return redirect()->route('lead-forms.show', [$workspace->slug, $project->slug, $form->slug, 'tab' => 'builder'])
            ->with('success', 'Lead form created.');
    }

    public function show(Request $request, Workspace $workspace, Project $project, LeadForm $leadForm): Response
    {
        $this->authorize('view', $leadForm);
        $user = $request->user();

        $leadForm->load(['columns.leads.assignee:id,name,email,avatar']);
        $leadForm->columns->each(fn ($column) => $column->leads->each->setRelation('form', $leadForm));
        $canUpdate = $user->can('update', $leadForm);

        return Inertia::render('LeadForms/Show', [
            'workspace' => $workspace,
            'project' => $project,
            'form' => $this->payload($leadForm, $canUpdate),
            'columns' => $leadForm->columns->map(fn (LeadFormColumn $column) => [
                'id' => $column->id,
                'name' => $column->name,
                'color' => $column->color,
                'is_done' => $column->is_done,
                'leads' => $column->leads->map(fn ($lead) => LeadController::present($lead))->values(),
            ])->values(),
            'members' => $this->members($project),
            'can' => [
                'update' => $canUpdate,
                'manageLeads' => $user->can('manageLeads', $leadForm),
                'delete' => $user->can('delete', $leadForm),
            ],
            'tab' => $request->query('tab'),
        ]);
    }

    /** Builder save (autosave): any of name, definition, theme, settings. */
    public function update(Request $request, Workspace $workspace, Project $project, LeadForm $leadForm): JsonResponse
    {
        $this->authorize('update', $leadForm);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'definition' => 'sometimes|array',
            ...LeadFormOptions::themeRules(),
            ...LeadFormOptions::settingsRules(),
        ]);

        $changes = [];
        if (isset($validated['name'])) {
            $changes['name'] = $validated['name'];
        }
        if (array_key_exists('definition', $validated)) {
            $changes['definition'] = LeadFormDefinition::normalize($validated['definition']);
        }
        if (isset($validated['theme'])) {
            $changes['theme'] = LeadFormOptions::theme($leadForm->theme, $validated['theme']);
        }
        if (isset($validated['settings'])) {
            $settings = LeadFormOptions::settings($leadForm->settings, $validated['settings']);
            if ($settings['after_submit'] === 'redirect' && ! $settings['redirect_url']) {
                throw ValidationException::withMessages(['settings.redirect_url' => 'Enter the page to send people to after they submit.']);
            }
            if (isset($validated['settings']['notify_user_ids'])) {
                $memberIds = collect($this->members($project))->pluck('id')->all();
                $settings['notify_user_ids'] = array_values(array_intersect($settings['notify_user_ids'], $memberIds));
            }
            $changes['settings'] = $settings;
            if ($settings['webhook_url'] && ! $leadForm->webhook_secret) {
                $changes['webhook_secret'] = Str::random(40);
            }
        }

        $leadForm->update($changes);

        return response()->json(['form' => $this->payload($leadForm->fresh(), true)]);
    }

    public function publish(Request $request, Workspace $workspace, Project $project, LeadForm $leadForm): RedirectResponse
    {
        $this->authorize('update', $leadForm);

        $validated = $request->validate([
            'public_slug' => [
                'required', 'string', 'regex:'.self::PUBLIC_SLUG,
                // the unique index includes deleted forms
                Rule::unique('lead_forms', 'public_slug')->ignore($leadForm->id),
            ],
        ], ['public_slug.regex' => 'Use 3–60 lowercase letters, numbers and dashes.', 'public_slug.unique' => 'This address is already taken.']);

        $leadForm->update(['public_slug' => $validated['public_slug'], 'status' => LeadForm::STATUS_PUBLISHED]);

        return back()->with('success', 'The form is live.');
    }

    public function unpublish(Workspace $workspace, Project $project, LeadForm $leadForm): RedirectResponse
    {
        $this->authorize('update', $leadForm);
        $leadForm->update(['status' => LeadForm::STATUS_DRAFT]);

        return back()->with('success', 'The form is offline.');
    }

    public function destroy(Workspace $workspace, Project $project, LeadForm $leadForm): RedirectResponse
    {
        $this->authorize('delete', $leadForm);

        // Free the addresses for new forms (both unique indexes include deleted rows)
        $leadForm->update([
            'status' => LeadForm::STATUS_DRAFT,
            'public_slug' => null,
            'inbound_token' => null,
            'slug' => Str::limit($leadForm->slug, 200, '').'-deleted-'.$leadForm->id,
        ]);
        $leadForm->delete();

        return redirect()->route('projects.show', [$workspace->slug, $project->slug])->with('success', 'Lead form deleted.');
    }

    /** Logo / cover image for the theme → its /storage URL. */
    public function uploadAsset(Request $request, Workspace $workspace, Project $project, LeadForm $leadForm): JsonResponse
    {
        $this->authorize('update', $leadForm);

        $request->validate(['file' => 'required|file|mimes:jpg,jpeg,png,gif,webp|max:4096']);
        $file = $request->file('file');
        $path = $file->storeAs('lead-forms/'.$leadForm->uuid, Uploads::imageName($file), 'public');

        return response()->json(['url' => '/storage/'.$path]);
    }

    /** New inbound token (the old one stops working) — shown once. */
    public function rotateInboundToken(Workspace $workspace, Project $project, LeadForm $leadForm): JsonResponse
    {
        $this->authorize('update', $leadForm);

        $token = 'lf_'.Str::random(40);
        $leadForm->update(['inbound_token' => hash('sha256', $token)]);

        return response()->json(['token' => $token, 'url' => route('lead-forms.inbound', $leadForm->uuid)]);
    }

    public function revokeInboundToken(Workspace $workspace, Project $project, LeadForm $leadForm): JsonResponse
    {
        $this->authorize('update', $leadForm);
        $leadForm->update(['inbound_token' => null]);

        return response()->json(['ok' => true]);
    }

    /** Views, starts, completions per day and how far visitors get (unique visit sessions). */
    public function analytics(Request $request, Workspace $workspace, Project $project, LeadForm $leadForm): JsonResponse
    {
        $this->authorize('view', $leadForm);

        $days = in_array((int) $request->query('days'), [7, 30, 90], true) ? (int) $request->query('days') : 30;
        $since = now()->subDays($days - 1)->startOfDay();
        $events = LeadFormEvent::where('lead_form_id', $leadForm->id)->where('created_at', '>=', $since);

        $totals = (clone $events)->select('type', DB::raw('count(distinct session_id) as sessions'))
            ->groupBy('type')->pluck('sessions', 'type');

        $daily = (clone $events)->whereIn('type', ['view', 'submit'])
            ->select(DB::raw('date(created_at) as day'), 'type', DB::raw('count(distinct session_id) as sessions'))
            ->groupBy('day', 'type')->get();

        $series = [];
        for ($d = $since->copy(); $d->lte(now()); $d->addDay()) {
            $series[$d->toDateString()] = ['date' => $d->toDateString(), 'views' => 0, 'submits' => 0];
        }
        foreach ($daily as $row) {
            $day = substr((string) $row->day, 0, 10);
            if (isset($series[$day])) {
                $series[$day][$row->type === 'view' ? 'views' : 'submits'] = (int) $row->sessions;
            }
        }

        $reached = (clone $events)->where('type', 'step')
            ->select('step', DB::raw('count(distinct session_id) as sessions'))
            ->groupBy('step')->pluck('sessions', 'step');

        $steps = [];
        foreach ($leadForm->definition['steps'] ?? [] as $i => $step) {
            $steps[] = ['index' => $i, 'title' => $step['title'], 'sessions' => (int) ($reached[$i] ?? ($i === 0 ? ($totals['start'] ?? 0) : 0))];
        }

        $views = (int) ($totals['view'] ?? 0);
        $submits = (int) ($totals['submit'] ?? 0);

        return response()->json([
            'days' => $days,
            'views' => $views,
            'starts' => (int) ($totals['start'] ?? 0),
            'submits' => $submits,
            'conversion' => $views > 0 ? round($submits / $views * 100, 1) : null,
            'leads' => $leadForm->leads()->where('created_at', '>=', $since)->count(),
            'series' => array_values($series),
            'steps' => $steps,
        ]);
    }

    // ── board columns ────────────────────────────────────────────────────────

    public function storeColumn(Request $request, Workspace $workspace, Project $project, LeadForm $leadForm): RedirectResponse
    {
        $this->authorize('update', $leadForm);
        $validated = $request->validate($this->columnRules());

        $leadForm->columns()->create([
            'name' => $validated['name'],
            'slug' => $this->uniqueColumnSlug($leadForm, $validated['name']),
            'color' => $validated['color'] ?? '#64748b',
            'is_done' => $validated['is_done'] ?? false,
            'position' => (int) $leadForm->columns()->reorder()->max('position') + 1,
        ]);

        return back();
    }

    public function updateColumn(Request $request, Workspace $workspace, Project $project, LeadForm $leadForm, LeadFormColumn $column): RedirectResponse
    {
        $this->authorize('update', $leadForm);
        $column->update($request->validate($this->columnRules(true)));

        return back();
    }

    public function reorderColumns(Request $request, Workspace $workspace, Project $project, LeadForm $leadForm): RedirectResponse
    {
        $this->authorize('update', $leadForm);
        $validated = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer']);

        $ids = $leadForm->columns()->pluck('id')->all();
        DB::transaction(function () use ($validated, $ids) {
            foreach (array_values(array_intersect($validated['ids'], $ids)) as $position => $id) {
                LeadFormColumn::whereKey($id)->update(['position' => $position]);
            }
        });

        return back();
    }

    /** Leads of a deleted column move to the first remaining column. */
    public function destroyColumn(Workspace $workspace, Project $project, LeadForm $leadForm, LeadFormColumn $column): RedirectResponse
    {
        $this->authorize('update', $leadForm);

        $target = $leadForm->columns()->whereKeyNot($column->id)->first();
        if (! $target) {
            return back()->with('error', 'A board needs at least one column.');
        }

        DB::transaction(function () use ($column, $target) {
            Lead::withTrashed()->where('column_id', $column->id)->update(['column_id' => $target->id]);
            $column->delete();
        });

        return back();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * The form as the builder edits it.
     */
    private function payload(LeadForm $form, bool $canUpdate): array
    {
        return [
            'id' => $form->id,
            'uuid' => $form->uuid,
            'name' => $form->name,
            'slug' => $form->slug,
            'public_slug' => $form->public_slug,
            'status' => $form->status,
            'definition' => $form->definition ?? ['version' => LeadFormDefinition::VERSION, 'steps' => []],
            'theme' => LeadFormOptions::theme($form->theme),
            'settings' => LeadFormOptions::settings($form->settings),
            'public_url' => $form->public_slug ? route('lead-forms.public', $form->public_slug) : null,
            'embed_url' => $form->public_slug ? route('lead-forms.embed', $form->public_slug) : null,
            'inbound_url' => route('lead-forms.inbound', $form->uuid),
            'has_inbound_token' => $form->inbound_token !== null,
            'webhook_secret' => $canUpdate ? $form->webhook_secret : null,
            'leads_count' => $form->leads()->count(),
        ];
    }

    /**
     * @return list<array{id: int, name: string, email: string, avatar: ?string}>
     */
    private function members(Project $project): array
    {
        $admins = $project->workspace->users()->wherePivot('role', 'admin')->get(['users.id', 'name', 'email', 'avatar']);

        return $project->users()->get(['users.id', 'name', 'email', 'avatar'])
            ->concat($admins)->unique('id')->sortBy('name')
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'avatar' => $u->avatar])
            ->values()->all();
    }

    private function columnRules(bool $update = false): array
    {
        return [
            'name' => [$update ? 'sometimes' : 'required', 'string', 'max:60'],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_done' => ['sometimes', 'boolean'],
        ];
    }

    private function uniqueSlug(Project $project, string $name): string
    {
        $base = Str::limit(Str::slug($name), 200, '') ?: 'form';
        $slug = $base;
        for ($n = 2; LeadForm::withTrashed()->where('project_id', $project->id)->where('slug', $slug)->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }

    private function uniqueColumnSlug(LeadForm $form, string $name): string
    {
        $base = Str::slug($name) ?: 'column';
        $slug = $base;
        for ($n = 2; $form->columns()->where('slug', $slug)->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }
}
