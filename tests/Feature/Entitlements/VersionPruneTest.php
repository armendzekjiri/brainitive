<?php

namespace Tests\Feature\Entitlements;

use App\Models\Page;
use App\Models\PageVersion;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\Project;
use App\Models\Workspace;
use App\Support\Entitlements\ConfigEntitlements;
use App\Support\Entitlements\Entitlements;
use App\Support\Entitlements\Limits;
use App\Support\Entitlements\VersionRetention;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** v1.46.0: versions:prune keeps the newest N versions + everything younger than N days. */
class VersionPruneTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $workspace = Workspace::factory()->create(['slug' => 'limited-acme']);
        $this->project = Project::factory()->create(['workspace_id' => $workspace->id]);
    }

    /** Starter-like: the newest 2 forever, older ones 30 days; only `limited-` workspaces. */
    private function useRetention(int $keepLatest = 2, ?int $keepDays = 30, bool $enforced = true): void
    {
        $this->app->instance(Entitlements::class, new ConfigEntitlements([
            'enforced' => $enforced, 'only_slug_prefix' => 'limited-', 'versions_keep_latest' => $keepLatest, 'versions_keep_days' => $keepDays,
        ]));
    }

    /**
     * A page with one version per age (days ago), oldest first; the page is at the newest one.
     *
     * @param  list<int>  $ages
     */
    private function pageWithVersions(array $ages, ?Project $project = null): Page
    {
        $page = Page::factory()->create(['project_id' => ($project ?? $this->project)->id, 'status' => 'published']);
        foreach ($ages as $i => $age) {
            $version = PageVersion::factory()->create(['page_id' => $page->id, 'version' => $i + 1, 'created_by' => $page->created_by]);
            $version->forceFill(['created_at' => now()->subDays($age)])->save();
        }
        $page->forceFill(['current_version' => count($ages)])->save();

        return $page;
    }

    /** @param  list<int>  $ages */
    private function planWithVersions(array $ages): Plan
    {
        $plan = Plan::factory()->create(['project_id' => $this->project->id]);
        foreach ($ages as $i => $age) {
            $version = $plan->versions()->create(['version' => $i + 1, 'name' => $plan->name, 'data' => $plan->data]);
            $version->forceFill(['created_at' => now()->subDays($age)])->save();
        }
        $plan->forceFill(['current_version' => count($ages)])->save();

        return $plan;
    }

    /** @return list<int> */
    private function versionsOf(Page|Plan $owner): array
    {
        return $owner->versions()->reorder('version')->pluck('version')->all();
    }

    private function shareWithClients(PageVersion $version): void
    {
        $portal = DB::table('client_portals')->insertGetId(['uuid' => (string) Str::uuid(), 'project_id' => $this->project->id, 'slug' => 'client', 'title' => 'Client', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('client_deliverables')->insert([
            'uuid' => (string) Str::uuid(), 'client_portal_id' => $portal, 'kind' => 'page', 'page_id' => $version->page_id, 'page_version_id' => $version->id,
            'version' => 1, 'title' => 'Shared', 'content' => '{}', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_keeps_the_newest_versions_and_everything_younger_than_the_retention(): void
    {
        $this->useRetention(keepLatest: 2, keepDays: 30);
        $page = $this->pageWithVersions([90, 60, 45, 10, 40, 1]); // v4 is young; v5 + v6 are the newest two
        $plan = $this->planWithVersions([90, 60, 1]);

        $this->artisan('versions:prune')->expectsOutputToContain('Deleted 3 doc version(s) and 1 plan version(s).')->assertSuccessful();

        $this->assertSame([4, 5, 6], $this->versionsOf($page));
        $this->assertSame([2, 3], $this->versionsOf($plan));
    }

    public function test_never_deletes_the_current_or_a_shared_version_and_always_keeps_the_newest(): void
    {
        // Free-like: no version is kept forever, everything goes after 7 days
        $this->useRetention(keepLatest: 0, keepDays: 7);
        $page = $this->pageWithVersions([90, 80, 70, 60, 50]);
        $page->forceFill(['current_version' => 2])->save(); // the page still points at v2
        $this->shareWithClients($page->versions()->where('version', 3)->sole());
        $plan = $this->planWithVersions([90, 80]);

        $this->artisan('versions:prune')->assertSuccessful();

        $this->assertSame([2, 3, 5], $this->versionsOf($page));
        $this->assertSame([2], $this->versionsOf($plan));
    }

    public function test_dry_run_only_counts(): void
    {
        $this->useRetention(keepLatest: 1, keepDays: 30);
        $page = $this->pageWithVersions([90, 60, 1]);

        $this->artisan('versions:prune', ['--dry-run' => true])
            ->expectsOutputToContain('limited-acme: 2 doc version(s), 0 plan version(s)')
            ->expectsOutputToContain('Would delete 2 doc version(s) and 0 plan version(s).')
            ->assertSuccessful();

        $this->assertSame([1, 2, 3], $this->versionsOf($page));
    }

    public function test_nothing_is_pruned_when_unlimited_not_enforced_keeping_everything_or_other_workspaces(): void
    {
        $page = $this->pageWithVersions([90, 60, 1]);
        $other = $this->pageWithVersions([90, 60, 1], Project::factory()->create(['workspace_id' => Workspace::factory()->create(['slug' => 'acme'])->id]));

        // self-hosted default
        $this->artisan('versions:prune')->expectsOutputToContain('not enforced')->assertSuccessful();
        // limits shown but not enforced yet (before launch)
        $this->useRetention(keepLatest: 1, keepDays: 7, enforced: false);
        $this->artisan('versions:prune')->assertSuccessful();
        // a plan that keeps every version
        $this->useRetention(keepLatest: 1, keepDays: null);
        $this->artisan('versions:prune')->assertSuccessful();
        $this->assertSame([1, 2, 3], $this->versionsOf($page));

        // the unlimited workspace keeps its history while the limited one is pruned
        $this->useRetention(keepLatest: 1, keepDays: 7);
        $this->artisan('versions:prune')->assertSuccessful();
        $this->assertSame([3], $this->versionsOf($page));
        $this->assertSame([1, 2, 3], $this->versionsOf($other));
    }

    public function test_nothing_is_pruned_during_the_grace_period(): void
    {
        $page = $this->pageWithVersions([90, 60, 1]);
        $grace = fn (?CarbonImmutable $until) => new class($until) implements Entitlements
        {
            public function __construct(private readonly ?CarbonImmutable $until) {}

            public function enforced(): bool
            {
                return true;
            }

            public function limits(Workspace $workspace): Limits
            {
                return new Limits('Free', true, versions: new VersionRetention(0, 7), graceUntil: $this->until);
            }

            public function freeWorkspacesPerOwner(): ?int
            {
                return null;
            }

            public function billingUrl(Workspace $workspace): ?string
            {
                return null;
            }
        };

        $this->app->instance(Entitlements::class, $grace(CarbonImmutable::now()->addDays(30)));
        $this->artisan('versions:prune')->assertSuccessful();
        $this->assertSame([1, 2, 3], $this->versionsOf($page));

        $this->app->instance(Entitlements::class, $grace(CarbonImmutable::now()->subDay()));
        $this->artisan('versions:prune')->assertSuccessful();
        $this->assertSame([3], $this->versionsOf($page));
    }

    public function test_deletes_in_chunks(): void
    {
        $this->useRetention(keepLatest: 1, keepDays: 7);
        $page = $this->pageWithVersions([]);
        $rows = [];
        for ($v = 1; $v <= 1_201; $v++) {
            $rows[] = ['page_id' => $page->id, 'version' => $v, 'name' => 'v', 'content' => '{}', 'created_at' => now()->subDays(30), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('page_versions')->insert($chunk);
        }
        $page->forceFill(['current_version' => 1_201])->save();

        $this->artisan('versions:prune')->expectsOutputToContain('Deleted 1200 doc version(s)')->assertSuccessful();

        $this->assertSame([1_201], $this->versionsOf($page));
        $this->assertSame(0, PlanVersion::count());
    }
}
