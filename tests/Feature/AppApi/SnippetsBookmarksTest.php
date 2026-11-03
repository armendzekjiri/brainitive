<?php

namespace Tests\Feature\AppApi;

use App\Jobs\FetchLinkMetadataJob;
use App\Models\Bookmark;
use App\Models\BookmarkItem;
use App\Models\Snippet;
use App\Models\SnippetCollection;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * App API v1 snippets + bookmark groups (BRAI-399): the web's rules (SnippetPolicy, BookmarkPolicy incl.
 * private groups and collaborators), other workspaces → 404, the shared create code (Snippets,
 * Bookmarks), file items, cursor lists and request_id retries.
 */
class SnippetsBookmarksTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use InteractsWithBoards;
    use RefreshDatabase;

    private User $owner;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $this->owner = $this->workspace->owner;
        // workspace editors write snippets and bookmark groups; the viewer stays a plain member
        $this->workspace->users()->updateExistingPivot($this->editor->id, ['role' => 'editor']);
        $this->token = $this->appToken($this->editor);
        Queue::fake([FetchLinkMetadataJob::class]);
    }

    private function headers(?User $user = null): array
    {
        return $this->appHeaders($user ? $this->appToken($user) : $this->token);
    }

    private function collection(array $attributes = []): SnippetCollection
    {
        return SnippetCollection::create($attributes + ['workspace_id' => $this->workspace->id, 'name' => 'Deploy', 'slug' => 'deploy', 'created_by' => $this->owner->id, 'position' => 1]);
    }

    private function group(array $attributes = []): Bookmark
    {
        return Bookmark::create($attributes + ['workspace_id' => $this->workspace->id, 'name' => 'Reading', 'slug' => 'reading', 'visibility' => 'public', 'created_by' => $this->editor->id, 'position' => 1]);
    }

    // ── snippets ────────────────────────────────────────────────────────────────────────────

    public function test_members_list_collections_and_copy_snippets(): void
    {
        $deploy = $this->collection();
        $second = $this->collection(['name' => 'SQL', 'slug' => 'sql', 'position' => 2, 'visibility' => 'public']);
        $b = $deploy->snippets()->create(['title' => 'Restart', 'content' => 'php artisan queue:restart', 'language' => 'bash', 'created_by' => $this->owner->id, 'position' => 2]);
        $a = $deploy->snippets()->create(['title' => 'Migrate', 'content' => 'php artisan migrate', 'language' => 'bash', 'created_by' => $this->owner->id, 'position' => 1]);
        SnippetCollection::create(['workspace_id' => Workspace::factory()->create()->id, 'name' => 'Other', 'slug' => 'other', 'created_by' => $this->owner->id]);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspace->slug}/snippet-collections", $this->headers($this->viewer))->assertOk();
        $this->assertSame([$deploy->id, $second->id], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('data.0.snippets_count', 2)
            ->assertJsonPath('data.0.share_url', null)
            ->assertJsonPath('data.1.share_url', route('snippet-collections.public.show', $second->uuid))
            ->assertJsonPath('data.0.url', route('snippets.show', [$this->workspace->slug, 'deploy']));

        $this->getJson("/api/v1/snippet-collections/{$deploy->id}", $this->headers($this->viewer))->assertOk()
            ->assertJsonPath('data.can', ['update' => false, 'delete' => false, 'manage_snippets' => false]);

        $snippets = $this->getJson("/api/v1/snippet-collections/{$deploy->id}/snippets?limit=1", $this->headers($this->viewer))->assertOk();
        $this->assertSame([$a->id], array_column($snippets->json('data'), 'id'));
        $this->assertSame([
            'id', 'uuid', 'slug', 'collection_id', 'title', 'description', 'language', 'content', 'visibility', 'effective_visibility',
            'share_url', 'position', 'url', 'created_at', 'updated_at',
        ], array_keys($snippets->json('data.0')));
        $snippets->assertJsonPath('data.0.content', 'php artisan migrate')->assertJsonPath('data.0.effective_visibility', 'private');
        $next = $this->getJson("/api/v1/snippet-collections/{$deploy->id}/snippets?limit=1&cursor=".urlencode($snippets->json('next_cursor')), $this->headers($this->viewer));
        $this->assertSame([$b->id], array_column($next->json('data'), 'id'));

        $this->getJson("/api/v1/snippets/{$b->id}", $this->headers($this->viewer))->assertOk()->assertJsonPath('data.title', 'Restart');
    }

    public function test_editors_write_snippets_and_creators_change_collections(): void
    {
        $requestId = '1c2d3e4f-5a6b-4c7d-8e9f-0a1b2c3d4e5f';
        $created = $this->postJson("/api/v1/workspaces/{$this->workspace->slug}/snippet-collections", ['name' => 'Deploy', 'request_id' => $requestId], $this->headers())
            ->assertCreated()->assertJsonPath('data.slug', 'deploy')->assertJsonPath('data.icon', '📋')->assertJsonPath('data.can.update', true);
        $id = $created->json('data.id');
        $this->postJson("/api/v1/workspaces/{$this->workspace->slug}/snippet-collections", ['name' => 'Deploy', 'request_id' => $requestId], $this->headers())
            ->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson("/api/v1/workspaces/{$this->workspace->slug}/snippet-collections", ['name' => 'Deploy'], $this->headers())
            ->assertCreated()->assertJsonPath('data.slug', 'deploy-1');

        $snippet = $this->postJson("/api/v1/snippet-collections/{$id}/snippets", ['title' => 'Up', 'content' => "docker compose up -d\n"], $this->headers())
            ->assertCreated()->assertJsonPath('data.language', 'plaintext')->assertJsonPath('data.visibility', 'inherit')
            ->assertJsonPath('data.content', 'docker compose up -d')->json('data.id'); // trimmed, as every input of the web
        $this->patchJson("/api/v1/snippets/{$snippet}", ['language' => 'bash', 'visibility' => 'public'], $this->headers())
            ->assertOk()->assertJsonPath('data.language', 'bash')->assertJsonPath('data.title', 'Up')
            ->assertJsonPath('data.share_url', route('snippets.public.show', Snippet::findOrFail($snippet)->uuid));
        $this->patchJson("/api/v1/snippet-collections/{$id}", ['name' => 'Deploys', 'visibility' => 'secret'], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('visibility');
        $this->patchJson("/api/v1/snippet-collections/{$id}", ['name' => 'Deploys'], $this->headers())->assertOk()->assertJsonPath('data.name', 'Deploys');

        // a plain member: reads only (the web's SnippetPolicy)
        $this->postJson("/api/v1/workspaces/{$this->workspace->slug}/snippet-collections", ['name' => 'Mine'], $this->headers($this->viewer))->assertForbidden();
        $this->postJson("/api/v1/snippet-collections/{$id}/snippets", ['title' => 'x'], $this->headers($this->viewer))->assertForbidden();
        $this->patchJson("/api/v1/snippets/{$snippet}", ['title' => 'Pwned'], $this->headers($this->viewer))->assertForbidden();
        $this->deleteJson("/api/v1/snippets/{$snippet}", [], $this->headers($this->viewer))->assertForbidden();
        // a collection is changed by its creator or a workspace admin, not every editor
        $others = $this->collection(['name' => 'Owner', 'slug' => 'owner']);
        $this->patchJson("/api/v1/snippet-collections/{$others->id}", ['name' => 'Mine'], $this->headers())->assertForbidden();
        $this->deleteJson("/api/v1/snippet-collections/{$others->id}", [], $this->headers())->assertForbidden();

        $this->deleteJson("/api/v1/snippets/{$snippet}", [], $this->headers())->assertNoContent();
        $this->assertModelMissing(Snippet::make(['id' => $snippet]));
        $this->deleteJson("/api/v1/snippet-collections/{$id}", [], $this->headers())->assertNoContent();
        $this->assertNull(SnippetCollection::find($id));
    }

    public function test_other_workspaces_snippets_are_not_found(): void
    {
        $outside = SnippetCollection::create(['workspace_id' => Workspace::factory()->create()->id, 'name' => 'Secret', 'slug' => 'secret', 'created_by' => $this->owner->id]);
        $snippet = $outside->snippets()->create(['title' => 'Key', 'content' => 'secret', 'language' => 'plaintext', 'created_by' => $this->owner->id, 'position' => 1]);

        $this->getJson("/api/v1/snippet-collections/{$outside->id}", $this->headers())->assertNotFound();
        $this->getJson("/api/v1/snippet-collections/{$outside->id}/snippets", $this->headers())->assertNotFound();
        $this->getJson("/api/v1/snippets/{$snippet->id}", $this->headers())->assertNotFound();
        $this->postJson("/api/v1/snippet-collections/{$outside->id}/snippets", [], $this->headers())->assertNotFound();
        $this->patchJson("/api/v1/snippets/{$snippet->id}", [], $this->headers())->assertNotFound();
        $this->deleteJson("/api/v1/snippet-collections/{$outside->id}", [], $this->headers())->assertNotFound();
        $this->getJson('/api/v1/workspaces/'.$outside->workspace->slug.'/snippet-collections', $this->headers())->assertNotFound();
        $this->postJson('/api/v1/workspaces/'.$outside->workspace->slug.'/snippet-collections', [], $this->headers())->assertNotFound();
        $this->patchJson('/api/v1/snippets/abc', ['title' => 'x'], $this->headers())->assertNotFound();
        $this->assertSame('Key', $snippet->fresh()->title);
    }

    // ── bookmarks ───────────────────────────────────────────────────────────────────────────

    public function test_lists_the_bookmark_groups_the_user_may_see(): void
    {
        $public = $this->group();
        $private = $this->group(['name' => 'Mine', 'slug' => 'mine', 'visibility' => 'private', 'position' => 2]);
        $shared = $this->group(['name' => 'Shared', 'slug' => 'shared', 'visibility' => 'private', 'position' => 3]);
        $shared->collaborators()->attach($this->viewer->id, ['role' => 'viewer']);
        BookmarkItem::create(['bookmark_id' => $public->id, 'type' => 'link', 'url' => 'https://a.example', 'position' => 1, 'created_by' => $this->editor->id]);
        BookmarkItem::create(['bookmark_id' => $public->id, 'type' => 'todo', 'title' => 'Read', 'is_completed' => true, 'position' => 2, 'created_by' => $this->editor->id]);

        $editor = $this->getJson("/api/v1/workspaces/{$this->workspace->slug}/bookmarks", $this->headers())->assertOk();
        $this->assertSame([$public->id, $private->id, $shared->id], array_column($editor->json('data'), 'id'));
        $editor->assertJsonPath('data.0.counts', ['links' => 1, 'todos' => 1, 'todos_completed' => 1, 'notes' => 0, 'files' => 0])
            ->assertJsonPath('data.0.is_owner', true)
            ->assertJsonPath('data.0.share_url', route('bookmarks.public.show', $public->uuid));

        // the viewer: public + shared with them, never someone's private group
        $viewer = $this->getJson("/api/v1/workspaces/{$this->workspace->slug}/bookmarks", $this->headers($this->viewer))->assertOk();
        $this->assertSame([$public->id, $shared->id], array_column($viewer->json('data'), 'id'));
        $this->getJson("/api/v1/bookmarks/{$private->id}", $this->headers($this->viewer))->assertNotFound();
        $this->getJson("/api/v1/bookmarks/{$private->id}/items", $this->headers($this->viewer))->assertNotFound();
        $this->getJson("/api/v1/bookmarks/{$shared->id}", $this->headers($this->viewer))->assertOk()
            ->assertJsonPath('data.can', ['update' => false, 'delete' => false]);

        // a public group of another workspace: only through its share link, not the API
        $foreign = Bookmark::create(['workspace_id' => Workspace::factory()->create()->id, 'name' => 'Out', 'slug' => 'out', 'visibility' => 'public', 'created_by' => $this->owner->id]);
        $this->getJson("/api/v1/bookmarks/{$foreign->id}", $this->headers())->assertNotFound();

        $items = $this->getJson("/api/v1/bookmarks/{$public->id}/items?type=todo", $this->headers($this->viewer))->assertOk();
        $this->assertSame([
            'id', 'group_id', 'type', 'title', 'url', 'content', 'file_url', 'is_completed', 'favicon_url', 'page_description', 'position', 'created_at', 'updated_at',
        ], array_keys($items->json('data.0')));
        $items->assertJsonPath('data.0.title', 'Read')->assertJsonCount(1, 'data');
    }

    public function test_writes_groups_and_items_with_the_web_rules(): void
    {
        $created = $this->postJson("/api/v1/workspaces/{$this->workspace->slug}/bookmarks", ['name' => 'Tools', 'icon' => '🔧', 'visibility' => 'public'], $this->headers())
            ->assertCreated()->assertJsonPath('data.icon', '🔧')->assertJsonPath('data.can', ['update' => true, 'delete' => true]);
        $group = $created->json('data.id');
        $this->postJson("/api/v1/workspaces/{$this->workspace->slug}/bookmarks", ['name' => 'Tools', 'icon' => 'bookmark-icons/1/icon.png'], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('icon');
        $this->patchJson("/api/v1/bookmarks/{$group}", ['name' => 'Dev tools'], $this->headers())->assertOk()->assertJsonPath('data.slug', 'dev-tools');

        $link = $this->postJson("/api/v1/bookmarks/{$group}/items", ['type' => 'link', 'url' => 'https://laravel.com', 'request_id' => '2d3e4f5a-6b7c-4d8e-9f0a-1b2c3d4e5f6a'], $this->headers())
            ->assertCreated()->assertJsonPath('data.type', 'link')->assertJsonPath('data.position', 1);
        Queue::assertPushed(FetchLinkMetadataJob::class);
        $this->postJson("/api/v1/bookmarks/{$group}/items", ['type' => 'link', 'url' => 'https://laravel.com', 'request_id' => '2d3e4f5a-6b7c-4d8e-9f0a-1b2c3d4e5f6a'], $this->headers())
            ->assertOk()->assertJsonPath('data.id', $link->json('data.id'));
        $this->postJson("/api/v1/bookmarks/{$group}/items", ['type' => 'link', 'url' => 'javascript:alert(1)'], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('url');

        $todo = $this->postJson("/api/v1/bookmarks/{$group}/items", ['type' => 'todo', 'title' => 'Read docs'], $this->headers())->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/bookmarks/{$group}/items/{$todo}", ['is_completed' => true], $this->headers())
            ->assertOk()->assertJsonPath('data.is_completed', true)->assertJsonPath('data.title', 'Read docs');

        // files: multipart, the web's checks
        Storage::fake('public');
        $file = $this->post("/api/v1/bookmarks/{$group}/items", ['type' => 'file', 'file' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')], $this->headers())
            ->assertCreated()->assertJsonPath('data.title', 'notes.pdf');
        $path = BookmarkItem::findOrFail($file->json('data.id'))->file_path;
        Storage::disk('public')->assertExists($path);
        $this->assertStringContainsString('/storage/'.$path, $file->json('data.file_url'));
        $this->post("/api/v1/bookmarks/{$group}/items", ['type' => 'file', 'file' => UploadedFile::fake()->create('evil.php', 1, 'application/pdf')], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->postJson("/api/v1/bookmarks/{$group}/items", ['type' => 'file'], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('file');

        // a plain member may not change someone's public group; a group of theirs is fine
        $this->postJson("/api/v1/bookmarks/{$group}/items", ['type' => 'note', 'content' => 'x'], $this->headers($this->viewer))->assertForbidden();
        $this->deleteJson("/api/v1/bookmarks/{$group}", [], $this->headers($this->viewer))->assertForbidden();
        $this->postJson("/api/v1/workspaces/{$this->workspace->slug}/bookmarks", ['name' => 'Mine'], $this->headers($this->viewer))->assertForbidden();

        $this->deleteJson("/api/v1/bookmarks/{$group}/items/{$file->json('data.id')}", [], $this->headers())->assertNoContent();
        Storage::disk('public')->assertMissing($path);
        $this->deleteJson("/api/v1/bookmarks/{$group}/items/abc", [], $this->headers())->assertNotFound();
        $this->deleteJson("/api/v1/bookmarks/{$group}", [], $this->headers())->assertNoContent();
        $this->assertSoftDeleted('bookmarks', ['id' => $group]);
    }

    public function test_an_item_of_another_group_is_not_found_through_this_one(): void
    {
        $mine = $this->group();
        $theirs = $this->group(['name' => 'Private', 'slug' => 'private', 'visibility' => 'private', 'created_by' => $this->owner->id]);
        $item = BookmarkItem::create(['bookmark_id' => $theirs->id, 'type' => 'note', 'content' => 'secret', 'position' => 1, 'created_by' => $this->owner->id]);

        $this->patchJson("/api/v1/bookmarks/{$mine->id}/items/{$item->id}", ['content' => 'pwned'], $this->headers())->assertNotFound();
        $this->deleteJson("/api/v1/bookmarks/{$mine->id}/items/{$item->id}", [], $this->headers())->assertNotFound();
        $this->patchJson("/api/v1/bookmarks/{$theirs->id}/items/{$item->id}", ['content' => 'pwned'], $this->headers())->assertNotFound();
        $this->assertSame('secret', $item->fresh()->content);
    }

    public function test_tokens_of_other_apis_and_unverified_users_are_refused(): void
    {
        $this->getJson("/api/v1/workspaces/{$this->workspace->slug}/snippet-collections")->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->getJson("/api/v1/workspaces/{$this->workspace->slug}/snippet-collections", $this->appHeaders($this->passportToken($this->editor)))->assertUnauthorized();
        $this->getJson("/api/v1/workspaces/{$this->workspace->slug}/bookmarks", $this->appHeaders($this->passportToken($this->editor)))->assertUnauthorized();

        $unverified = User::factory()->unverified()->create();
        $this->workspace->addUser($unverified, 'editor');
        $this->getJson("/api/v1/workspaces/{$this->workspace->slug}/bookmarks", $this->appHeaders($this->appToken($unverified)))->assertForbidden();
    }
}
