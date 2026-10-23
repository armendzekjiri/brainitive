<?php

namespace Tests\Feature\Captures;

use App\Models\Capture;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Quick capture (BRAI-70): the private inbox itself — capture, privacy, edit, archive,
 * search. Conversion lives in CaptureConversionTest.
 */
class CaptureInboxTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = User::factory()->create();
        $this->other = User::factory()->create();
    }

    public function test_capture_without_a_project_creates_a_private_entry(): void
    {
        $this->assertFileExists(resource_path('js/Pages/Captures/Index.tsx'));

        $this->actingAs($this->owner)->from('/my-work')->post(route('captures.store'), [
            'text' => "Idea: dark mode for the widget\nMaybe per helpdesk",
            'url' => 'https://example.com/widget',
            'intended_type' => 'issue',
            'source' => 'Call with Ana',
        ])->assertRedirect('/my-work')->assertSessionHas('success');

        $capture = Capture::sole();
        $this->assertSame($this->owner->id, $capture->user_id);
        $this->assertSame('inbox', $capture->state());
        $this->assertNotNull($capture->captured_at);
        $this->assertNotEmpty($capture->uuid);

        $this->actingAs($this->owner)->get(route('captures.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Captures/Index')
                ->has('captures', 1)
                ->where('captures.0.id', $capture->uuid)
                ->where('captures.0.text', "Idea: dark mode for the widget\nMaybe per helpdesk")
                ->where('captures.0.url', 'https://example.com/widget')
                ->where('captures.0.intended_type', 'issue')
                ->where('captures.0.source', 'Call with Ana')
                ->where('captures.0.target', null)
                ->missing('captures.0.user_id')
                ->where('counts', ['inbox' => 1, 'processed' => 0, 'archived' => 0])
                ->where('captureInboxCount', 1));

        // Nobody else sees it — not in their list, not in their badge.
        $this->actingAs($this->other)->get(route('captures.index'))
            ->assertInertia(fn (AssertableInertia $p) => $p->has('captures', 0)
                ->where('counts.inbox', 0)
                ->where('captureInboxCount', 0));
    }

    public function test_guests_cannot_capture_or_list(): void
    {
        $this->get(route('captures.index'))->assertRedirect(route('login'));
        $this->post(route('captures.store'), ['text' => 'x'])->assertRedirect(route('login'));
        $this->assertSame(0, Capture::count());
    }

    public function test_capture_input_is_validated(): void
    {
        $this->actingAs($this->owner);

        $this->post(route('captures.store'), ['text' => '   '])->assertSessionHasErrors('text');
        $this->post(route('captures.store'), ['text' => 'x', 'url' => 'javascript:alert(1)'])->assertSessionHasErrors('url');
        $this->post(route('captures.store'), ['text' => 'x', 'url' => 'not a url'])->assertSessionHasErrors('url');
        $this->post(route('captures.store'), ['text' => 'x', 'intended_type' => 'helpdesk'])->assertSessionHasErrors('intended_type');
        $this->post(route('captures.store'), ['text' => str_repeat('a', Capture::MAX_TEXT + 1)])->assertSessionHasErrors('text');
        // fields a user may not set are ignored
        $this->post(route('captures.store'), ['text' => 'ok', 'user_id' => $this->other->id, 'processed_at' => now()->toIso8601String()])
            ->assertSessionHasNoErrors();

        $capture = Capture::sole();
        $this->assertSame($this->owner->id, $capture->user_id);
        $this->assertNull($capture->processed_at);
    }

    public function test_other_users_get_not_found_for_every_action(): void
    {
        $capture = Capture::factory()->create(['user_id' => $this->owner->id, 'text' => 'Secret plan']);
        $archived = Capture::factory()->archived()->create(['user_id' => $this->owner->id]);

        $this->actingAs($this->other);
        $this->patch(route('captures.update', $capture), ['text' => 'Hijacked'])->assertNotFound();
        $this->post(route('captures.archive', $capture))->assertNotFound();
        $this->post(route('captures.restore', $archived))->assertNotFound();
        $this->delete(route('captures.destroy', $archived))->assertNotFound();
        $this->getJson(route('captures.preview', [$capture, 'type' => 'issue']))->assertNotFound();
        $this->postJson(route('captures.convert', $capture), ['type' => 'issue', 'destination_id' => 1, 'fields' => ['title' => 'x']])->assertNotFound();

        $this->assertSame('Secret plan', $capture->fresh()->text);
        $this->assertNull($capture->fresh()->archived_at);
        $this->assertNotNull($archived->fresh()->archived_at);
    }

    public function test_owner_edits_archives_restores_and_deletes(): void
    {
        $capture = Capture::factory()->create(['user_id' => $this->owner->id, 'text' => 'First draft']);
        $this->actingAs($this->owner);

        $this->patch(route('captures.update', $capture), [
            'text' => 'Better wording',
            'url' => 'https://example.com',
            'intended_type' => 'page',
            'source' => '',
        ])->assertSessionHasNoErrors();
        $capture->refresh();
        $this->assertSame('Better wording', $capture->text);
        $this->assertSame('page', $capture->intended_type);
        $this->assertNull($capture->source);

        $this->patch(route('captures.update', $capture), ['text' => ''])->assertSessionHasErrors('text');

        // only archived captures can be deleted
        $this->delete(route('captures.destroy', $capture))->assertStatus(422);

        $this->post(route('captures.archive', $capture))->assertSessionHas('success');
        $this->assertSame('archived', $capture->fresh()->state());
        $this->get(route('captures.index', ['state' => 'archived']))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('state', 'archived')->has('captures', 1)
                ->where('counts', ['inbox' => 0, 'processed' => 0, 'archived' => 1]));

        $this->post(route('captures.restore', $capture));
        $this->assertSame('inbox', $capture->fresh()->state());

        $this->post(route('captures.archive', $capture));
        $this->delete(route('captures.destroy', $capture))->assertSessionHas('success');
        $this->assertSame(0, Capture::count());
    }

    public function test_search_is_case_insensitive_and_only_covers_own_captures(): void
    {
        Capture::factory()->create(['user_id' => $this->owner->id, 'text' => 'Try Kafka for events']);
        Capture::factory()->create(['user_id' => $this->owner->id, 'text' => 'Unrelated', 'url' => 'https://kafka.apache.org']);
        Capture::factory()->create(['user_id' => $this->owner->id, 'text' => 'Groceries', 'source' => 'KAFKA meetup']);
        Capture::factory()->create(['user_id' => $this->owner->id, 'text' => 'Nothing to see']);
        Capture::factory()->create(['user_id' => $this->other->id, 'text' => 'Kafka is mine']);

        $this->actingAs($this->owner)->get(route('captures.index', ['q' => 'kafka']))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('search', 'kafka')->has('captures', 3));
    }

    public function test_tabs_list_newest_first(): void
    {
        $old = Capture::factory()->create(['user_id' => $this->owner->id, 'text' => 'Old', 'captured_at' => now()->subDay()]);
        $new = Capture::factory()->create(['user_id' => $this->owner->id, 'text' => 'New']);
        Capture::factory()->create(['user_id' => $this->owner->id, 'processed_at' => now(), 'target_type' => 'issue', 'target_id' => 999999]);

        $this->actingAs($this->owner)->get(route('captures.index'))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('captures.0.id', $new->uuid)->where('captures.1.id', $old->uuid)
                ->where('counts', ['inbox' => 2, 'processed' => 1, 'archived' => 0]));

        // a target that no longer exists shows as unavailable, never as an error
        $this->actingAs($this->owner)->get(route('captures.index', ['state' => 'processed']))
            ->assertInertia(fn (AssertableInertia $p) => $p->has('captures', 1)->where('captures.0.target.available', false));

        // unknown state falls back to the inbox
        $this->actingAs($this->owner)->get(route('captures.index', ['state' => 'everything']))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('state', 'inbox')->has('captures', 2));
    }
}
