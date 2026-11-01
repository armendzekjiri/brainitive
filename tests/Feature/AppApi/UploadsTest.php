<?php

namespace Tests\Feature\AppApi;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUsage;
use App\Support\Entitlements\ConfigEntitlements;
use App\Support\Entitlements\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * App API v1 files (BRAI-416): `POST /uploads` with the web editor's rules (EditorUploads) and the plan's file
 * storage (FileRoom → 402) — which issue attachments and bookmark files now check too.
 */
class UploadsTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use InteractsWithBoards;
    use RefreshDatabase;

    private function headers(?User $user = null): array
    {
        return $this->appHeaders($this->appToken($user ?? $this->editor)) + ['Accept' => 'application/json'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Storage::fake('public');
    }

    private function upload(UploadedFile $file, ?User $user = null, ?string $workspace = null)
    {
        return $this->post('/api/v1/uploads', ['workspace' => $workspace ?? $this->workspace->slug, 'file' => $file], $this->headers($user));
    }

    private function fileLimit(int $limit, int $used): void
    {
        $this->app->instance(Entitlements::class, new ConfigEntitlements(['enforced' => true, 'file_bytes' => $limit]));
        WorkspaceUsage::create(['workspace_id' => $this->workspace->id, 'file_bytes' => $used]);
    }

    public function test_images_and_documents_are_stored_under_safe_names_with_a_markdown_snippet(): void
    {
        $image = $this->upload(UploadedFile::fake()->image('photo [1].png', 40, 40), $this->viewer)->assertCreated()
            ->assertJsonPath('data.kind', 'image')
            ->assertJsonPath('data.name', 'photo [1].png')
            ->assertJsonPath('data.mime_type', 'image/png');
        $url = $image->json('data.url');
        $this->assertMatchesRegularExpression('#/storage/uploads/images/[0-9a-f-]{36}\.png$#', $url);
        $this->assertSame("![photo (1).png]({$url})", $image->json('data.markdown'));
        Storage::disk('public')->assertExists(substr(parse_url($url, PHP_URL_PATH), strlen('/storage/')));

        $pdf = $this->upload(UploadedFile::fake()->create('Spec.pdf', 30, 'application/pdf'))->assertCreated()
            ->assertJsonPath('data.kind', 'file')->assertJsonPath('data.size', 30 * 1024);
        $this->assertMatchesRegularExpression('#/storage/uploads/documents/[0-9a-f-]{36}\.pdf$#', $pdf->json('data.url'));
        $this->assertStringStartsWith('[Spec.pdf](', $pdf->json('data.markdown'));
    }

    public function test_the_web_editors_rules(): void
    {
        $this->upload(UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'))
            ->assertUnprocessable()->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('file');
        $this->upload(UploadedFile::fake()->create('run.php', 1, 'text/x-php'))->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->upload(UploadedFile::fake()->create('setup.exe', 1, 'application/x-msdownload'))->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->upload(UploadedFile::fake()->image('huge.png')->size(12 * 1024))->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->upload(UploadedFile::fake()->create('big.pdf', 21 * 1024, 'application/pdf'))->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->post('/api/v1/uploads', ['workspace' => $this->workspace->slug], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_other_workspaces_tokens_and_the_storage_limit(): void
    {
        $other = Workspace::factory()->create();
        $this->upload(UploadedFile::fake()->image('a.png'), null, $other->slug)->assertNotFound();
        $this->upload(UploadedFile::fake()->image('a.png'), null, 'nope')->assertNotFound();
        $this->post('/api/v1/uploads', ['workspace' => $this->workspace->slug, 'file' => UploadedFile::fake()->image('a.png')], ['Accept' => 'application/json'])->assertUnauthorized();
        $this->post('/api/v1/uploads', ['workspace' => $this->workspace->slug, 'file' => UploadedFile::fake()->image('a.png')], $this->appHeaders($this->passportToken($this->editor)) + ['Accept' => 'application/json'])->assertUnauthorized();

        $this->fileLimit(100 * 1024, 90 * 1024);
        $this->upload(UploadedFile::fake()->create('Spec.pdf', 30, 'application/pdf'))
            ->assertStatus(402)->assertJsonPath('code', 'limit_reached')->assertJsonPath('limit.kind', 'files');
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->upload(UploadedFile::fake()->create('Small.pdf', 5, 'application/pdf'))->assertCreated();
    }

    public function test_issue_attachments_and_bookmark_files_count_against_the_storage_too(): void
    {
        $item = $this->item();
        $admin = User::factory()->create();
        $this->workspace->addUser($admin, 'admin');
        $group = $this->postJson("/api/v1/workspaces/{$this->workspace->slug}/bookmarks", ['name' => 'Files', 'visibility' => 'public'], $this->headers($admin))->assertCreated()->json('data.id');
        $this->fileLimit(100 * 1024, 90 * 1024);

        $this->post("/api/v1/items/{$item->id}/attachments", ['file' => UploadedFile::fake()->create('a.pdf', 30, 'application/pdf')], $this->headers())
            ->assertStatus(402)->assertJsonPath('code', 'limit_reached');
        $this->post("/api/v1/bookmarks/{$group}/items", ['type' => 'file', 'file' => UploadedFile::fake()->create('b.pdf', 30, 'application/pdf')], $this->headers($admin))
            ->assertStatus(402)->assertJsonPath('code', 'limit_reached');
        $this->assertSame(0, $item->attachments()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());

        $this->post("/api/v1/items/{$item->id}/attachments", ['file' => UploadedFile::fake()->create('c.pdf', 5, 'application/pdf')], $this->headers())->assertCreated();
        // a link needs no room
        $this->postJson("/api/v1/bookmarks/{$group}/items", ['type' => 'todo', 'title' => 'Still fine'], $this->headers($admin))->assertCreated();
    }

    public function test_markdown_images_round_trip_through_a_doc(): void
    {
        $url = $this->upload(UploadedFile::fake()->image('chart.png', 10, 10))->assertCreated()->json('data.url');
        $doc = $this->postJson("/api/v1/projects/{$this->project->id}/pages", ['name' => 'With image', 'markdown' => "Look\n\n![chart]({$url})\n\n![x](data:image/png;base64,AAAA)"], $this->headers())
            ->assertCreated();

        $this->assertSame("Look\n\n![chart]({$url})\n\nx", $doc->json('data.markdown'));
        $this->assertStringContainsString('<img src="'.$url.'" alt="chart"', $doc->json('data.html'));
        $this->assertStringNotContainsString('data:image', $doc->json('data.html'));
    }
}
