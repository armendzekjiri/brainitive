<?php

namespace Tests\Feature\Billing;

use App\Models\BillingActivity;
use App\Models\BillingReport;
use App\Models\BillingSetting;
use App\Models\Board;
use App\Models\Client;
use App\Models\Item;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;

/**
 * Billing report fixture (BRAI-101): one client (Muster GmbH) with two projects (Website WEB,
 * Shop SHOP), two developers, activities Programming 120 €/h, Testing 45 €/h and SSM setup 450 €
 * fixed. October 2026 entries (rounding 0):
 *
 *   Shop     SHOP-1 Programming   Ada  30 min           → 60.00
 *            SHOP-1 SSM setup     Bob 125 min (fixed)   → 450.00
 *            SHOP-1 Testing       Bob  20 min, not billable
 *   Website  WEB-1  Programming   Ada 100 + Bob 50 min  → 300.00
 *            WEB-2  Testing       Ada  70 min           → 52.50
 *   Σ 862.50
 *
 * Plus entries outside the scope (September, another client's project) that must never show up.
 */
trait InteractsWithBilling
{
    protected Workspace $workspace;

    protected User $admin;

    protected User $ada;

    protected User $bob;

    protected Client $client;

    protected Project $website;

    protected Project $shop;

    protected Project $otherProject;

    protected BillingActivity $programming;

    protected BillingActivity $testing;

    protected BillingActivity $ssm;

    /** @var array<string, Item> */
    protected array $items = [];

    /** @var array<string, TimeEntry> */
    protected array $entries = [];

    protected function setUpBilling(): void
    {
        $this->withoutVite();

        $this->admin = User::factory()->create(['name' => 'Erin Admin']);
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->admin->id]);
        $this->workspace->addUser($this->admin, 'admin');
        $this->ada = User::factory()->create(['name' => 'Ada Developer']);
        $this->bob = User::factory()->create(['name' => 'Bob Developer']);
        $this->workspace->addUser($this->ada, 'member');
        $this->workspace->addUser($this->bob, 'member');

        $this->client = Client::create(['workspace_id' => $this->workspace->id, 'name' => 'Muster GmbH', 'email' => 'billing@muster.test', 'address' => "Hauptstr. 1\nBerlin", 'vat_id' => 'DE123']);
        $other = Client::create(['workspace_id' => $this->workspace->id, 'name' => 'Other AG']);

        $this->website = $this->project('Website', 'WEB', $this->client);
        $this->shop = $this->project('Shop', 'SHOP', $this->client);
        $this->otherProject = $this->project('Other', 'OTH', $other);

        $this->programming = $this->activity('Programming', BillingActivity::HOURLY, 12000);
        $this->testing = $this->activity('Testing', BillingActivity::HOURLY, 4500);
        $this->ssm = $this->activity('SSM setup', BillingActivity::FIXED, 45000);

        $this->items['WEB-1'] = $this->issue($this->website, 'Checkout bug');
        $this->items['WEB-2'] = $this->issue($this->website, 'Pricing page');
        $this->items['SHOP-1'] = $this->issue($this->shop, 'Payment provider');
        $this->items['OTH-1'] = $this->issue($this->otherProject, 'Other work');

        $this->entries['web1_ada'] = $this->log($this->ada, 'WEB-1', $this->programming, 100, '2026-10-02', 'Fixed rounding in cart');
        $this->entries['web1_bob'] = $this->log($this->bob, 'WEB-1', $this->programming, 50, '2026-10-03', 'Review');
        $this->entries['web2_ada'] = $this->log($this->ada, 'WEB-2', $this->testing, 70, '2026-10-05');
        $this->entries['shop1_ssm'] = $this->log($this->bob, 'SHOP-1', $this->ssm, 125, '2026-10-06');
        $this->entries['shop1_prog'] = $this->log($this->ada, 'SHOP-1', $this->programming, 30, '2026-10-07');
        $this->entries['shop1_test'] = $this->log($this->bob, 'SHOP-1', $this->testing, 20, '2026-10-08', null, false);
        // out of scope
        $this->entries['september'] = $this->log($this->ada, 'WEB-1', $this->programming, 60, '2026-09-30');
        $this->entries['other_client'] = $this->log($this->ada, 'OTH-1', $this->programming, 60, '2026-10-10');
    }

    protected function project(string $name, string $key, Client $client): Project
    {
        $project = Project::factory()->create([
            'workspace_id' => $this->workspace->id,
            'client_id' => $client->id,
            'name' => $name,
            'key' => $key,
            'created_by' => $this->admin->id,
        ]);
        Board::factory()->create(['project_id' => $project->id, 'created_by' => $this->admin->id]);

        return $project;
    }

    protected function activity(string $name, string $type, int $rate): BillingActivity
    {
        return BillingActivity::create(['workspace_id' => $this->workspace->id, 'name' => $name, 'pricing_type' => $type, 'rate_cents' => $rate]);
    }

    protected function issue(Project $project, string $title): Item
    {
        return Item::factory()->create([
            'board_id' => $project->boards()->value('id'),
            'title' => $title,
            'created_by' => $this->admin->id,
        ]);
    }

    protected function log(?User $user, ?string $item, ?BillingActivity $activity, int $minutes, string $date, ?string $note = null, bool $billable = true, ?Project $project = null): TimeEntry
    {
        $issue = $item ? $this->items[$item] : null;

        return TimeEntry::create([
            'workspace_id' => $this->workspace->id,
            'project_id' => $project?->id ?? $issue->project_id,
            'item_id' => $issue?->id,
            'user_id' => $user?->id,
            'billing_activity_id' => $activity?->id,
            'spent_on' => $date,
            'minutes' => $minutes,
            'note' => $note,
            'billable' => $billable,
            'item_key' => $issue?->key,
            'item_title' => $issue?->title,
        ]);
    }

    protected function draft(array $attributes = []): BillingReport
    {
        $options = ($attributes['options'] ?? []) + ['project_ids' => null, 'group_by' => 'ticket', 'show_people' => true, 'show_notes' => false, 'excluded_entry_ids' => []];
        unset($attributes['options']);

        return BillingReport::create($attributes + [
            'workspace_id' => $this->workspace->id,
            'client_id' => $this->client->id,
            'title' => 'Time report October 2026',
            'period_from' => '2026-10-01',
            'period_to' => '2026-10-31',
            'status' => 'draft',
            'options' => $options,
            'created_by' => $this->admin->id,
        ])->fresh();
    }

    protected function settings(array $attributes): BillingSetting
    {
        $setting = BillingSetting::forWorkspace($this->workspace);
        $setting->fill($attributes)->save();

        return $setting;
    }
}
