<?php

namespace App\Http\Controllers;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Automation\AutomationActions;
use App\Support\Automation\AutomationCatalog;
use App\Support\Automation\AutomationConditions;
use App\Support\Automation\AutomationDefinition;
use App\Support\Automation\AutomationOptions;
use App\Support\Automation\AutomationRunner;
use App\Support\Automation\AutomationSubject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Project → Automations (v1.40.0, BRAI-63): list with on/off switch and last run, the WHEN / IF / THEN
 * builder, "Test on an issue" (dry run, no writes) and the run history. Project admins only (whoever
 * may edit the project settings: ProjectPolicy::update). A rule runs as the person who saved it last.
 */
class AutomationController extends Controller
{
    public function index(Request $request, Workspace $workspace, Project $project): Response
    {
        $this->authorize('update', $project);
        $options = new AutomationOptions($project);

        $rules = $project->automationRules()->with('actor:id,name,suspended_at')->orderBy('name')->orderBy('id')->get();
        $runs = AutomationRun::where('project_id', $project->id)->with('rule:id,name')
            ->latest('id')->limit(50)->get();

        return Inertia::render('Automations/Index', [
            'workspace' => $workspace->only(['id', 'name', 'slug']),
            'project' => $project->only(['id', 'name', 'slug', 'key']),
            'rules' => $rules->map(fn (AutomationRule $rule) => $this->presentRule($rule, $options, $project))->values(),
            'runs' => $runs->map(fn (AutomationRun $run) => $this->presentRun($run))->values(),
            'maxRules' => AutomationRule::MAX_PER_PROJECT,
        ]);
    }

    public function create(Request $request, Workspace $workspace, Project $project): Response
    {
        $this->authorize('update', $project);

        return $this->builder($workspace, $project, null);
    }

    public function store(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);
        if ($project->automationRules()->count() >= AutomationRule::MAX_PER_PROJECT) {
            throw ValidationException::withMessages(['name' => 'A project can have at most '.AutomationRule::MAX_PER_PROJECT.' automation rules.']);
        }

