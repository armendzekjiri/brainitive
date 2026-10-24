<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingReport;
use App\Models\BillingSetting;
use App\Models\Client;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\Workspace;
use App\Support\Billing\ReportBuilder;
use App\Support\Billing\ReportComputation;
use App\Support\Billing\ReportFinalizer;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Billing reports (v1.38.0, BRAI-101 reports): list, draft builder (live preview of the snapshot,
 * options, leave out entries), finalize / paid / void. Workspace admins only (manageBilling);
 * a report of another workspace is a 404. Money rules and the snapshot shape:
 * docs/ai/tasks/release-v1.38.0-billing.md; computing in ReportBuilder, locking in ReportFinalizer.
 */
class BillingReportController extends Controller
{
    public function __construct(private readonly ReportBuilder $builder, private readonly ReportFinalizer $finalizer) {}

    public function index(Request $request, Workspace $workspace): Response
    {
        Gate::authorize('manageBilling', $workspace);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(BillingReport::STATUSES)],
            'client' => ['nullable', 'integer'],
        ]);

        $reports = BillingReport::where('workspace_id', $workspace->id)
            ->with('client:id,name')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['client'] ?? null, fn ($q, $client) => $q->where('client_id', $client))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(25)->withQueryString()
            ->through(fn (BillingReport $r) => $this->summary($r));

        $clients = Client::where('workspace_id', $workspace->id)->withCount('projects')->orderBy('name')->orderBy('id')
            ->get(['id', 'name', 'archived_at'])
            ->map(fn (Client $c) => ['id' => $c->id, 'name' => $c->name, 'archived' => $c->archived_at !== null, 'projects_count' => $c->projects_count]);

        $lastMonth = CarbonImmutable::now($request->user()->localTimezone())->startOfMonth()->subMonth();

        return Inertia::render('Billing/Reports/Index', [
            'reports' => $reports,
            'clients' => $clients,
            'filters' => ['status' => $filters['status'] ?? null, 'client' => isset($filters['client']) ? (int) $filters['client'] : null],
            'defaultPeriod' => ['from' => $lastMonth->format('Y-m-d'), 'to' => $lastMonth->endOfMonth()->format('Y-m-d')],
        ]);
    }

    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manageBilling', $workspace);
        $data = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where('workspace_id', $workspace->id)->whereNull('archived_at')],
            ...self::periodRules(),
        ]);
        $client = Client::where('workspace_id', $workspace->id)->findOrFail($data['client_id']);
        if (! $client->projects()->exists()) {
            throw ValidationException::withMessages(['client_id' => 'Assign a project to this client first.']);
        }

        $setting = BillingSetting::forWorkspace($workspace);
        $kind = $setting->invoice_mode ? 'invoice' : 'report';
        $from = CarbonImmutable::parse($data['period_from']);
        $to = CarbonImmutable::parse($data['period_to']);

        $report = BillingReport::create([
            'workspace_id' => $workspace->id,
            'client_id' => $client->id,
            'kind' => $kind,
            'title' => ReportBuilder::defaultTitle($kind, $from, $to),
            'period_from' => $from->format('Y-m-d'),
            'period_to' => $to->format('Y-m-d'),
            'status' => 'draft',
            'currency' => $setting->currency ?: 'EUR',
            'options' => ['project_ids' => null, 'group_by' => 'ticket', 'show_people' => true, 'show_notes' => false, 'excluded_entry_ids' => []],
            'created_by' => $request->user()->id,
        ]);
        $this->refreshDraftTotals($report->setRelation('workspace', $workspace)->setRelation('client', $client));

        return redirect()->route('billing.reports.show', [$workspace, $report->id]);
    }

    public function show(Workspace $workspace, int $billingReport): Response
    {
        $report = $this->report($workspace, $billingReport);

        $computation = null;
        if ($report->isDraft()) {
            $computation = $this->refreshDraftTotals($report);
        }

        $projects = $report->client_id
            ? Project::where('workspace_id', $workspace->id)->where('client_id', $report->client_id)->orderBy('name')->orderBy('id')->get(['id', 'name', 'key'])
            : collect();
        $setting = BillingSetting::forWorkspace($workspace);

        return Inertia::render('Billing/Reports/Show', [
            'report' => $this->summary($report) + [
                'options' => ReportBuilder::options($report->options),
                'finalized_at' => $report->finalized_at?->toIso8601String(),
                'sent_at' => $report->sent_at?->toIso8601String(),
                'sent_to' => $report->sent_to,
                'paid_at' => $report->paid_at?->toIso8601String(),
                'voided_at' => $report->voided_at?->toIso8601String(),
                'client_email' => $report->client?->email,
            ],
            'preview' => $computation?->snapshot ?? $report->snapshot,
            'rows' => $computation?->rows ?? ($report->snapshot['entries'] ?? []),
            'excluded' => $computation?->excludedRows ?? [],
            'entryIds' => $computation?->entryIds ?? [],
            'warnings' => $computation?->warnings,
            'projects' => $projects,
            'settings' => [
                'invoice_mode' => (bool) $setting->invoice_mode,
                'rounding_minutes' => (int) $setting->rounding_minutes,
                'company_name' => $setting->company_name,
            ],
        ]);
    }

    public function update(Request $request, Workspace $workspace, int $billingReport): RedirectResponse
    {
        $report = $this->report($workspace, $billingReport);
        $this->draftOnly($report);
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:160'],
            ...self::periodRules(),
            'project_ids' => ['nullable', 'array', 'max:500'],
            'project_ids.*' => ['integer'],
            'group_by' => ['required', Rule::in(BillingReport::GROUP_BY)],
            'show_people' => ['required', 'boolean'],
            'show_notes' => ['required', 'boolean'],
            'excluded_entry_ids' => ['present', 'array', 'max:5000'],
            'excluded_entry_ids.*' => ['integer'],
        ]);

        $projectIds = null;
        if ($data['project_ids'] !== null) {
            $projectIds = Project::where('workspace_id', $workspace->id)->where('client_id', $report->client_id)
                ->whereIn('id', $data['project_ids'])->orderBy('id')->pluck('id')->all();
            if (count($projectIds) !== count(array_unique($data['project_ids']))) {
                throw ValidationException::withMessages(['project_ids' => 'Pick projects of this client.']);
            }
        }
        // only this workspace's entries; ids that are no longer in scope just don't match later
        $excluded = TimeEntry::where('workspace_id', $workspace->id)->whereIn('id', $data['excluded_entry_ids'])->orderBy('id')->pluck('id')->all();

        $report->update([
            'title' => $data['title'] ?: ReportBuilder::defaultTitle($report->kind, CarbonImmutable::parse($data['period_from']), CarbonImmutable::parse($data['period_to'])),
            'period_from' => $data['period_from'],
            'period_to' => $data['period_to'],
            'options' => [
                'project_ids' => $projectIds,
                'group_by' => $data['group_by'],
                'show_people' => (bool) $data['show_people'],
                'show_notes' => (bool) $data['show_notes'],
                'excluded_entry_ids' => $excluded,
            ],
        ]);
        $this->refreshDraftTotals($report);

        return back();
    }

    public function destroy(Workspace $workspace, int $billingReport): RedirectResponse
    {
        $report = $this->report($workspace, $billingReport);
        $this->draftOnly($report);
        $report->delete();

        return redirect()->route('billing.reports.index', $workspace)->with('success', 'Draft deleted.');
    }

    public function finalize(Request $request, Workspace $workspace, int $billingReport): RedirectResponse
    {
        $report = $this->report($workspace, $billingReport);
        $data = $request->validate([
            'entry_ids' => ['present', 'array', 'max:10000'],
            'entry_ids.*' => ['integer'],
        ]);
        $wasFinal = $report->isFinal();

        $report = $this->finalizer->finalize($report, $data['entry_ids'], $request->user());

        return redirect()->route('billing.reports.show', [$workspace, $report->id])
            ->with('success', $wasFinal ? 'This report was already finalized.' : ($report->number ? "Finalized as invoice {$report->number}." : 'Report finalized. Its time entries are locked.'));
    }

    public function markPaid(Workspace $workspace, int $billingReport): RedirectResponse
    {
        $report = $this->finalizer->markPaid($this->report($workspace, $billingReport));

        return redirect()->route('billing.reports.show', [$workspace, $report->id])->with('success', 'Marked as paid.');
    }

    public function void(Request $request, Workspace $workspace, int $billingReport): RedirectResponse
    {
        $report = $this->report($workspace, $billingReport);
        $data = $request->validate(['confirm' => ['nullable', 'boolean']]);
        if ($report->status === 'paid' && ! ($data['confirm'] ?? false)) {
            throw ValidationException::withMessages(['report' => 'This report is paid. Confirm to void it anyway.']);
        }

        $report = $this->finalizer->void($report);

        return redirect()->route('billing.reports.show', [$workspace, $report->id])
            ->with('success', 'Report voided. Its time entries can be billed again.');
    }

    /** Workspace admins only; another workspace's report is a 404. */
    private function report(Workspace $workspace, int $id): BillingReport
    {
        Gate::authorize('manageBilling', $workspace);

        return BillingReport::where('workspace_id', $workspace->id)->with('client')->findOrFail($id)
            ->setRelation('workspace', $workspace);
    }

    private function draftOnly(BillingReport $report): void
    {
        if (! $report->isDraft()) {
            throw ValidationException::withMessages(['report' => 'Only drafts can be changed.']);
        }
    }

    /** A draft's totals columns cache its live preview for the list (finalize writes the real ones). */
    private function refreshDraftTotals(BillingReport $report): ReportComputation
    {
        $computation = $this->builder->compute($report);
        $snapshot = $computation->snapshot;
        $report->forceFill([
            'kind' => $snapshot['kind'],
            'currency' => $snapshot['currency'],
            'tax_rate_bp' => $snapshot['tax_rate_bp'],
            'prices_include_tax' => $snapshot['prices_include_tax'],
            'total_minutes' => $snapshot['totals']['billed_minutes'],
            'subtotal_cents' => $snapshot['totals']['net_cents'],
            'tax_cents' => $snapshot['totals']['tax_cents'],
            'total_cents' => $snapshot['totals']['gross_cents'],
        ]);
        if ($report->isDirty()) {
            $report->saveQuietly();
        }

        return $computation;
    }

    private static function periodRules(): array
    {
        return [
            'period_from' => ['required', 'date_format:Y-m-d'],
            'period_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_from', function ($attribute, $value, $fail) {
                $from = request()->input('period_from');
                if (is_string($from) && strtotime($from) && strtotime((string) $value) - strtotime($from) > 366 * 86400) {
                    $fail('A report covers at most one year.');
                }
            }],
        ];
    }

    private function summary(BillingReport $r): array
    {
        return [
            'id' => $r->id,
            'kind' => $r->kind,
            'number' => $r->number,
            'title' => $r->title,
            'status' => $r->status,
            'client' => $r->client ? ['id' => $r->client->id, 'name' => $r->client->name] : null,
            'period_from' => $r->period_from->format('Y-m-d'),
            'period_to' => $r->period_to->format('Y-m-d'),
            'currency' => $r->currency,
            'total_minutes' => $r->total_minutes,
            'total_cents' => $r->total_cents,
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
