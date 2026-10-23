<?php

namespace Tests\Feature\Security;

use App\Jobs\FetchLinkMetadataJob;
use App\Mcp\Servers\BrainitiveServer;
use App\Mcp\Tools\FetchTool;
use App\Models\Board;
use App\Models\Bookmark;
use App\Models\BookmarkItem;
use App\Models\Helpdesk;
use App\Models\Item;
use App\Models\ItemType;
use App\Models\Project;
use App\Models\SnippetCollection;
use App\Models\TaskList;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\TicketCreatedNotification;
use App\Support\Mcp\PageContentConverter;
use App\Support\OutboundUrl;
use App\Support\Uploads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\Feature\Concerns\UsesPassportKeys;
use Tests\TestCase;

/**
 * Regression tests for the October 2026 security review: each test asserts the attack is
 * now rejected. See docs/ai/tasks/fix-security-review.md.
 */
class SecurityRegressionTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;
    use UsesPassportKeys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $this->usePassportKeys();
        Notification::fake();
    }

    // --- CLI API ------------------------------------------------------------------------

    public function test_cli_cannot_touch_an_item_of_another_list_through_its_own_list(): void
    {
        $attackerList = TaskList::create(['project_id' => $this->project->id, 'name' => 'Mine', 'slug' => 'mine', 'created_by' => $this->editor->id]);

        $otherWorkspace = Workspace::factory()->create(['owner_id' => $this->outsider->id]);
        $otherWorkspace->addUser($this->outsider, 'admin');
        $otherProject = Project::factory()->create(['workspace_id' => $otherWorkspace->id, 'created_by' => $this->outsider->id]);
        $victimList = TaskList::create(['project_id' => $otherProject->id, 'name' => 'Victim', 'slug' => 'victim', 'created_by' => $this->outsider->id]);
        $victimItem = $victimList->items()->create(['title' => 'Secret plan', 'status' => 'todo', 'position' => 1, 'created_by' => $this->outsider->id]);

        Passport::actingAs($this->editor);

        $this->patchJson("/api/cli/lists/{$attackerList->slug}/items/{$victimItem->id}", ['title' => 'pwned'])->assertNotFound();
        $this->deleteJson("/api/cli/lists/{$attackerList->slug}/items/{$victimItem->id}")->assertNotFound();

        $this->assertSame('Secret plan', $victimItem->fresh()->title);
        $this->assertNotNull($victimItem->fresh());
    }

    public function test_suspended_users_cannot_get_or_use_api_tokens(): void
    {
        $this->editor->forceFill(['suspended_at' => now()])->save();

        $this->postJson('/api/cli/login', ['email' => $this->editor->email, 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'This account is suspended.');

        Passport::actingAs($this->editor, ['capture']);
        $this->getJson(route('ext.me'))->assertForbidden();
    }

    // --- Public helpdesk ----------------------------------------------------------------

    private function helpdesk(): Helpdesk
    {
        return Helpdesk::create(['project_id' => $this->project->id, 'name' => 'Support', 'slug' => 'support', 'created_by' => $this->editor->id]);
    }

    private function submitTicket(Helpdesk $helpdesk, string $email, string $description = 'Help'): array
    {
        return $this->postJson(route('helpdesks.tickets.store-public', $helpdesk->uuid), [
            'subject' => 'Question',
            'description' => $description,
            'contact_email' => $email,
        ])->assertOk()->json();
    }

    public function test_a_public_submission_token_only_opens_its_own_ticket(): void
    {
        $helpdesk = $this->helpdesk();
        $victim = $this->submitTicket($helpdesk, 'customer@example.com', 'My invoice number is 4711');

        // Attacker submits with the victim's email and gets a token back.
        $attacker = $this->submitTicket($helpdesk, 'customer@example.com');

        $this->getJson(route('helpdesks.tickets.get-public', $victim['ticket_uuid']).'?token='.$attacker['access_token'])->assertForbidden();
        $this->get(route('helpdesks.tickets.show-public', $victim['ticket_uuid']).'?token='.$attacker['access_token'])->assertForbidden();
        $this->postJson(route('helpdesks.tickets.replies.store-public', [$helpdesk->uuid, $victim['ticket_uuid']]), [
            'body' => 'hi', 'access_token' => $attacker['access_token'],
        ])->assertForbidden();

        // The victim's own link keeps working (tokens are no longer rotated).
        $this->getJson(route('helpdesks.tickets.get-public', $victim['ticket_uuid']).'?token='.$victim['access_token'])
            ->assertOk()
            ->assertJsonPath('ticket.description', 'My invoice number is 4711');
    }

    public function test_ticket_html_is_sanitized_on_write_and_on_read(): void
    {
        $helpdesk = $this->helpdesk();
        $ticket = $this->submitTicket($helpdesk, 'x@example.com', '<p>Hi</p><img src=x onerror="alert(document.cookie)"><script>alert(1)</script><a href="javascript:alert(1)">x</a>');

        $stored = DB::table('tickets')->where('uuid', $ticket['ticket_uuid'])->value('description');
        $this->assertStringNotContainsString('onerror', $stored);
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('javascript:', $stored);
        $this->assertStringContainsString('<p>Hi</p>', $stored);

        // A row saved before sanitizing existed is cleaned when read.
        DB::table('tickets')->where('uuid', $ticket['ticket_uuid'])->update(['description' => '<img src=x onerror=alert(1)>']);
        $json = $this->getJson(route('helpdesks.tickets.get-public', $ticket['ticket_uuid']).'?token='.$ticket['access_token'])->json('ticket.description');
        $this->assertStringNotContainsString('onerror', $json);
    }

    // --- Uploads -----------------------------------------------------------------------

    public function test_public_ticket_attachments_never_keep_an_executable_extension(): void
    {
        Storage::fake('public');
        $helpdesk = $this->helpdesk();
        $ticket = $this->submitTicket($helpdesk, 'x@example.com');

        $this->post(route('helpdesks.tickets.attachments.store-public', [$helpdesk->uuid, $ticket['ticket_uuid']]), [
            'file' => UploadedFile::fake()->createWithContent('shell.php', '<?php system($_GET["c"]);'),
            'access_token' => $ticket['access_token'],
        ])->assertOk();

        $attachment = Ticket::where('uuid', $ticket['ticket_uuid'])->firstOrFail()->attachments()->firstOrFail();
        $this->assertStringEndsWith('.bin', $attachment->path);
        $this->assertSame('shell.php', $attachment->original_filename);
        $this->assertSame([], array_filter(Storage::disk('public')->allFiles(), fn ($f) => str_contains($f, '.php')));
    }

    public function test_board_attachments_and_image_uploads_use_server_side_extensions(): void
    {
        Storage::fake('public');
        $item = $this->item();

        $this->actingAs($this->editor)->post(route('boards.items.attachments.store', [$this->workspace->slug, $this->project->slug, $this->board->slug, $item->id]), [
            'file' => UploadedFile::fake()->createWithContent('page.html', '<script>alert(1)</script>'),
        ]);
        $this->assertStringEndsWith('.bin', $item->attachments()->firstOrFail()->path);

        // A real PNG named .svg passes the image check; it used to be stored as avatar.svg.
        $this->actingAs($this->editor)->patch(route('profile.update'), [
            'name' => $this->editor->name,
            'email' => $this->editor->email,
            'avatar' => $this->realUpload('avatar.svg', UploadedFile::fake()->image('a.png', 20, 20)->getContent()),
        ])->assertSessionHasNoErrors();
        $files = Storage::disk('public')->allFiles('profile-photos/'.$this->editor->id);
        $this->assertSame(['profile-photos/'.$this->editor->id.'/avatar.png'], $files);
    }

    /** A real upload (MIME read from the content, unlike fakes that trust the name). */
    private function realUpload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    public function test_bookmark_temp_icon_path_cannot_walk_out_of_the_temp_folder(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('attachments/1/secret.png', 'x');
        $this->workspace->users()->updateExistingPivot($this->editor->id, ['role' => 'editor']);

        $this->actingAs($this->editor)->post(route('bookmarks.store', $this->workspace->slug), [
            'name' => 'Links',
            'icon' => 'storage/bookmark-icons/temp/../../attachments/1/secret.png',
        ]);

        Storage::disk('public')->assertExists('attachments/1/secret.png');
    }

    // --- Outbound requests (SSRF) -------------------------------------------------------

    public function test_outbound_urls_must_be_public(): void
    {
        foreach (['http://127.0.0.1/', 'http://localhost:8080/', 'http://169.254.169.254/latest/meta-data/', 'http://10.0.0.5/', 'http://[::1]/', 'file:///etc/passwd', 'gopher://example.com', 'http://user:pw@93.184.216.34/'] as $url) {
            $this->assertNull(OutboundUrl::options($url), $url);
        }

        $options = OutboundUrl::options('https://93.184.216.34/page');
        $this->assertFalse($options['allow_redirects']);
        $this->assertSame(['93.184.216.34:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);

        $this->assertNotNull(OutboundUrl::options('http://127.0.0.1:11434/', allowPrivate: true));
    }

    public function test_bookmark_previews_do_not_fetch_internal_addresses(): void
    {
        Http::fake();
        $bookmark = Bookmark::create(['workspace_id' => $this->workspace->id, 'name' => 'Links', 'created_by' => $this->editor->id]);
        $item = BookmarkItem::create(['bookmark_id' => $bookmark->id, 'type' => 'link', 'url' => 'http://169.254.169.254/latest/meta-data/', 'position' => 1, 'created_by' => $this->editor->id]);

        (new FetchLinkMetadataJob($item->id))->handle();

        Http::assertNothingSent();
    }

    public function test_ai_endpoint_cannot_point_at_internal_services(): void
    {
        Http::fake();
        $this->workspace->users()->updateExistingPivot($this->workspace->owner_id, ['role' => 'admin']);
        $owner = User::find($this->workspace->owner_id);

        $this->actingAs($owner)->postJson(route('ai.models', $this->workspace->slug), [
            'ai_endpoint' => 'http://169.254.169.254',
            'ai_api_key' => 'x',
        ])->assertUnprocessable();

        Http::assertNothingSent();
    }

    // --- Cross-tenant item types --------------------------------------------------------

    public function test_a_project_cannot_change_default_item_types_for_everyone(): void
    {
        $bug = ItemType::create(['name' => 'Bug', 'slug' => 'bug-test', 'is_active' => true]);
        $owner = User::find($this->workspace->owner_id);
        $route = route('projects.item-types.update', [$this->workspace->slug, $this->project->slug, $bug->id]);

        $this->actingAs($owner)->put($route, ['name' => 'Pwned', 'is_active' => true]);
        $this->assertSame('Bug', $bug->fresh()->name);

        $this->actingAs($owner)->put($route, ['is_active' => false]);
        $this->assertTrue($bug->fresh()->is_active, 'the shared type stays active');
        $this->assertFalse(ItemType::forProject($this->project->fresh())->whereKey($bug->id)->exists());

        $otherProject = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $owner->id]);
        $this->assertTrue(ItemType::forProject($otherProject)->whereKey($bug->id)->exists());
    }

    // --- Read scoping -------------------------------------------------------------------

    public function test_search_and_mcp_fetch_respect_project_membership_and_private_bookmarks(): void
    {
        $hiddenProject = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->workspace->owner_id]);
        $hiddenBoard = Board::factory()->create(['project_id' => $hiddenProject->id, 'created_by' => $this->workspace->owner_id]);
        $hidden = Item::factory()->create(['board_id' => $hiddenBoard->id, 'title' => 'Layoff plan', 'created_by' => $this->workspace->owner_id]);
        Bookmark::create(['workspace_id' => $this->workspace->id, 'name' => 'Layoff links', 'visibility' => 'private', 'created_by' => $this->workspace->owner_id]);

        // The editor is a workspace member but not in $hiddenProject.
        $results = $this->actingAs($this->editor)->getJson(route('search', ['q' => 'Layoff']))->assertOk()->json();
        $this->assertSame([], $results['items']);
        $this->assertSame([], $results['bookmarks']);

        BrainitiveServer::actingAs($this->editor)
            ->tool(FetchTool::class, ['id' => 'item:'.$hidden->id])
            ->assertHasErrors();
    }

    // --- Foreign ids from other workspaces ----------------------------------------------

    public function test_issue_links_and_column_moves_cannot_reach_other_workspaces(): void
    {
        $otherWorkspace = Workspace::factory()->create(['owner_id' => $this->outsider->id]);
        $otherWorkspace->addUser($this->outsider, 'admin');
        $otherProject = Project::factory()->create(['workspace_id' => $otherWorkspace->id, 'created_by' => $this->outsider->id]);
        $otherBoard = Board::factory()->create(['project_id' => $otherProject->id, 'created_by' => $this->outsider->id]);
        $secret = Item::factory()->create(['board_id' => $otherBoard->id, 'title' => 'Acquisition target', 'created_by' => $this->outsider->id]);
        $item = $this->item();

        $this->actingAs($this->editor)
            ->post($this->itemRoute('boards.items.links.store', $item), ['target_item_id' => $secret->id, 'link_type' => 'relates_to'])
            ->assertSessionHasErrors('target_item_id');
        $this->assertSame(0, $item->outgoingLinks()->count());

        $this->actingAs($this->editor)
            ->patch($this->itemRoute('boards.items.move', $item), ['column_id' => $otherBoard->columns()->first()->id, 'position' => 0])
            ->assertSessionHasErrors('column_id');
        $this->assertSame($this->board->id, $item->fresh()->column->board_id);
    }

    public function test_unlinking_issues_still_works_with_scoped_bindings(): void
    {
        $item = $this->item();
        $other = $this->item();
        $link = $item->outgoingLinks()->create(['target_item_id' => $other->id, 'link_type' => 'relates_to', 'created_by' => $this->editor->id]);

        $this->actingAs($this->editor)
            ->delete($this->itemRoute('boards.items.links.destroy', $item, [$link->id]))
            ->assertRedirect();

        $this->assertModelMissing($link);
    }

    public function test_snippets_can_only_be_changed_by_workspace_editors(): void
    {
        $collection = SnippetCollection::create(['workspace_id' => $this->workspace->id, 'name' => 'Deploy', 'slug' => 'deploy', 'created_by' => $this->workspace->owner_id]);
        $snippet = $collection->snippets()->create(['title' => 'Key', 'content' => 'secret', 'language' => 'plaintext', 'created_by' => $this->workspace->owner_id, 'position' => 1]);
        $ws = $this->workspace->slug;

        foreach ([$this->outsider, $this->editor /* workspace role "member" */] as $user) {
            $this->actingAs($user)->post(route('snippets.store', $ws), ['name' => 'Mine'])->assertForbidden();
            $this->actingAs($user)->put(route('snippets.update', [$ws, $collection->slug]), ['name' => 'Pwned'])->assertForbidden();
            $this->actingAs($user)->post(route('snippets.snippets.store', [$ws, $collection->slug]), ['title' => 'x'])->assertForbidden();
            $this->actingAs($user)->put(route('snippets.snippets.update', [$ws, $collection->slug, $snippet->slug]), ['title' => 'Pwned'])->assertForbidden();
            $this->actingAs($user)->delete(route('snippets.snippets.destroy', [$ws, $collection->slug, $snippet->slug]))->assertForbidden();
            $this->actingAs($user)->delete(route('snippets.destroy', [$ws, $collection->slug]))->assertForbidden();
        }

        $this->assertSame('Deploy', $collection->fresh()->name);
        $this->assertSame('Key', $snippet->fresh()->title);

        $this->workspace->users()->updateExistingPivot($this->editor->id, ['role' => 'editor']);
        $this->actingAs($this->editor)->post(route('snippets.snippets.store', [$ws, $collection->slug]), ['title' => 'Allowed'])->assertRedirect();
    }

    // --- Audit 2026-10-23, batch 3: XSS & uploads -------------------------------------

    public function test_uploads_only_keep_allowlisted_extensions(): void
    {
        Storage::fake('public');
        $helpdesk = $this->helpdesk();
        $ticket = $this->submitTicket($helpdesk, 'x@example.com');

        foreach (['invoice.xhtm' => '.bin', 'payload.rdf' => '.bin', 'notes.strange' => '.bin', 'contract.pdf' => '.pdf', 'sheet.xlsx' => '.xlsx'] as $name => $ext) {
            $this->post(route('helpdesks.tickets.attachments.store-public', [$helpdesk->uuid, $ticket['ticket_uuid']]), [
                'file' => UploadedFile::fake()->createWithContent($name, '<?xml version="1.0"?><html xmlns="http://www.w3.org/1999/xhtml"><script>alert(1)</script></html>'),
                'access_token' => $ticket['access_token'],
            ])->assertOk();
            $this->assertStringEndsWith($ext, Ticket::where('uuid', $ticket['ticket_uuid'])->firstOrFail()->attachments()->latest('id')->value('path'), $name);
        }
    }

    public function test_public_ticket_uploads_are_throttled_limited_and_respect_attachments_off(): void
    {
        Storage::fake('public');
        foreach (['helpdesks.tickets.attachments.store-public', 'helpdesks.tickets.replies.store-public'] as $name) {
            $this->assertContains('throttle:20,1', Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }

        $helpdesk = $this->helpdesk();
        $helpdesk->update(['settings' => ['default_field_attachments' => ['hidden' => true]]]);
        $ticket = $this->submitTicket($helpdesk, 'x@example.com');

        $this->post(route('helpdesks.tickets.attachments.store-public', [$helpdesk->uuid, $ticket['ticket_uuid']]), [
            'file' => UploadedFile::fake()->create('a.pdf', 1), 'access_token' => $ticket['access_token'],
        ])->assertForbidden();
        $this->post(route('helpdesks.tickets.replies.store-public', [$helpdesk->uuid, $ticket['ticket_uuid']]), [
            'body' => 'hi', 'file' => [UploadedFile::fake()->create('a.pdf', 1)], 'access_token' => $ticket['access_token'],
        ])->assertForbidden();
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_ticket_emails_never_render_visitor_markdown_links(): void
    {
        $helpdesk = $this->helpdesk();
        $this->postJson(route('helpdesks.tickets.store-public', $helpdesk->uuid), [
            'subject' => '[Your account is suspended – verify now](https://evil.example/login)',
            'description' => 'x',
            'contact_email' => 'victim@example.com',
        ])->assertOk();

        $ticket = Ticket::latest('id')->firstOrFail();
        $html = (string) (new TicketCreatedNotification($ticket))->toMail($ticket->contact)->render();
        $this->assertStringNotContainsString('href="https://evil.example', $html);
    }

    public function test_mcp_markdown_links_cannot_carry_script_urls(): void
    {
        $html = PageContentConverter::markdownToHtml('[click](javascript:alert(1)) and [ok](https://example.com) and [rel](/workspaces)');

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('href="https://example.com"', $html);
        $this->assertStringContainsString('href="/workspaces"', $html);
    }

    public function test_a_project_icon_cannot_point_at_someone_elses_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('profile-photos/'.$this->viewer->id.'/avatar.png', 'victim');
        $admin = User::factory()->create();
        $this->workspace->addUser($admin, 'admin');
        $route = route('projects.update', [$this->workspace->slug, $this->project->slug]);

        $this->actingAs($admin)->put($route, ['name' => $this->project->name, 'icon' => 'profile-photos/'.$this->viewer->id.'/avatar.png'])->assertSessionHasErrors('icon');
        $this->actingAs($admin)->put($route, ['name' => $this->project->name, 'icon' => '../profile-photos/x.png'])->assertSessionHasErrors('icon');
        $this->actingAs($admin)->put($route, ['name' => $this->project->name, 'icon_image' => UploadedFile::fake()->image('i.png')])->assertSessionHasNoErrors();

        Storage::disk('public')->assertExists('profile-photos/'.$this->viewer->id.'/avatar.png');
        $this->actingAs($admin)->put($route, ['name' => $this->project->name, 'icon' => '🚀'])->assertSessionHasNoErrors();
    }

    public function test_bookmark_links_cannot_use_script_urls(): void
    {
        $this->workspace->users()->updateExistingPivot($this->editor->id, ['role' => 'editor']);
        $bookmark = Bookmark::create(['workspace_id' => $this->workspace->id, 'name' => 'Links', 'slug' => 'links', 'visibility' => 'public', 'created_by' => $this->editor->id]);
        $route = route('bookmarks.items.store', [$this->workspace->slug, $bookmark->slug]);

        $this->actingAs($this->editor)->post($route, ['type' => 'link', 'title' => 'x', 'url' => 'javascript:alert(document.cookie)'])->assertSessionHasErrors('url');
        $this->actingAs($this->editor)->post($route, ['type' => 'link', 'title' => 'x', 'url' => ' DATA:text/html,<script>alert(1)</script>'])->assertSessionHasErrors('url');
        $this->actingAs($this->editor)->post($route, ['type' => 'link', 'title' => 'ok', 'url' => 'https://example.com'])->assertSessionHasNoErrors();
    }

    public function test_batch_3_review_bypasses_stay_closed(): void
    {
        // bookmark links: an allowlist, so browser-stripped characters can't smuggle a scheme in
        $this->workspace->users()->updateExistingPivot($this->editor->id, ['role' => 'editor']);
        $bookmark = Bookmark::create(['workspace_id' => $this->workspace->id, 'name' => 'L', 'slug' => 'l', 'visibility' => 'public', 'created_by' => $this->editor->id]);
        foreach (["java\tscript:alert(1)", "jav\nascript:alert(1)", "\x01javascript:alert(1)", 'ftp://example.com'] as $url) {
            $this->actingAs($this->editor)->post(route('bookmarks.items.store', [$this->workspace->slug, $bookmark->slug]), ['type' => 'link', 'title' => 'x', 'url' => $url])->assertSessionHasErrors('url');
        }

        // MCP markdown: control characters and backslashes never make a link
        foreach (["[x](\x01javascript:alert(1))", '[x](\\\\evil.com)', '[x](/\\evil.com)'] as $md) {
            $this->assertStringNotContainsString('href=', PageContentConverter::markdownToHtml($md), $md);
        }

        // legacy icon file names may be sent back unchanged
        Storage::fake('public');
        $admin = User::factory()->create();
        $this->workspace->addUser($admin, 'admin');
        $this->project->forceFill(['icon' => 'project-icons/'.$this->project->id.'/icon.PNG'])->save();
        $this->actingAs($admin)->put(route('projects.update', [$this->workspace->slug, $this->project->slug]), [
            'name' => 'Renamed', 'icon' => 'project-icons/'.$this->project->id.'/icon.PNG',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Renamed', $this->project->fresh()->name);

        // the attachment audit flags any non-allowlisted extension (files stored before the allowlist)
        $this->assertTrue(Uploads::isActive('ticket-attachments/1/abc.rss'));
        $this->assertFalse(Uploads::isActive('ticket-attachments/1/abc.pdf'));
    }
}