        $definition = AutomationDefinition::validate($request->all(), new AutomationOptions($project));
        $rule = $project->automationRules()->create($definition + [
            'enabled' => false, // rules start switched off
            'version' => 1,
            'actor_id' => $request->user()->id,
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('automations.edit', [$workspace->slug, $project->slug, $rule->id])
            ->with('success', 'Rule saved. It is switched off until you turn it on.');
    }

    public function edit(Request $request, Workspace $workspace, Project $project, AutomationRule $automationRule): Response
    {
        $this->authorize('update', $project);

        return $this->builder($workspace, $project, $automationRule);
    }

    public function update(Request $request, Workspace $workspace, Project $project, AutomationRule $automationRule): RedirectResponse
    {
        $this->authorize('update', $project);

        $definition = AutomationDefinition::validate($request->all(), new AutomationOptions($project));
        $changed = collect(['trigger', 'trigger_config', 'condition_match', 'conditions', 'actions'])
            ->contains(fn (string $key) => $definition[$key] != ($automationRule->definition()[$key] ?? null));

        $automationRule->update($definition + [
            // a new definition is a new version: runs queued for the old one are skipped
            'version' => $changed ? $automationRule->version + 1 : $automationRule->version,
            // the rule runs as whoever saved it last
            'actor_id' => $request->user()->id,
        ]);

        return back()->with('success', $changed ? 'Rule saved (version '.$automationRule->version.').' : 'Rule saved.');
    }

    public function toggle(Request $request, Workspace $workspace, Project $project, AutomationRule $automationRule): RedirectResponse
    {
        $this->authorize('update', $project);
        $validated = $request->validate(['enabled' => ['required', 'boolean']]);

        $automationRule->update(['enabled' => $validated['enabled']]);

        return back()->with('success', $automationRule->enabled ? "\"{$automationRule->name}\" is on." : "\"{$automationRule->name}\" is off.");
    }

    public function destroy(Request $request, Workspace $workspace, Project $project, AutomationRule $automationRule): RedirectResponse
    {
        $this->authorize('update', $project);
        $automationRule->delete();

        return redirect()->route('automations.index', [$workspace->slug, $project->slug])
            ->with('success', 'Rule deleted.');
    }

    /**
     * "Test on an issue": the unsaved definition from the builder against one issue / ticket, as the
     * current user (who becomes the actor on save). Reads only.
     */
    public function preview(Request $request, Workspace $workspace, Project $project): JsonResponse
    {
        $this->authorize('update', $project);
        $validated = $request->validate(['subject_key' => ['required', 'string', 'max:40']]);

        $definition = AutomationDefinition::validate(['name' => $request->input('name') ?: 'Test'] + $request->except('subject_key'), new AutomationOptions($project));
        $type = AutomationCatalog::subjectOf($definition['trigger']);
        $subject = AutomationSubject::findByKey($type, $validated['subject_key'], $project->id);
        if (! $subject) {
            throw ValidationException::withMessages(['subject_key' => $type === AutomationCatalog::SUBJECT_TICKET
                ? 'No ticket with that key in this project.'
                : 'No issue with that key in this project.']);
        }

        return response()->json(AutomationRunner::preview($definition, $project, $request->user(), $subject));
    }

    private function builder(Workspace $workspace, Project $project, ?AutomationRule $rule): Response
    {
        $options = new AutomationOptions($project);
        $runs = $rule
            ? $rule->runs()->latest('id')->limit(50)->get()->map(fn (AutomationRun $run) => $this->presentRun($run))->values()
            : [];

        return Inertia::render('Automations/Edit', [
            'workspace' => $workspace->only(['id', 'name', 'slug']),
            'project' => $project->only(['id', 'name', 'slug', 'key']),
            'rule' => $rule ? $this->presentRule($rule->load('actor:id,name,suspended_at'), $options, $project) : null,
            'runs' => $runs,
            'catalog' => AutomationCatalog::forFrontend(),
            'options' => $options->forFrontend(),
        ]);
    }

    private function presentRule(AutomationRule $rule, AutomationOptions $options, Project $project): array
    {
        $subject = AutomationCatalog::subjectOf($rule->trigger);
        $actor = $rule->actor;
        $column = $rule->trigger_config['column_id'] ?? null;
        $when = AutomationCatalog::TRIGGERS[$rule->trigger]['label'] ?? $rule->trigger;
        if ($column) {
            $when .= ' to '.($options->name('column', $column, $subject) ?? '(deleted column)');
        }

        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'enabled' => $rule->enabled,
            'version' => $rule->version,
            'trigger' => $rule->trigger,
            'trigger_config' => (object) ($rule->trigger_config ?? []),
            'condition_match' => $rule->conditionMatch(),
            'conditions' => $rule->normalizedConditions(),
            'actions' => $rule->actions ?? [],
            'summary' => [
                'when' => $when,
                'match' => $rule->conditionMatch(),
                'if' => array_map(fn (array $c) => AutomationConditions::describe($c, $options, $subject), $rule->normalizedConditions()),
                'then' => array_map(fn (array $a) => AutomationActions::describe($a, $options, $subject), $rule->actions ?? []),
            ],
            'actor' => $actor ? ['id' => $actor->id, 'name' => $actor->name] : null,
            'actor_problem' => $this->actorProblem($actor, $project),
            'last_run_at' => $rule->last_run_at?->toIso8601String(),
            'last_run_status' => $rule->last_run_status,
        ];
    }

    private function actorProblem(?User $actor, Project $project): ?string
    {
        return match (true) {
            ! $actor => 'The person this rule ran as was deleted. Save the rule to run it as you.',
            $actor->isSuspended() => "{$actor->name} is suspended. Save the rule to run it as you.",
            ! $actor->hasProjectAccess($project) => "{$actor->name} can no longer open this project. Save the rule to run it as you.",
            default => null,
        };
    }

    private function presentRun(AutomationRun $run): array
    {
        $results = $run->results ?? [];
        ksort($results); // keyed by action index

        return [
            'id' => $run->id,
            'rule' => $run->relationLoaded('rule') && $run->rule ? ['id' => $run->rule->id, 'name' => $run->rule->name] : null,
            'rule_version' => $run->rule_version,
            'trigger' => AutomationCatalog::TRIGGERS[$run->trigger]['label'] ?? $run->trigger,
            'subject' => $run->subject_label,
            'status' => $run->status,
            'message' => $run->message,
            'results' => array_values($results),
            'conditions' => $run->conditions,
            'created_at' => $run->created_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }
}
