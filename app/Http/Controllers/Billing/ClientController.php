<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingSetting;
use App\Models\Client;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Workspace → Billing → Clients (BRAI-100, workspace admins): the customers the workspace bills, and
 * which projects belong to them. Clients are archived, never deleted (reports keep pointing at them).
 * VAT is entered as a percent and stored in basis points (`tax_rate_bp`, null = workspace default).
 */
class ClientController extends Controller
{
    public function index(Request $request, Workspace $workspace): Response
    {
        Gate::authorize('manageBilling', $workspace);

        $archived = $request->boolean('archived');
        $clients = $workspace->clients()
            ->when(! $archived, fn ($q) => $q->whereNull('archived_at'))
            ->with(['projects' => fn ($q) => $q->where('workspace_id', $workspace->id)->orderBy('name')->select(['id', 'client_id', 'name', 'slug', 'key', 'icon', 'color'])])
            ->orderByRaw('archived_at IS NOT NULL')
            ->orderBy('name')
            ->get();

        return Inertia::render('Billing/Clients/Index', [
            'workspace' => $workspace->only(['id', 'name', 'slug']),
            'clients' => $clients->map(fn (Client $client) => [
                'id' => $client->id,
                'name' => $client->name,
                'email' => $client->email,
                'address' => $client->address,
                'vat_id' => $client->vat_id,
                'tax_rate' => $client->tax_rate_bp === null ? null : $client->tax_rate_bp / 100,
                'notes' => $client->notes,
                'archived' => $client->archived_at !== null,
                'projects' => $client->projects->map->only(['id', 'name', 'slug', 'key', 'icon', 'icon_display', 'color'])->values(),
            ])->values(),
            'projects' => $workspace->projects()->orderBy('name')->get(['id', 'name', 'slug', 'key', 'icon', 'color', 'client_id'])
                ->map->only(['id', 'name', 'slug', 'key', 'icon', 'icon_display', 'color', 'client_id'])->values(),
            // names of every client a project points to, archived ones too ("moves from …")
            'clientNames' => $workspace->clients()->pluck('name', 'id'),
            'showArchived' => $archived,
            'archivedCount' => $workspace->clients()->whereNotNull('archived_at')->count(),
            'defaultTaxRate' => (BillingSetting::forWorkspace($workspace)->default_tax_rate_bp ?? 0) / 100,
        ]);
    }

    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manageBilling', $workspace);

        $client = $workspace->clients()->create($this->validated($request, $workspace));

        return back()->with('success', "Client “{$client->name}” created.");
    }

    public function update(Request $request, Workspace $workspace, Client $client): RedirectResponse
    {
        $this->authorizeClient($workspace, $client);

        $client->update($this->validated($request, $workspace, $client));

        return back()->with('success', "Client “{$client->name}” saved.");
    }

    public function archive(Workspace $workspace, Client $client): RedirectResponse
    {
        $this->authorizeClient($workspace, $client);

        $client->update(['archived_at' => now()]);

        return back()->with('success', "Client “{$client->name}” archived.");
    }

    public function restore(Workspace $workspace, Client $client): RedirectResponse
    {
        $this->authorizeClient($workspace, $client);

        $client->update(['archived_at' => null]);

        return back()->with('success', "Client “{$client->name}” restored.");
    }

    /**
     * The client's projects become exactly `project_ids` (projects of this workspace). A project that
     * belonged to another client moves here; projects left out are unassigned.
     */
    public function syncProjects(Request $request, Workspace $workspace, Client $client): RedirectResponse
    {
        $this->authorizeClient($workspace, $client);

        $validated = $request->validate([
            'project_ids' => ['present', 'array', 'max:500'],
            'project_ids.*' => ['integer', 'distinct', Rule::exists('projects', 'id')->where('workspace_id', $workspace->id)->whereNull('deleted_at')],
        ]);
        $ids = array_map('intval', $validated['project_ids']);

        // client_id isn't fillable on Project: set explicitly, scoped to this workspace
        DB::transaction(function () use ($workspace, $client, $ids) {
            Project::where('workspace_id', $workspace->id)->where('client_id', $client->id)->whereNotIn('id', $ids)->update(['client_id' => null]);
            if ($ids) {
                Project::where('workspace_id', $workspace->id)->whereIn('id', $ids)->update(['client_id' => $client->id]);
            }
        });

        return back()->with('success', count($ids) === 1 ? "1 project assigned to {$client->name}." : count($ids)." projects assigned to {$client->name}.");
    }

    private function authorizeClient(Workspace $workspace, Client $client): void
    {
        Gate::authorize('manageBilling', $workspace);
        abort_unless($client->workspace_id === $workspace->id, 404);
    }

    private function validated(Request $request, Workspace $workspace, ?Client $client = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160', Rule::unique('clients', 'name')->where('workspace_id', $workspace->id)->ignore($client?->id)],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'vat_id' => ['nullable', 'string', 'max:40'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], [], ['tax_rate' => 'VAT rate', 'vat_id' => 'VAT ID']);

        $data['tax_rate_bp'] = ($data['tax_rate'] ?? null) === null ? null : (int) round((float) $data['tax_rate'] * 100);
        unset($data['tax_rate']);

        return $data;
    }
}
