<?php

namespace Database\Seeders;

use App\Models\BillingActivity;
use App\Models\Project;
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

        $this->issue($project, $board->id, $columns[0], 'Homepage hero', $owner);
        $this->issue($project, $board->id, $columns[0], 'Pricing page copy', $owner, $member);
        $this->issue($project, $board->id, $columns[1], 'Checkout bug', $owner, $owner);

        // ── v1.38.0 billing fixtures ──────────────────────────────────────────────────────────
        // Two hourly activities, so logging time in e2e-workspace needs one (time-log journeys).
        // Idempotent: the billing tickets of v1.38.0 may seed the same pair.
        foreach ([['Programming', 12000, '#0E83DC', 1], ['Testing', 4500, '#69A748', 2]] as [$name, $rate, $color, $position]) {
            BillingActivity::firstOrCreate(
                ['workspace_id' => $workspace->id, 'name' => $name],
                ['pricing_type' => BillingActivity::HOURLY, 'rate_cents' => $rate, 'billable' => true, 'color' => $color, 'position' => $position],
            );
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

    private function issue(Project $project, int $boardId, int $columnId, string $title, User $reporter, ?User $assignee = null): void
    {
        $project->items()->create([
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
