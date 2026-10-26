<?php

namespace Tests\Feature\Imports;

use App\Models\ImportRun;
use App\Models\Item;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ItemAssignedNotification;
use App\Support\Boards\ItemComments;
use App\Support\Imports\Importer;
use App\Support\Imports\ImportFailed;
use App\Support\Imports\ImportMappings;
use App\Support\Imports\ImportProgress;
use App\Support\Imports\ImportRegistry;
use App\Support\Imports\ImportScope;
use App\Support\Imports\SafeZip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;
use ZipArchive;

/**
 * Import foundation (v1.41.0): lifecycle, permissions, idempotent mappings, safe archives and no
 * per-record notifications while importing. Importers have their own tests.
 */
class ImportFoundationTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $this->withoutVite();
        Storage::fake('local');
        ImportRegistry::fake('fake', FakeImporter::class);
        FakeImporter::$fail = null;
    }

    protected function tearDown(): void
    {
        ImportRegistry::fake('fake', null);
        parent::tearDown();
    }

    private function upload(?User $as = null, array $data = [])
    {
        return $this->actingAs($as ?? $this->editor)->post(route('imports.store', $this->workspace->slug), $data + [
            'source' => 'fake',
            'project_id' => $this->project->id,
            'file' => UploadedFile::fake()->createWithContent('cards.json', json_encode(['titles' => ['One', 'Two', 'Three']])),
        ]);
    }

    public function test_upload_previews_without_writing_then_start_imports_once(): void
    {
        $this->upload()->assertRedirect();
        $run = ImportRun::sole();
        $this->assertSame(ImportRun::PREVIEWED, $run->status);
        $this->assertSame(['count' => 3], $run->preview);
        $this->assertSame(0, Item::count()); // dry run
        Storage::disk('local')->assertExists($run->file_path);

        $this->get(route('imports.show', [$this->workspace->slug, $run->uuid]))->assertOk();
        $this->post(route('imports.start', [$this->workspace->slug, $run->uuid]), ['options' => ['prefix' => 'T-']])->assertRedirect();

        $run->refresh();
        $this->assertSame(ImportRun::COMPLETED, $run->status);
        $this->assertSame(['T-One', 'T-Three', 'T-Two'], Item::orderBy('title')->pluck('title')->all());
        $this->assertSame(['created' => ['issues' => 3], 'warnings' => [], 'unsupported' => ['Votes' => 2]], $run->report);
        $this->assertSame([3, 3], [$run->progress_done, $run->progress_total]);
        Storage::disk('local')->assertMissing($run->file_path); // the upload is deleted after a successful run
    }

    public function test_a_failed_run_resumes_without_duplicates(): void
    {
        $this->upload();
        $run = ImportRun::sole();
        FakeImporter::$fail = 2; // crash after two records

        try {
            $this->post(route('imports.start', [$this->workspace->slug, $run->uuid]), ['options' => ['prefix' => '']]);
        } catch (\RuntimeException) {
            // the sync queue rethrows; the job marked the run failed
        }
        $this->assertSame(2, Item::count());

        FakeImporter::$fail = null;
        $run->forceFill(['status' => ImportRun::FAILED])->save();
        $this->post(route('imports.start', [$this->workspace->slug, $run->uuid]))->assertRedirect();
        $this->assertSame(ImportRun::COMPLETED, $run->fresh()->status);
        $this->assertSame(3, Item::count()); // the first two weren't created again
    }

    public function test_an_import_failed_message_is_shown_and_nothing_is_retried(): void
    {
        $this->actingAs($this->editor)->post(route('imports.store', $this->workspace->slug), [
            'source' => 'fake', 'project_id' => $this->project->id,
            'file' => UploadedFile::fake()->createWithContent('x.json', 'not json'),
        ]);
        $run = ImportRun::sole();
        $this->assertSame(ImportRun::FAILED, $run->status);
        $this->assertSame('This isn’t a fake export.', $run->error);
        Storage::disk('local')->assertMissing('imports/'.$run->uuid.'/source.json');
        $this->post(route('imports.start', [$this->workspace->slug, $run->uuid]))->assertSessionHasErrors('run');
    }

    public function test_permissions(): void
    {
        $this->upload($this->viewer)->assertForbidden(); // the fake importer needs createBoards
        $this->upload($this->outsider)->assertForbidden();

        $other = Project::factory()->create(['workspace_id' => Workspace::factory()->create()->id, 'created_by' => $this->editor->id]);
        $this->upload($this->editor, ['project_id' => $other->id])->assertSessionHasErrors('project_id');

        $this->upload();
        $run = ImportRun::sole();
        $this->actingAs($this->viewer)->get(route('imports.show', [$this->workspace->slug, $run->uuid]))->assertForbidden();
        $admin = User::find($this->workspace->owner_id);
        $this->actingAs($admin)->get(route('imports.show', [$this->workspace->slug, $run->uuid]))->assertOk(); // workspace admins see every run
    }

    public function test_no_assignment_notifications_while_importing(): void
    {
        Notification::fake();
        $this->actingAs($this->editor);
        ImportScope::run(fn () => $this->item(['assigned_to' => $this->viewer->id]));
        Notification::assertNothingSent();

        $this->item(['assigned_to' => $this->viewer->id]);
        Notification::assertSentTo($this->viewer, ItemAssignedNotification::class);

        // comments and @mentions through the normal path stay silent too
        Notification::fake();
        $item = $this->item(['assigned_to' => $this->viewer->id]);
        Notification::fake();
        ImportScope::run(fn () => ItemComments::add($item, $this->editor, '<p>Ping <span data-lexical-mention="true" data-mention-id="'.$this->viewer->id.'">@Viewer</span></p>'));
        Notification::assertNothingSent();
    }

    public function test_safe_zip_refuses_traversal_symlinks_and_bombs(): void
    {
        $dir = sys_get_temp_dir().'/safezip-'.uniqid();
        $make = function (callable $fill) use ($dir) {
            @mkdir($dir, 0700, true);
            $path = $dir.'/'.uniqid().'.zip';
            $zip = new ZipArchive;
            $zip->open($path, ZipArchive::CREATE);
            $fill($zip);
            $zip->close();

            return $path;
        };

        $ok = $make(fn (ZipArchive $z) => $z->addFromString('Notes/Page 1.md', '# Hi') ?: null);
        $this->assertSame(['Notes/Page 1.md'], SafeZip::extract($ok, $dir.'/out'));
        $this->assertSame('# Hi', file_get_contents($dir.'/out/Notes/Page 1.md'));

        foreach ([
            '../evil.txt' => 'unsafe file name',
            '/etc/passwd' => 'unsafe file name',
            'C:/windows.txt' => 'unsafe file name',
        ] as $name => $message) {
            $bad = $make(fn (ZipArchive $z) => $z->addFromString($name, 'x') ?: null);
            try {
                SafeZip::extract($bad, $dir.'/out-'.md5($name));
                $this->fail("$name was accepted");
            } catch (ImportFailed $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        $link = $make(function (ZipArchive $z) {
            $z->addFromString('link', '/etc/passwd');
            $z->setExternalAttributesName('link', ZipArchive::OPSYS_UNIX, (0120777 << 16));
        });
        $this->expectExceptionMessage('symbolic link');
        try {
            SafeZip::extract($link, $dir.'/out-link');
        } finally {
            $bomb = $make(fn (ZipArchive $z) => $z->addFromString('zeros.txt', str_repeat('0', 20 * 1024 * 1024)) ?: null);
            try {
                SafeZip::extract($bomb, $dir.'/out-bomb');
                $this->fail('the bomb was accepted');
            } catch (ImportFailed $e) {
                $this->assertStringContainsString('compression bomb', $e->getMessage());
            }
        }
    }
}

/** A minimal importer: a JSON list of titles → issues on the project's first board. */
class FakeImporter implements Importer
{
    public static ?int $fail = null;

    public static function source(): string
    {
        return 'fake';
    }

    public static function label(): string
    {
        return 'Fake';
    }

    public static function description(): string
    {
        return 'Test importer.';
    }

    public static function accept(): string
    {
        return '.json';
    }

    public static function fileRules(): array
    {
        return ['max:1024'];
    }

    public static function needsProject(): bool
    {
        return true;
    }

    public static function authorize(User $user, Workspace $workspace, ?Project $project): bool
    {
        return $project !== null && $user->can('createBoards', $project);
    }

    public function preview(ImportRun $run, string $path): array
    {
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ! isset($data['titles'])) {
            throw new ImportFailed('This isn’t a fake export.');
        }

        return ['count' => count($data['titles'])];
    }

    public function optionRules(ImportRun $run): array
    {
        return ['prefix' => ['nullable', 'string', 'max:10']];
    }

    public function run(ImportRun $run, string $path, ImportProgress $progress): array
    {
        $titles = json_decode((string) file_get_contents($path), true)['titles'];
        $board = $run->project->boards()->first();
        $progress->total(count($titles));
        foreach ($titles as $i => $title) {
            if (self::$fail !== null && $i >= self::$fail) {
                throw new \RuntimeException('crash');
            }
            $before = ImportMappings::find($run, 'title', $i);
            ImportMappings::once($run, 'title', $i, Item::class, fn () => Item::factory()->create([
                'board_id' => $board->id,
                'title' => ($run->options['prefix'] ?? '').$title,
                'created_by' => $run->user_id,
            ]));
            if ($before === null) {
                $progress->created('issues');
            }
            $progress->advance();
        }
        $progress->unsupported('Votes', 2);

        return ['board_id' => $board->id, 'url' => '/'];
    }
}
