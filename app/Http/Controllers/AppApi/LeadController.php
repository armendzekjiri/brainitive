<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\LeadFormAnalyticsRequest;
use App\Http\Requests\AppApi\ListLeadsRequest;
use App\Http\Requests\AppApi\UpdateLeadRequest;
use App\Http\Resources\AppApi\LeadCollection;
use App\Http\Resources\AppApi\LeadFormAnalyticsResource;
use App\Http\Resources\AppApi\LeadFormResource;
use App\Http\Resources\AppApi\LeadResource;
use App\Models\Lead;
use App\Models\LeadForm;
use App\Models\User;
use App\Support\LeadForms\LeadActions;
use App\Support\LeadForms\LeadFormAnalytics;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App API v1 — lead forms' pipelines, the member side (BRAI-421): a project's lead forms (read), their leads
 * per column, and moving / editing / assigning a lead — the web's lead board through LeadController::present,
 * the shared LeadActions and LeadFormPolicy. Every project member reads leads; editors and admins work them.
 * The public form (submissions) and the builder are not in the App API.
 */
#[Group('Leads', weight: 300)]
class LeadController extends Controller
{
    use ResolvesVisible;

    /**
     * The lead forms of a project with their pipeline columns and lead counts, in the web's order.
     *
     * Not paginated.
     *
     * @throws NotFoundHttpException the project doesn't exist or the user can't open it (404)
     */
    public function forms(Request $request, string $project): AnonymousResourceCollection
    {
        $project = $this->visibleProject($request, $project);
        $forms = LeadForm::query()->where('project_id', $project->id)
            ->with(['columns' => fn ($q) => $q->withCount('leads')])
            ->orderBy('position')->orderBy('id')
            ->get()
            ->each(fn (LeadForm $f) => $f->setRelation('project', $project));

        return LeadFormResource::collection($forms);
    }

    /**
     * The leads of a lead form.
     *
     * With `column`: that column in board order (as the web's board); without: all, newest first. `assignee=me|
     * unassigned|<id>`. Cursor-paginated, `updated_since`.
     *
     * @throws NotFoundHttpException the form doesn't exist or the user can't open its project (404)
     */
    public function leads(ListLeadsRequest $request, string $form): LeadCollection
    {
        $form = $this->visibleForm($request, $form);
        $v = $request->validated();
        $assignee = $v['assignee'] ?? null;
        $since = $request->updatedSince();

        $query = Lead::query()->where('lead_form_id', $form->id)->with('assignee')
            ->when(isset($v['column']), fn ($q) => $q->where('column_id', (int) $v['column']))
            ->when($assignee === 'me', fn ($q) => $q->where('assigned_to', $request->user()->id))
            ->when($assignee === 'unassigned', fn ($q) => $q->whereNull('assigned_to'))
            ->when($assignee !== null && ctype_digit($assignee), fn ($q) => $q->where('assigned_to', (int) $assignee))
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since));
        isset($v['column'])
            ? $query->orderBy('position')->orderBy('id')
            : $query->orderByDesc('created_at')->orderByDesc('id');

        $page = $query->cursorPaginate($request->limit(), ['*'], 'cursor', $request->cursor());
        $page->getCollection()->each(fn (Lead $l) => $l->setRelation('form', $form));

        return new LeadCollection($page);
    }

    /**
     * One lead: contact details, answers, UTM, consent.
     *
     * @throws NotFoundHttpException the lead doesn't exist or the user can't open its project (404)
     */
    public function show(Request $request, string $lead): LeadResource
    {
        abort_unless(preg_match('/^[1-9][0-9]{0,17}$/', $lead) === 1, 404);
        $row = Lead::with(['form.project.workspace', 'assignee'])->find((int) $lead);
        abort_unless($row instanceof Lead && $row->form?->project?->workspace !== null && $request->user()->can('view', $row->form), 404);

        return new LeadResource($row);
    }

    /**
     * Move a lead to another pipeline column and / or change its contact details or assignee.
     *
     * Only the sent fields change. `position` places it in the column (0 = top; default: the end); a new
     * assignee is notified. Project viewers → 403; an assignee who can't open the project → 422.
     *
     * @throws NotFoundHttpException the lead doesn't exist or the user can't open its project (404)
     */
    public function update(UpdateLeadRequest $request): LeadResource
    {
        $lead = $request->lead();
        $form = $lead->form;
        $v = $request->validated();

        $changes = collect($v)->only(['name', 'email', 'phone', 'company'])->all();
        if (array_key_exists('assignee_id', $v)) {
            $assignee = $v['assignee_id'] !== null ? User::find($v['assignee_id']) : null;
            if ($v['assignee_id'] !== null && ! $assignee?->hasProjectAccess($form->project)) {
                throw ValidationException::withMessages(['assignee_id' => 'Pick a member of this project.']);
            }
            $changes['assigned_to'] = $v['assignee_id'];
        }
        if ($changes !== []) {
            LeadActions::update($lead, $form->project, $changes);
        }
        if (array_key_exists('column_id', $v)) {
            $columnId = (int) $v['column_id'];
            $order = Lead::where('lead_form_id', $form->id)->where('column_id', $columnId)->whereKeyNot($lead->id)
                ->orderBy('position')->orderBy('id')->pluck('id')->all();
            array_splice($order, min((int) ($v['position'] ?? count($order)), count($order)), 0, [$lead->id]);
            LeadActions::move($lead, $form, $columnId, $order);
        }

        return new LeadResource($lead->fresh(['form.project.workspace', 'assignee']));
    }

    /**
     * A lead form's funnel for the last 7 / 30 / 90 days (the web's Analytics tab).
     *
     * @throws NotFoundHttpException the form doesn't exist or the user can't open its project (404)
     */
    public function analytics(LeadFormAnalyticsRequest $request, string $form): LeadFormAnalyticsResource
    {
        $form = $this->visibleForm($request, $form);

        return new LeadFormAnalyticsResource(LeadFormAnalytics::summary($form, (int) ($request->validated('days') ?? 30)));
    }

    private function visibleForm(Request $request, string $id): LeadForm
    {
        abort_unless(preg_match('/^[1-9][0-9]{0,17}$/', $id) === 1, 404);
        $form = LeadForm::with('project.workspace')->find((int) $id);
        abort_unless($form instanceof LeadForm && $form->project?->workspace !== null && $request->user()->can('view', $form), 404);

        return $form;
    }
}
