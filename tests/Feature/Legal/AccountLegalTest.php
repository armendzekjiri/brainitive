<?php

namespace Tests\Feature\Legal;

use App\Models\Board;
use App\Models\Item;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Legal\LegalInfo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Accounts and the law (BRAI-156): the accepted Terms version is stored at sign-up, and deleting your
 * own account (Art. 17 GDPR) removes your data without taking other people's work with it.
 */
class AccountLegalTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_stores_the_accepted_terms_version_and_time(): void
    {
        $this->freezeSecond();

        $this->post('/register', [
            'name' => 'Test User', 'email' => 'terms@example.com', 'password' => 'password', 'password_confirmation' => 'password',
        ])->assertRedirect();

        $user = User::where('email', 'terms@example.com')->sole();
        $this->assertSame(LegalInfo::termsVersion(), $user->terms_version);
        $this->assertTrue($user->terms_accepted_at->equalTo(now()));
    }

    public function test_sign_up_pages_show_the_terms_notice(): void
    {
        $this->withoutVite();
        $this->get('/register')->assertOk();
        $this->assertStringContainsString('TermsNotice', file_get_contents(resource_path('js/Pages/Auth/Register.tsx')));
        $this->assertStringContainsString('TermsNotice', file_get_contents(resource_path('js/Pages/Auth/Login.tsx')));
    }

    public function test_owner_of_a_workspace_others_use_must_hand_it_over_first(): void
    {
        $owner = User::factory()->create(['name' => 'Owner']);
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id, 'name' => 'Team space']);
        $workspace->addUser($owner, 'admin');
        $workspace->addUser(User::factory()->create(), 'member');

        $this->actingAs($owner)->delete('/profile', ['password' => 'password'])
            ->assertSessionHasErrors('password');

        $this->assertNotNull($owner->fresh());
        $this->assertNotNull($workspace->fresh());
    }

    public function test_deleting_your_account_keeps_your_work_in_other_peoples_workspaces(): void
    {
        $other = User::factory()->create();
        $theirs = Workspace::factory()->create(['owner_id' => $other->id]);
        $theirs->addUser($other, 'admin');

        $leaver = User::factory()->create();
        $theirs->addUser($leaver, 'member');
        $project = Project::factory()->create(['workspace_id' => $theirs->id, 'created_by' => $leaver->id]);
        $board = Board::factory()->create(['project_id' => $project->id, 'created_by' => $leaver->id]);
        $item = Item::factory()->create(['board_id' => $board->id, 'created_by' => $leaver->id]);

        // the leaver's own one-person workspace goes with the account
        $own = Workspace::factory()->create(['owner_id' => $leaver->id]);
        $own->addUser($leaver, 'admin');

        $this->actingAs($leaver)->delete('/profile', ['password' => 'password'])
            ->assertSessionHasNoErrors()->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($leaver->fresh());
        $this->assertNull(Workspace::withTrashed()->find($own->id));
        $this->assertSame($other->id, $project->fresh()->created_by, 'handed over to the workspace owner');
        $this->assertSame($other->id, $item->fresh()->created_by);
    }
}
