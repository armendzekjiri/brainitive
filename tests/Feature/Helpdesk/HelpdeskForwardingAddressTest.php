<?php

namespace Tests\Feature\Helpdesk;

use App\Models\Helpdesk;
use App\Models\User;
use App\Support\Helpdesks\Inbound\ForwardingAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * BRAI-124: Helpdesk settings → Email → "Forwarding address" (helpdesks.forwarding.*), for whoever
 * may edit the helpdesk.
 */
class HelpdeskForwardingAddressTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    private Helpdesk $helpdesk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        config([
            'services.inbound_mail.domain' => 'Inbound.Example',
            'services.inbound_mail.provider' => 'mailgun',
            'services.inbound_mail.mailgun_signing_key' => 'mg-key',
            'services.inbound_mail.postmark_token' => null,
        ]);
        $this->helpdesk = Helpdesk::create(['project_id' => $this->project->id, 'name' => 'Customer Support', 'slug' => 'customer-support', 'created_by' => User::find($this->workspace->owner_id)->id]);
    }

    private function route(string $name): string
    {
        return route($name, [$this->workspace->slug, $this->project->slug, $this->helpdesk->slug]);
    }

    public function test_editor_gets_a_stable_address_on_the_configured_domain(): void
    {
        $first = $this->actingAs($this->editor)->getJson($this->route('helpdesks.forwarding.show'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJson(['enabled' => true, 'provider' => 'mailgun'])
            ->json('address');

        $this->assertMatchesRegularExpression('/^customer-support-[a-z0-9]{10}@inbound\.example$/', $first);
        $this->actingAs($this->editor)->getJson($this->route('helpdesks.forwarding.show'))->assertJson(['address' => $first]);
        $this->assertTrue(ForwardingAddress::resolve(strtoupper($first))->is($this->helpdesk));
    }

    public function test_regenerate_replaces_the_address(): void
    {
        $old = $this->actingAs($this->editor)->getJson($this->route('helpdesks.forwarding.show'))->json('address');
        $new = $this->actingAs($this->editor)->postJson($this->route('helpdesks.forwarding.regenerate'))->assertOk()->json('address');

        $this->assertNotSame($old, $new);
        $this->assertNull(ForwardingAddress::resolve($old));
        $this->assertTrue(ForwardingAddress::resolve($new)->is($this->helpdesk));
    }

    public function test_only_people_who_may_edit_the_helpdesk(): void
    {
        $this->actingAs($this->viewer)->getJson($this->route('helpdesks.forwarding.show'))->assertForbidden();
        $this->actingAs($this->viewer)->postJson($this->route('helpdesks.forwarding.regenerate'))->assertForbidden();
        $this->actingAs($this->outsider)->getJson($this->route('helpdesks.forwarding.show'))->assertForbidden();
        $this->assertNull($this->helpdesk->fresh()->forwarding_local_part, 'no address was created');
    }

    public function test_disabled_instance_explains_instead_of_creating_an_address(): void
    {
        config(['services.inbound_mail.mailgun_signing_key' => null]);

        $this->actingAs($this->editor)->getJson($this->route('helpdesks.forwarding.show'))
            ->assertOk()->assertExactJson(['enabled' => false, 'address' => null, 'provider' => null]);
        $this->actingAs($this->editor)->postJson($this->route('helpdesks.forwarding.regenerate'))->assertNotFound();
        $this->assertNull($this->helpdesk->fresh()->forwarding_local_part);
    }

    public function test_the_address_never_reaches_other_props(): void
    {
        ForwardingAddress::for($this->helpdesk);
        $this->assertNotNull($this->helpdesk->fresh()->forwarding_local_part);
        $this->assertArrayNotHasKey('forwarding_local_part', $this->helpdesk->fresh()->toArray());

        $this->actingAs($this->editor)->get(route('helpdesks.edit', [$this->workspace->slug, $this->project->slug, $this->helpdesk->slug]))
            ->assertOk()
            ->assertDontSee($this->helpdesk->fresh()->forwarding_local_part);
    }
}
