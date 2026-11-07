<?php

namespace Tests\Feature\ApiKeys;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\ApiKeys\Concerns\UsesApiKeys;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\ClientPortal\InteractsWithClientPortal;
use Tests\TestCase;

/**
 * Developer API (v1.52.0, Codex review): a client deliverable is Helpdesk-area data, but its snapshot and title are
 * a doc's (Docs) — a key with Helpdesk read and no Docs read gets the deliverable without them.
 */
class ApiKeyDeliverablesTest extends TestCase
{
    use ForgetsGuardsBetweenRequests;
    use InteractsWithClientPortal;
    use RefreshDatabase;
    use UsesApiKeys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpClientPortal();
    }

    public function test_the_snapshot_and_title_need_docs_read(): void
    {
        [, $deliverable] = $this->sharedPage('<p>Secret snapshot text</p>', 'Secret doc name');

        $helpdesk = $this->apiKey($this->admin, ['helpdesk' => 'read']);
        $response = $this->getJson("/api/v1/client-deliverables/{$deliverable->id}", $this->keyHeaders($helpdesk))->assertOk()
            ->assertJsonPath('data.html', null)->assertJsonPath('data.markdown', null)->assertJsonPath('data.title', '');
        $this->assertStringNotContainsString('Secret', $response->getContent());
        $list = $this->getJson("/api/v1/client-portals/{$this->portal->id}/deliverables", $this->keyHeaders($helpdesk))->assertOk();
        $this->assertStringNotContainsString('Secret doc name', $list->getContent());

        $both = $this->apiKey($this->admin, ['helpdesk' => 'read', 'docs' => 'read']);
        $this->getJson("/api/v1/client-deliverables/{$deliverable->id}", $this->keyHeaders($both))->assertOk()
            ->assertJsonPath('data.title', 'Secret doc name');
    }
}
