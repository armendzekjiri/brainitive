<?php

namespace Tests\Feature\Billing;

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Projects\ProjectTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Billing → Clients (BRAI-100): workspace admins create, edit, archive and restore clients and assign
 * projects to them — on the client page and in project settings. Projects of other workspaces never.
 */
class ClientsTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $this->admin = $this->workspace->owner;
    }

    private function url(string $name, array $parameters = []): string
    {
        return route($name, ['workspace' => $this->workspace->slug] + $parameters);
    }

    private function client(array $attributes = []): Client
    {
        return Client::create($attributes + ['workspace_id' => $this->workspace->id, 'name' => 'Muster GmbH']);
    }

    public function test_an_admin_creates_and_edits_a_client_with_its_own_vat_rate(): void
    {
        $this->actingAs($this->admin)->post($this->url('billing.clients.store'), [
            'name' => 'Muster GmbH',
            'email' => 'billing@muster.test',
            'address' => "Hauptstraße 1\n10115 Berlin",
            'vat_id' => 'DE123456789',
            'tax_rate' => '19',
            'notes' => 'Invoices to accounting.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $client = Client::firstWhere('name', 'Muster GmbH');
        $this->assertSame($this->workspace->id, $client->workspace_id);
        $this->assertSame(1900, $client->tax_rate_bp);
        $this->assertSame("Hauptstraße 1\n10115 Berlin", $client->address);

        $this->actingAs($this->admin)->put($this->url('billing.clients.update', ['client' => $client->id]), [
            'name' => 'Muster AG',
            'email' => null,
            'tax_rate' => '7.5',
        ])->assertSessionHasNoErrors();
        $client->refresh();
        $this->assertSame(['Muster AG', null, 750, 'DE123456789'], [$client->name, $client->email, $client->tax_rate_bp, $client->vat_id]);

        // an empty VAT rate = the workspace default
        $this->actingAs($this->admin)->put($this->url('billing.clients.update', ['client' => $client->id]), ['name' => 'Muster AG', 'tax_rate' => ''])->assertSessionHasNoErrors();
        $this->assertNull($client->fresh()->tax_rate_bp);

        $this->actingAs($this->admin)->get($this->url('billing.clients.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Billing/Clients/Index')
                ->where('clients.0.name', 'Muster AG')
                ->where('clients.0.tax_rate', null));
    }

    public function test_client_input_is_validated(): void
    {
        $this->client(['name' => 'Taken']);

        $this->actingAs($this->admin)->post($this->url('billing.clients.store'), ['name' => '', 'email' => 'nope', 'tax_rate' => '101'])
            ->assertSessionHasErrors(['name', 'email', 'tax_rate']);
        $this->actingAs($this->admin)->post($this->url('billing.clients.store'), ['name' => 'Taken'])->assertSessionHasErrors('name');
        $this->actingAs($this->admin)->post($this->url('billing.clients.store'), ['name' => 'X', 'tax_rate' => '19.555'])->assertSessionHasErrors('tax_rate');
        $this->actingAs($this->admin)->post($this->url('billing.clients.store'), ['name' => 'X', 'tax_rate' => '-1'])->assertSessionHasErrors('tax_rate');
        $this->actingAs($this->admin)->post($this->url('billing.clients.store'), ['name' => str_repeat('x', 161)])->assertSessionHasErrors('name');
        $this->assertSame(1, Client::count());

        // the same name is fine in another workspace
        $other = Workspace::factory()->create(['owner_id' => $this->admin->id]);
        $other->addUser($this->admin, 'admin');
        $this->actingAs($this->admin)->post(route('billing.clients.store', $other->slug), ['name' => 'Taken'])->assertSessionHasNoErrors();
        $this->assertSame(2, Client::count());
    }

    public function test_archived_clients_are_hidden_by_default_and_can_be_restored(): void
    {
        $active = $this->client(['name' => 'Active']);
        $archived = $this->client(['name' => 'Old customer']);

        $this->actingAs($this->admin)->post($this->url('billing.clients.archive', ['client' => $archived->id]))->assertRedirect();
        $this->assertNotNull($archived->fresh()->archived_at);

        $this->actingAs($this->admin)->get($this->url('billing.clients.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->has('clients', 1)->where('clients.0.name', 'Active')->where('archivedCount', 1));
        $this->actingAs($this->admin)->get($this->url('billing.clients.index', ['archived' => 1]))->assertOk()
            ->assertInertia(fn ($page) => $page->has('clients', 2)->where('clients.1.name', 'Old customer')->where('clients.1.archived', true));

        $this->actingAs($this->admin)->post($this->url('billing.clients.restore', ['client' => $archived->id]))->assertRedirect();
        $this->assertNull($archived->fresh()->archived_at);
        $this->assertNull($active->fresh()->archived_at);
    }

    public function test_members_and_outsiders_get_403(): void
    {
        $client = $this->client();
        $projectAdmin = $this->projectMember('admin');

        foreach ([$this->editor, $this->viewer, $projectAdmin, $this->outsider] as $user) {
            $this->actingAs($user)->get($this->url('billing.clients.index'))->assertForbidden();
            $this->actingAs($user)->post($this->url('billing.clients.store'), ['name' => 'Sneaky'])->assertForbidden();
            $this->actingAs($user)->put($this->url('billing.clients.update', ['client' => $client->id]), ['name' => 'Sneaky'])->assertForbidden();
            $this->actingAs($user)->post($this->url('billing.clients.archive', ['client' => $client->id]))->assertForbidden();
            $this->actingAs($user)->post($this->url('billing.clients.restore', ['client' => $client->id]))->assertForbidden();
            $this->actingAs($user)->put($this->url('billing.clients.projects.update', ['client' => $client->id]), ['project_ids' => [$this->project->id]])->assertForbidden();
        }
        $this->assertSame('Muster GmbH', $client->fresh()->name);
        $this->assertSame(1, Client::count());
        $this->assertNull($this->project->fresh()->client_id);
    }

    public function test_a_client_of_another_workspace_is_not_found_under_this_one(): void
    {
        $other = Workspace::factory()->create(['owner_id' => $this->admin->id]);
        $other->addUser($this->admin, 'admin');
        $foreign = Client::create(['workspace_id' => $other->id, 'name' => 'Foreign']);

        $this->actingAs($this->admin)->put($this->url('billing.clients.update', ['client' => $foreign->id]), ['name' => 'Hijacked'])->assertNotFound();
        $this->actingAs($this->admin)->post($this->url('billing.clients.archive', ['client' => $foreign->id]))->assertNotFound();
        $this->actingAs($this->admin)->post($this->url('billing.clients.restore', ['client' => $foreign->id]))->assertNotFound();
        $this->actingAs($this->admin)->put($this->url('billing.clients.projects.update', ['client' => $foreign->id]), ['project_ids' => [$this->project->id]])->assertNotFound();
        $this->assertSame('Foreign', $foreign->fresh()->name);
    }

    public function test_the_client_page_assigns_moves_and_unassigns_projects(): void
    {
        $client = $this->client();
        $rival = $this->client(['name' => 'Rival']);
        $second = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->admin->id, 'key' => 'APP']);
        $third = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->admin->id, 'key' => 'OPS']);
        $third->forceFill(['client_id' => $rival->id])->save();

        $this->actingAs($this->admin)->put($this->url('billing.clients.projects.update', ['client' => $client->id]), ['project_ids' => [$this->project->id, $third->id]])
            ->assertSessionHasNoErrors();
        $this->assertSame($client->id, $this->project->fresh()->client_id);
        $this->assertSame($client->id, $third->fresh()->client_id); // moved from the rival
        $this->assertNull($second->fresh()->client_id);

        $this->actingAs($this->admin)->get($this->url('billing.clients.index'))
            ->assertInertia(fn ($page) => $page->where('clients.0.name', 'Muster GmbH')->has('clients.0.projects', 2)->has('projects', 3));

        $this->actingAs($this->admin)->put($this->url('billing.clients.projects.update', ['client' => $client->id]), ['project_ids' => [$second->id]])
            ->assertSessionHasNoErrors();
        $this->assertNull($this->project->fresh()->client_id);
        $this->assertNull($third->fresh()->client_id);
        $this->assertSame($client->id, $second->fresh()->client_id);

        $this->actingAs($this->admin)->put($this->url('billing.clients.projects.update', ['client' => $client->id]), ['project_ids' => []])
            ->assertSessionHasNoErrors();
        $this->assertNull($second->fresh()->client_id);
    }

    public function test_projects_of_another_workspace_are_refused(): void
    {
        $client = $this->client();
        $foreign = Project::factory()->create(['key' => 'FOR']);

        $this->actingAs($this->admin)->put($this->url('billing.clients.projects.update', ['client' => $client->id]), ['project_ids' => [$this->project->id, $foreign->id]])
            ->assertSessionHasErrors('project_ids.1');
        $this->assertNull($foreign->fresh()->client_id);
        $this->assertNull($this->project->fresh()->client_id);
    }

    public function test_project_settings_assign_a_client_for_workspace_admins_only(): void
    {
        $client = $this->client();
        $archived = $this->client(['name' => 'Archived', 'archived_at' => now()]);
        $settings = route('projects.update', [$this->workspace->slug, $this->project->slug]);
        $edit = route('projects.edit', [$this->workspace->slug, $this->project->slug]);

        $this->actingAs($this->admin)->get($edit)->assertOk()
            ->assertInertia(fn ($page) => $page->has('billingClients', 1)->where('billingClients.0.name', 'Muster GmbH'));

        $this->actingAs($this->admin)->put($settings, ['name' => $this->project->name, 'client_id' => $client->id])->assertSessionHasNoErrors();
        $this->assertSame($client->id, $this->project->fresh()->client_id);

        // a project admin who isn't a workspace admin: no select, and the field is ignored
        $projectAdmin = $this->projectMember('admin');
        $this->actingAs($projectAdmin)->get($edit)->assertOk()->assertInertia(fn ($page) => $page->where('billingClients', null));
        $this->actingAs($projectAdmin)->put($settings, ['name' => 'Renamed', 'client_id' => null])->assertSessionHasNoErrors();
        $this->assertSame(['Renamed', $client->id], [$this->project->fresh()->name, $this->project->fresh()->client_id]);

        // a client of another workspace is refused
        $foreign = Client::create(['workspace_id' => Workspace::factory()->create()->id, 'name' => 'Foreign']);
        $this->actingAs($this->admin)->put($settings, ['name' => 'Renamed', 'client_id' => $foreign->id])->assertSessionHasErrors('client_id');
        $this->assertSame($client->id, $this->project->fresh()->client_id);

        // saving without the field (other callers) keeps the client; empty unassigns
        $this->actingAs($this->admin)->put($settings, ['name' => 'Renamed again'])->assertSessionHasNoErrors();
        $this->assertSame($client->id, $this->project->fresh()->client_id);
        $this->actingAs($this->admin)->put($settings, ['name' => 'Renamed again', 'client_id' => ''])->assertSessionHasNoErrors();
        $this->assertNull($this->project->fresh()->client_id);

        // an archived client can't be picked …
        $this->actingAs($this->admin)->put($settings, ['name' => 'Renamed again', 'client_id' => $archived->id])->assertSessionHasErrors('client_id');

        // … but the current client stays selectable after it was archived
        $this->project->forceFill(['client_id' => $archived->id])->save();
        $this->actingAs($this->admin)->get($edit)
            ->assertInertia(fn ($page) => $page->has('billingClients', 2)->where('billingClients.0.archived', true));
        $this->actingAs($this->admin)->put($settings, ['name' => 'Renamed again', 'client_id' => $archived->id])->assertSessionHasNoErrors();
    }

    public function test_a_moved_project_leaves_its_client_behind(): void
    {
        $client = $this->client();
        $this->project->forceFill(['client_id' => $client->id])->save();
        $target = Workspace::factory()->create(['owner_id' => $this->admin->id]);
        $target->addUser($this->admin, 'admin');

        ProjectTransfer::move($this->project, $target);

        $this->assertNull($this->project->fresh()->client_id);
        $this->actingAs($this->admin)->get($this->url('billing.clients.index'))
            ->assertInertia(fn ($page) => $page->has('clients.0.projects', 0)->has('projects', 0));
    }
}
