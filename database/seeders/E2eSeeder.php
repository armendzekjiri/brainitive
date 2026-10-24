<?php

namespace Database\Seeders;

use App\Models\BillingActivity;
use App\Models\Client;
use App\Models\Item;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Known starting data for the Playwright suite (BRAI-96), run by `php artisan e2e:reset`:
 * an owner and a member, one workspace, one project with the default board and a few issues.
 * Mirrors what the controllers create (WorkspaceController / ProjectController::store) and uses
 * no factories, so it also runs without dev dependencies. Tests create everything else themselves.
 */
class E2eSeeder extends Seeder
{
    public const PASSWORD_ENV = 'E2E_PASSWORD';

    public function run(): void
    {
        $this->call([InstanceSettingsSeeder::class, ItemTypeSeeder::class, ItemPrioritySeeder::class]);

        // Also seeds the e2e users on dev.brainitive.app (post-deploy smoke tests): a second run
        // only refreshes their password and creates nothing — dev keeps its data.
        if (User::where('email', 'e2e-owner@example.test')->exists()) {
            User::whereIn('email', ['e2e-owner@example.test', 'e2e-member@example.test'])
                ->update(['password' => Hash::make((string) env(self::PASSWORD_ENV, 'e2e-password'))]);

            return;
        }

        $owner = $this->user('Erin Owner', 'e2e-owner@example.test');
        $member = $this->user('Max Member', 'e2e-member@example.test');

        $workspace = Workspace::create(['name' => 'E2E Workspace', 'owner_id' => $owner->id]);
        $workspace->addUser($owner, 'admin');
        $workspace->addUser($member, 'member');

        $project = $workspace->projects()->create([
            'name' => 'Website',
            'slug' => 'website',
            'key' => 'WEB',
            'created_by' => $owner->id,
        ]);
        $project->addUser($owner, 'admin');
        $project->addUser($member, 'editor');

        // default columns (To Do / In Progress / Done) come from Board::created
        $board = $project->boards()->create(['name' => 'Main Board', 'slug' => 'main-board', 'type' => 'kanban', 'created_by' => $owner->id]);
        $columns = $board->columns()->orderBy('position')->pluck('id');

        $hero = $this->issue($project, $board->id, $columns[0], 'Homepage hero', $owner);
        $pricing = $this->issue($project, $board->id, $columns[0], 'Pricing page copy', $owner, $member);
        $checkout = $this->issue($project, $board->id, $columns[1], 'Checkout bug', $owner, $owner);

        $this->billingFixtures($workspace, $project, $owner, $member, $hero, $pricing, $checkout);
    }

    /**
     * ── v1.38.0 billing fixtures (BRAI-101 reports, e2e/tests/billing-reports.spec.ts) ──────────
     * Activities Programming 120 €/h + Testing 45 €/h (firstOrCreate by name: the log-time fixtures
     * may create the same two), client "Muster GmbH" on `website`, and time logged last month:
     * WEB-3 Programming 200 min (Erin) + 60 min (Max) = 520.00 €, WEB-1 Programming 45 min (Erin)
     * = 90.00 €, WEB-2 Testing 90 min (Max) = 67.50 € → 677.50 € (no VAT, no rounding by default).
     * Only the reports journey bills them; it voids its report again at the end.
     */
    private function billingFixtures(Workspace $workspace, Project $project, User $owner, User $member, Item $hero, Item $pricing, Item $checkout): void
    {
        $programming = BillingActivity::firstOrCreate(
            ['workspace_id' => $workspace->id, 'name' => 'Programming'],
            ['pricing_type' => BillingActivity::HOURLY, 'rate_cents' => 12000, 'billable' => true, 'position' => 0],
        );
        $testing = BillingActivity::firstOrCreate(
            ['workspace_id' => $workspace->id, 'name' => 'Testing'],
            ['pricing_type' => BillingActivity::HOURLY, 'rate_cents' => 4500, 'billable' => true, 'position' => 1],
        );
        $client = Client::firstOrCreate(['workspace_id' => $workspace->id, 'name' => 'Muster GmbH'], ['email' => 'buchhaltung@muster.example']);
        $project->update(['client_id' => $client->id]);

        $lastMonth = now()->startOfMonth()->subMonthNoOverflow();
        foreach ([
            [$owner, $checkout, $programming, 200, 3, 'Fixed rounding in cart'],
            [$member, $checkout, $programming, 60, 4, 'Code review'],
            [$owner, $hero, $programming, 45, 10, 'Hero layout'],
            [$member, $pricing, $testing, 90, 17, 'Tested pricing copy'],
        ] as [$user, $item, $activity, $minutes, $day, $note]) {
            TimeEntry::create([
                'workspace_id' => $workspace->id,
                'project_id' => $project->id,
                'item_id' => $item->id,
                'user_id' => $user->id,
                'billing_activity_id' => $activity->id,
                'spent_on' => $lastMonth->copy()->day($day)->format('Y-m-d'),
                'minutes' => $minutes,
                'note' => $note,
                'billable' => true,
                'source' => TimeEntry::SOURCE_MANUAL,
                'item_key' => $item->key,
                'item_title' => $item->title,
            ]);
        }
    }

    private function user(string $name, string $email): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'email_verified_at' => now(),
            'password' => Hash::make((string) env(self::PASSWORD_ENV, 'e2e-password')),
            // no welcome dialog / onboarding checklist in front of the UI under test
            'onboarding_state' => ['welcome_seen' => true, 'checklist_dismissed' => true],
        ]);
    }

    private function issue(Project $project, int $boardId, int $columnId, string $title, User $reporter, ?User $assignee = null): Item
    {
        return $project->items()->create([
            'board_id' => $boardId,
            'column_id' => $columnId,
            'key' => $project->getNextItemKey(),
            'title' => $title,
            'reporter_id' => $reporter->id,
            'created_by' => $reporter->id,
            'assigned_to' => $assignee?->id,
            'position' => 0,
        ]);
    }
}
