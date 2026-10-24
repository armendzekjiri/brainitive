<?php

namespace Tests\Feature\Billing;

use App\Models\BillingActivity;
use App\Models\BillingActivityDefault;
use App\Models\BillingSetting;
use App\Models\ItemType;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Billing → Activities (BRAI-99): workspace admins create, price, archive, reorder and delete
 * activities and map issue types to a default activity. Members and outsiders get 403, and no
 * price reaches a member.
 */
class BillingActivitiesTest extends TestCase
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

    private function url(string $name, mixed ...$extra): string
    {
        return route($name, [$this->workspace->slug, ...$extra]);
    }

    private function activity(array $attributes = []): BillingActivity
    {
        return BillingActivity::create($attributes + [
            'workspace_id' => $this->workspace->id,
            'name' => 'Programming',
            'pricing_type' => BillingActivity::HOURLY,
            'rate_cents' => 12000,
        ]);
    }

    private function logTime(BillingActivity $activity): TimeEntry
    {
        return TimeEntry::create([
            'workspace_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'user_id' => $this->editor->id,
            'billing_activity_id' => $activity->id,
            'spent_on' => '2026-10-03',
            'minutes' => 60,
        ]);
    }

    private function bugType(): ItemType
    {
        return ItemType::global()->where('slug', 'bug')->firstOrFail();
    }

    /**
     * A second workspace (another admin) with its own project, item type and activity.
     *
     * @return array{0: Workspace, 1: ItemType, 2: BillingActivity}
     */
    private function foreignWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addUser($owner, 'admin');
        $project = Project::factory()->create(['workspace_id' => $workspace->id, 'created_by' => $owner->id, 'key' => 'OTHER']);
        $type = ItemType::create(['project_id' => $project->id, 'name' => 'Foreign type']);
        $activity = BillingActivity::create(['workspace_id' => $workspace->id, 'name' => 'Foreign', 'pricing_type' => 'hourly', 'rate_cents' => 100]);

        return [$workspace, $type, $activity];
    }

    public function test_admin_sees_activities_currency_and_the_workspaces_item_types(): void
    {
        BillingSetting::forWorkspace($this->workspace)->fill(['currency' => 'CHF'])->save();
        $second = $this->activity(['name' => 'Testing', 'rate_cents' => 4500, 'position' => 2]);
        $first = $this->activity(['name' => 'SSM setup', 'pricing_type' => 'fixed', 'rate_cents' => 45000, 'position' => 1]);
        $archived = $this->activity(['name' => 'Old', 'position' => 0, 'archived_at' => now()]);
        $this->logTime($second);

        $ownType = ItemType::create(['project_id' => $this->project->id, 'name' => 'Change request']);
        $inactiveGlobal = ItemType::create(['name' => 'Retired', 'is_active' => false]);
        [, $foreignType] = $this->foreignWorkspace();
        $bug = $this->bugType();
        BillingActivityDefault::create(['workspace_id' => $this->workspace->id, 'item_type_id' => $bug->id, 'billing_activity_id' => $first->id]);

        // feature tests skip the Vite manifest, so make sure the page really exists (KNOWLEDGE)
        $this->assertFileExists(resource_path('js/Pages/Billing/Activities/Index.tsx'));

        $this->actingAs($this->admin)->get($this->url('billing.activities.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Billing/Activities/Index')
                ->where('currency', 'CHF')
                // active by position, archived last
                ->where('activities.0.id', $first->id)
                ->where('activities.0.pricing_type', 'fixed')
                ->where('activities.0.rate_cents', 45000)
                ->where('activities.0.used', false)
                ->where('activities.1.id', $second->id)
                ->where('activities.1.used', true)
                ->where('activities.2.id', $archived->id)
                ->where('itemTypes', function ($types) use ($ownType, $inactiveGlobal, $foreignType, $bug, $first) {
                    $byId = collect($types)->keyBy('id');

                    return $byId->has($ownType->id)
                        && $byId[$ownType->id]['project'] === $this->project->name
                        && $byId->has($bug->id) && $byId[$bug->id]['project'] === null
                        && $byId[$bug->id]['billing_activity_id'] === $first->id
                        && ! $byId->has($inactiveGlobal->id)
                        && ! $byId->has($foreignType->id);
                }));
    }

    public function test_the_currency_defaults_to_eur_and_there_are_no_seeded_activities(): void
    {
        $this->actingAs($this->admin)->get($this->url('billing.activities.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('currency', 'EUR')->has('activities', 0));
    }

    public function test_admin_creates_hourly_and_fixed_activities_with_prices_in_cents(): void
    {
        $this->actingAs($this->admin)->post($this->url('billing.activities.store'), [
            'name' => 'Programming', 'pricing_type' => 'hourly', 'price' => '120', 'billable' => true, 'color' => '#0E83DC',
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->actingAs($this->admin)->post($this->url('billing.activities.store'), [
            'name' => '  SSM setup ', 'pricing_type' => 'fixed', 'price' => '450.50', 'billable' => false,
        ])->assertSessionHasNoErrors();

        $programming = BillingActivity::where('name', 'Programming')->firstOrFail();
        $this->assertSame(12000, $programming->rate_cents);
        $this->assertSame('hourly', $programming->pricing_type);
        $this->assertTrue($programming->billable);
        $this->assertSame('#0E83DC', $programming->color);

        $ssm = BillingActivity::where('name', 'SSM setup')->firstOrFail();
        $this->assertSame(45050, $ssm->rate_cents);
        $this->assertTrue($ssm->isFixed());
        $this->assertFalse($ssm->billable);
        $this->assertNull($ssm->color);
        $this->assertGreaterThan($programming->position, $ssm->position);
    }

    public function test_admin_updates_an_activity(): void
    {
        $activity = $this->activity();

        $this->actingAs($this->admin)->put($this->url('billing.activities.update', $activity->id), [
            'name' => 'Programming (senior)', 'pricing_type' => 'fixed', 'price' => '0', 'billable' => false, 'color' => null,
        ])->assertSessionHasNoErrors();

        $activity->refresh();
        $this->assertSame('Programming (senior)', $activity->name);
        $this->assertSame('fixed', $activity->pricing_type);
        $this->assertSame(0, $activity->rate_cents);
        $this->assertFalse($activity->billable);
    }

    public function test_the_name_is_unique_per_workspace_case_insensitive_archived_included(): void
    {
        $this->activity(['name' => 'Programming']);
        $this->activity(['name' => 'Old work', 'archived_at' => now()]);
        $valid = ['pricing_type' => 'hourly', 'price' => '10', 'billable' => true];

        $this->actingAs($this->admin)->post($this->url('billing.activities.store'), ['name' => 'programming'] + $valid)
            ->assertSessionHasErrors(['name' => 'Another activity already uses this name.']);
        $this->actingAs($this->admin)->post($this->url('billing.activities.store'), ['name' => 'OLD WORK'] + $valid)
            ->assertSessionHasErrors('name');

        // another workspace may use the same name, and an activity keeps its own name on save
        [$foreign] = $this->foreignWorkspace();
        $this->actingAs($foreign->owner)->post(route('billing.activities.store', $foreign->slug), ['name' => 'Programming'] + $valid)
            ->assertSessionHasNoErrors();
        $own = BillingActivity::where('workspace_id', $this->workspace->id)->where('name', 'Programming')->firstOrFail();
        $this->actingAs($this->admin)->put($this->url('billing.activities.update', $own->id), ['name' => 'Programming'] + $valid)
            ->assertSessionHasNoErrors();

        $this->assertSame(2, BillingActivity::where('workspace_id', $this->workspace->id)->count());
    }

    public function test_price_pricing_type_and_the_other_fields_are_validated(): void
    {
        $valid = ['name' => 'Testing', 'pricing_type' => 'hourly', 'price' => '45', 'billable' => true];

        foreach ([
            ['price', ['price' => '-1']],
            ['price', ['price' => '1.234']],
            ['price', ['price' => 'abc']],
            ['price', ['price' => '']],
            ['pricing_type', ['pricing_type' => 'daily']],
            ['name', ['name' => '']],
            ['name', ['name' => str_repeat('x', 81)]],
            ['color', ['color' => 'red; background: url(x)']],
            ['billable', ['billable' => 'maybe']],
        ] as [$field, $override]) {
            $this->actingAs($this->admin)->post($this->url('billing.activities.store'), $override + $valid)
                ->assertSessionHasErrors($field);
        }

        $this->assertSame(0, BillingActivity::count());

        // zero is a valid price (e.g. internal, unbilled work)
        $this->actingAs($this->admin)->post($this->url('billing.activities.store'), ['price' => '0'] + $valid)->assertSessionHasNoErrors();
    }

    public function test_archive_hides_restore_brings_back_and_archiving_clears_its_defaults(): void
    {
        $activity = $this->activity();
        BillingActivityDefault::create(['workspace_id' => $this->workspace->id, 'item_type_id' => $this->bugType()->id, 'billing_activity_id' => $activity->id]);
        $this->logTime($activity);

        $this->actingAs($this->admin)->post($this->url('billing.activities.archive', $activity->id))->assertSessionHasNoErrors();
        $this->assertNotNull($activity->fresh()->archived_at);
        $this->assertSame(0, BillingActivityDefault::count());
        $this->assertSame($activity->id, TimeEntry::first()->billing_activity_id); // stays on old entries
        $this->assertSame(0, BillingActivity::active()->count());

        $this->actingAs($this->admin)->post($this->url('billing.activities.restore', $activity->id))->assertSessionHasNoErrors();
        $this->assertNull($activity->fresh()->archived_at);
    }

    public function test_admin_reorders_activities_within_the_workspace_only(): void
    {
        $a = $this->activity(['name' => 'A', 'position' => 1]);
        $b = $this->activity(['name' => 'B', 'position' => 2]);
        $c = $this->activity(['name' => 'C', 'position' => 3]);

        $this->actingAs($this->admin)->post($this->url('billing.activities.reorder'), ['ids' => [$c->id, $a->id, $b->id]])
            ->assertSessionHasNoErrors();
        $this->assertSame([$c->id, $a->id, $b->id], BillingActivity::orderBy('position')->pluck('id')->all());

        [, , $foreign] = $this->foreignWorkspace();
        $this->actingAs($this->admin)->post($this->url('billing.activities.reorder'), ['ids' => [$foreign->id, $a->id]])
            ->assertSessionHasErrors('ids.0');
    }

    public function test_an_unused_activity_is_deleted_a_used_one_must_be_archived(): void
    {
        $unused = $this->activity(['name' => 'Unused']);
        $used = $this->activity(['name' => 'Used']);
        $this->logTime($used);

        $this->actingAs($this->admin)->delete($this->url('billing.activities.destroy', $unused->id))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertModelMissing($unused);

        $this->actingAs($this->admin)->from($this->url('billing.activities.index'))
            ->delete($this->url('billing.activities.destroy', $used->id))
            ->assertRedirect($this->url('billing.activities.index'))
            ->assertSessionHasErrors('activity');
        $this->assertModelExists($used);
        $this->assertStringContainsString('Archive it instead', session('errors')->first('activity'));
    }

    public function test_activities_of_another_workspace_are_not_found(): void
    {
        [, , $foreign] = $this->foreignWorkspace();
        $valid = ['name' => 'X', 'pricing_type' => 'hourly', 'price' => '1', 'billable' => true];

        $this->actingAs($this->admin)->put($this->url('billing.activities.update', $foreign->id), $valid)->assertNotFound();
        $this->actingAs($this->admin)->post($this->url('billing.activities.archive', $foreign->id))->assertNotFound();
        $this->actingAs($this->admin)->post($this->url('billing.activities.restore', $foreign->id))->assertNotFound();
        $this->actingAs($this->admin)->delete($this->url('billing.activities.destroy', $foreign->id))->assertNotFound();
        $this->assertModelExists($foreign);
        $this->assertSame('Foreign', $foreign->fresh()->name);
    }

    public function test_admin_sets_changes_and_clears_a_default_activity(): void
    {
        $programming = $this->activity();
        $testing = $this->activity(['name' => 'Testing']);
        $bug = $this->bugType();
        $ownType = ItemType::create(['project_id' => $this->project->id, 'name' => 'Change request']);
        $url = $this->url('billing.activities.defaults.update');

        $this->actingAs($this->admin)->put($url, ['item_type_id' => $bug->id, 'billing_activity_id' => $programming->id])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->put($url, ['item_type_id' => $ownType->id, 'billing_activity_id' => $testing->id])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->put($url, ['item_type_id' => $bug->id, 'billing_activity_id' => $testing->id])->assertSessionHasNoErrors();

        $this->assertSame(2, BillingActivityDefault::count());
        $this->assertSame($testing->id, BillingActivityDefault::where('item_type_id', $bug->id)->value('billing_activity_id'));

        $this->actingAs($this->admin)->put($url, ['item_type_id' => $bug->id, 'billing_activity_id' => null])->assertSessionHasNoErrors();
        $this->assertFalse(BillingActivityDefault::where('item_type_id', $bug->id)->exists());
        $this->assertTrue(BillingActivityDefault::where('item_type_id', $ownType->id)->exists());
    }

    public function test_defaults_reject_foreign_item_types_and_foreign_or_archived_activities(): void
    {
        $programming = $this->activity();
        $archived = $this->activity(['name' => 'Old', 'archived_at' => now()]);
        $bug = $this->bugType();
        $inactiveGlobal = ItemType::create(['name' => 'Retired', 'is_active' => false]);
        [, $foreignType, $foreignActivity] = $this->foreignWorkspace();
        $url = $this->url('billing.activities.defaults.update');

        $this->actingAs($this->admin)->put($url, ['item_type_id' => $foreignType->id, 'billing_activity_id' => $programming->id])->assertSessionHasErrors('item_type_id');
        $this->actingAs($this->admin)->put($url, ['item_type_id' => $inactiveGlobal->id, 'billing_activity_id' => $programming->id])->assertSessionHasErrors('item_type_id');
        $this->actingAs($this->admin)->put($url, ['item_type_id' => $bug->id, 'billing_activity_id' => $foreignActivity->id])->assertSessionHasErrors('billing_activity_id');
        $this->actingAs($this->admin)->put($url, ['item_type_id' => $bug->id, 'billing_activity_id' => $archived->id])->assertSessionHasErrors('billing_activity_id');

        $this->assertSame(0, BillingActivityDefault::count());
    }

    public function test_members_and_outsiders_get_403_everywhere(): void
    {
        $activity = $this->activity();
        $bug = $this->bugType();
        $valid = ['name' => 'Hacked', 'pricing_type' => 'hourly', 'price' => '1', 'billable' => true];

        foreach ([$this->editor, $this->viewer, $this->outsider] as $user) {
            $this->actingAs($user)->get($this->url('billing.index'))->assertForbidden();
            $this->actingAs($user)->get($this->url('billing.activities.index'))->assertForbidden();
            $this->actingAs($user)->post($this->url('billing.activities.store'), $valid)->assertForbidden();
            $this->actingAs($user)->put($this->url('billing.activities.update', $activity->id), $valid)->assertForbidden();
            $this->actingAs($user)->post($this->url('billing.activities.archive', $activity->id))->assertForbidden();
            $this->actingAs($user)->post($this->url('billing.activities.restore', $activity->id))->assertForbidden();
            $this->actingAs($user)->post($this->url('billing.activities.reorder'), ['ids' => [$activity->id]])->assertForbidden();
            $this->actingAs($user)->delete($this->url('billing.activities.destroy', $activity->id))->assertForbidden();
            $this->actingAs($user)->put($this->url('billing.activities.defaults.update'), ['item_type_id' => $bug->id, 'billing_activity_id' => $activity->id])->assertForbidden();
        }

        $this->assertSame(['Programming'], BillingActivity::pluck('name')->all());
        $this->assertNull($activity->fresh()->archived_at);
        $this->assertSame(0, BillingActivityDefault::count());
    }

    public function test_no_price_reaches_a_member(): void
    {
        $activity = $this->activity(['rate_cents' => 987654]);
        BillingActivityDefault::create(['workspace_id' => $this->workspace->id, 'item_type_id' => $this->bugType()->id, 'billing_activity_id' => $activity->id]);
        $this->logTime($activity);

        foreach ([
            route('workspaces.show', $this->workspace->slug),
            route('projects.show', [$this->workspace->slug, $this->project->slug]),
            route('boards.show', [$this->workspace->slug, $this->project->slug, $this->board->slug]),
            $this->url('billing.activities.index'),
        ] as $url) {
            $content = $this->actingAs($this->editor)->get($url)->getContent();
            $this->assertStringNotContainsString('987654', $content, $url);
            $this->assertStringNotContainsString('9876.54', $content, $url);
            $this->assertStringNotContainsString('rate_cents', $content, $url);
        }
    }
}
