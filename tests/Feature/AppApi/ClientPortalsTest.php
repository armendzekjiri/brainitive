<?php

namespace Tests\Feature\AppApi;

use App\Support\ClientPortal\Deliverables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\ClientPortal\InteractsWithClientPortal;
use Tests\TestCase;

/**
 * App API v1 client portals, team side, read-only (BRAI-422): the portal, its deliverables with the clients'
 * approval state, and one deliverable (snapshot, decisions, conversation, versions) through Deliverables
 * (shared with the web). Every project member reads; clients' addresses only for project admins.
 */
class ClientPortalsTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use InteractsWithClientPortal;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpClientPortal();
    }

    public function test_portal_deliverables_and_one_with_decisions(): void
    {
        [$page, $first] = $this->sharedPage('<p>Version one text</p>');
        $client = $this->client();
        Deliverables::decide($first, $client, 'changes_requested', 'Too long');
        $this->newVersion($page, '<p>Version two text</p>');
        [$second] = Deliverables::publishPage($this->portal, $page->fresh(), $this->author);
        Deliverables::teamComment($second, $this->author, 'Shorter now', false);

        $admin = $this->appHeaders($this->appToken($this->admin));
        $this->getJson("/api/v1/projects/{$this->project->id}/client-portal", $admin)->assertOk()
            ->assertJsonPath('data.slug', 'acme-site')
            ->assertJsonPath('data.can', ['publish' => true, 'manage' => true]);

        $list = $this->getJson("/api/v1/client-portals/{$this->portal->id}/deliverables", $admin)->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame([$second->id, $first->id], array_column($list->json('data'), 'id'));
        $this->assertSame(['awaiting', 'superseded'], array_column($list->json('data'), 'status'));

        $this->getJson("/api/v1/client-deliverables/{$first->id}", $admin)->assertOk()
            ->assertJsonPath('data.decisions.0.decision', 'changes_requested')
            ->assertJsonPath('data.decisions.0.email', 'client@acme.test')
            ->assertJsonPath('data.markdown', 'Version one text')
            ->assertJsonCount(2, 'data.versions');

        // a viewer reads it without the client's address and sees the internal note; an outsider gets 404
        $viewer = $this->appHeaders($this->appToken($this->viewer));
        $this->getJson("/api/v1/client-deliverables/{$first->id}", $viewer)->assertOk()
            ->assertJsonPath('data.decisions.0.email', null)
            ->assertJsonPath('data.decisions.0.name', 'Clara Client');
        $this->getJson("/api/v1/client-deliverables/{$second->id}", $viewer)->assertOk()
            ->assertJsonPath('data.comments.0.visibility', 'internal');
        $this->getJson("/api/v1/projects/{$this->project->id}/client-portal", $viewer)->assertOk()
            ->assertJsonPath('data.can', ['publish' => false, 'manage' => false]);
        $outsider = $this->appHeaders($this->appToken($this->outsider));
        $this->getJson("/api/v1/projects/{$this->project->id}/client-portal", $outsider)->assertNotFound();
        $this->getJson("/api/v1/client-portals/{$this->portal->id}/deliverables", $outsider)->assertNotFound();
        $this->getJson("/api/v1/client-deliverables/{$first->id}", $outsider)->assertNotFound();
    }
}
