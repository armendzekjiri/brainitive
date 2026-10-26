<?php

namespace Tests\Feature\Imports;

use App\Models\ImportMapping;
use App\Models\ImportRun;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\User;
use App\Support\Imports\Shared\MarkdownHtml;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Normalizer;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;
use ZipArchive;

/**
 * Notion "Markdown & CSV" importer (BRAI-66). The export is built from the synthetic files under
 * tests/Fixtures/imports/notion/: a wiki page with subpages, a database with row pages, Unicode
 * names, a table, code, lists, an image, a PDF, an SVG (not allowed), broken links and a page with
 * script / javascript: links.
 */
class NotionImporterTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    private const WIKI = '0123456789abcdef0123456789abcdef';

    private const GETTING_STARTED = '11111111111111111111111111111111';

    private const CAFE = '22222222222222222222222222222222';

    private const TASKS = '44444444444444444444444444444444';

    private const WRITE_DOCS = '55555555555555555555555555555555';

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Storage::fake('local');
        Storage::fake('public');
        $this->tmp = sys_get_temp_dir().'/notion-test-'.uniqid();
        mkdir($this->tmp, 0700, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    /** The fixture tree as a Notion export ZIP; $nested wraps it like Notion's large exports. */
    private function exportZip(bool $nested = false, ?callable $extra = null): string
    {
        $path = $this->tmp.'/'.uniqid().'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $root = base_path('tests/Fixtures/imports/notion');
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $zip->addFile($file->getPathname(), Normalizer::normalize(substr($file->getPathname(), strlen($root) + 1), Normalizer::FORM_C));
        }
        if ($extra) {
            $extra($zip);
        }
        $zip->close();
        if (! $nested) {
            return $path;
        }

        $outer = $this->tmp.'/'.uniqid().'-outer.zip';
        $zip = new ZipArchive;
        $zip->open($outer, ZipArchive::CREATE);
        $zip->addFile($path, 'Export-1b2c3d4e-0000-4000-8000-000000000000-Part-1.zip');
        $zip->close();

        return $outer;
    }

    private function zipOf(array $entries): string
    {
        $path = $this->tmp.'/'.uniqid().'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    private function upload(string $zip, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->editor)->post(route('imports.store', $this->workspace->slug), [
            'source' => 'notion',
            'project_id' => $this->project->id,
            'file' => new UploadedFile($zip, 'Export-notion.zip', 'application/zip', null, true),
        ]);
    }

    private function start(ImportRun $run, array $options = [])
    {
        return $this->post(route('imports.start', [$this->workspace->slug, $run->uuid]), ['options' => $options]);
    }

    private function folder(string $name): Page
    {
        return Page::create(['project_id' => $this->project->id, 'name' => $name, 'type' => 'folder', 'created_by' => $this->editor->id]);
    }

    private function imported(string $sourceType, string $key): Page
    {
        $id = ImportMapping::query()->where('source_type', $sourceType)->where('source_id', $key)->value('target_id');
        $this->assertNotNull($id, "$sourceType $key was not imported");

        return Page::findOrFail($id);
    }

    private function html(Page $page): string
    {
        return (string) ($page->fresh()->content['html'] ?? '');
    }

    private function url(Page $page): string
    {
        return route('pages.show', [$this->workspace->slug, $this->project->slug, $page->uuid], false);
    }

    public function test_preview_shows_the_tree_files_and_problems_without_writing(): void
    {
        $docs = $this->folder('Docs');
        $this->upload($this->exportZip())->assertRedirect();
        $run = ImportRun::sole();
        $this->assertSame(ImportRun::PREVIEWED, $run->status, (string) $run->error);
        $preview = $run->preview;

        $this->assertSame(1, Page::count()); // only "Docs": a dry run
        $this->assertSame([], Storage::disk('public')->allFiles());

        $this->assertSame(['Archive', 'Team Wiki'], array_column($preview['tree'], 'title'));
        $wiki = $preview['tree'][1];
        $this->assertSame('page', $wiki['kind']);
        // in the order the wiki page links them
        $this->assertSame(['Getting Started', 'Tasks', 'Café ☕ Notes', 'Empty Page'], array_column($wiki['children'], 'title'));
        $this->assertSame(['database', 3], [$wiki['children'][1]['kind'], $wiki['children'][1]['rows']]);
        $this->assertTrue($wiki['children'][3]['empty']);
        $this->assertSame('folder', $preview['tree'][0]['kind']);

        $this->assertSame(['pages' => 7, 'databases' => 1, 'folders' => 1, 'empty_pages' => 1, 'images' => 1, 'files' => 2], $preview['counts']);
        $this->assertSame(2, $preview['broken_link_count']);
        $this->assertEqualsCanonicalizing(['Gone 99999999999999999999999999999999.md', 'missing picture'], array_map(
            fn ($b) => str_contains($b['target'], 'missing') ? 'missing picture' : basename($b['target']),
            $preview['broken_links'],
        ));
        $this->assertSame([['page' => 'Getting Started', 'target' => 'clip.svg']], $preview['skipped_files']);
        $this->assertSame(1, $preview['unsupported']['Database views, formulas and relations (databases come over as tables)']);
        $this->assertSame([['id' => $docs->id, 'path' => 'Docs']], $preview['folders']);
    }

    public function test_import_creates_a_draft_tree_with_links_files_and_tables(): void
    {
        $docs = $this->folder('Docs');
        $this->upload($this->exportZip());
        $run = ImportRun::sole();
        $this->start($run, ['parent_id' => $docs->id, 'skip_empty' => true])->assertRedirect();
        $run->refresh();
        $this->assertSame(ImportRun::COMPLETED, $run->status, (string) $run->error);

        // the tree: a page with subpages becomes a folder holding the page first
        $wikiFolder = $this->imported('notion_folder', self::WIKI);
        $wiki = $this->imported('notion_page', self::WIKI);
        $this->assertSame([$docs->id, 'folder', 'Team Wiki'], [$wikiFolder->parent_id, $wikiFolder->type, $wikiFolder->name]);
        $this->assertSame(
            ['Team Wiki', 'Getting Started', 'Tasks', 'Café ☕ Notes'],
            Page::where('parent_id', $wikiFolder->id)->orderBy('position')->pluck('name')->all(),
        );
        $this->assertSame(0, Page::where('name', 'Empty Page')->count()); // skipped
        $tasksFolder = $this->imported('notion_folder', self::TASKS);
        $this->assertSame(['Tasks', 'Write docs', 'Ship it'], Page::where('parent_id', $tasksFolder->id)->orderBy('position')->pluck('name')->all());
        $archive = Page::where('name', 'Archive')->sole();
        $this->assertSame([$docs->id, 'folder'], [$archive->parent_id, $archive->type]);
        $this->assertSame(['Old Idea'], $archive->children()->pluck('name')->all());

        // all pages are drafts by the importing user; nothing published
        $pages = Page::where('type', 'page')->get();
        $this->assertCount(7, $pages);
        $this->assertTrue($pages->every(fn (Page $p) => $p->status === 'draft' && $p->created_by === $this->editor->id && $p->published_at === null));
        $this->assertSame(0, PageVersion::count());

        // links between pages → the new page URLs; a skipped page's link → its words only
        $getting = $this->imported('notion_page', self::GETTING_STARTED);
        $cafe = $this->imported('notion_page', self::CAFE);
        $html = $this->html($wiki);
        $this->assertStringContainsString('href="'.$this->url($getting).'"', $html);
        $this->assertStringContainsString('href="'.$this->url($cafe).'"', $html);
        $this->assertStringContainsString('Empty Page', $html);
        $this->assertStringNotContainsString('Empty%20Page', $html);
        $this->assertStringNotContainsString('<h1>Team Wiki</h1>', $html); // the title is the page name

        $html = $this->html($getting);
        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('<pre data-language="bash"><code>npm install', $html);
        $this->assertStringContainsString('<li aria-checked="false">Read the handbook</li>', $html);
        $this->assertStringContainsString('<li aria-checked="true">Get a laptop</li>', $html);
        $this->assertMatchesRegularExpression('#<ol>\s*<li>Install the tools</li>\s*<li>Clone the repo\s*<ul>#', $html);
        $this->assertStringContainsString('href="'.$this->url($wiki).'"', $html); // "../Team Wiki ….md"
        $this->assertStringContainsString('href="https://example.com/docs"', $html);
        // the image and the PDF are stored like editor uploads; the SVG is not allowed
        $images = Storage::disk('public')->files('uploads/images');
        $this->assertCount(1, $images);
        $this->assertStringEndsWith('.png', $images[0]);
        $this->assertStringContainsString('src="/storage/'.$images[0].'"', $html);
        $documents = Storage::disk('public')->files('uploads/documents');
        $this->assertCount(2, $documents); // spec.pdf + the Tasks CSV
        $pdf = collect($documents)->first(fn ($f) => str_ends_with($f, '.pdf'));
        $this->assertStringContainsString('href="/storage/'.$pdf.'"', $html);
        $this->assertStringContainsString('the clip.', $html);
        $this->assertStringNotContainsString('clip.svg', $html);
        // broken references are kept as text and reported
        $this->assertStringContainsString('This old page was deleted.', $html);
        $this->assertStringContainsString('[missing picture]', $html);
        $this->assertStringNotContainsString('.md"', $html);

        $html = $this->html($cafe);
        $this->assertSame('Café ☕ Notes', $cafe->name);
        $this->assertStringContainsString('Grüße, 日本語, emoji 🎉.<br>', $html);
        $this->assertStringContainsString('href="'.$this->url($wiki).'"', $html); // a notion.so URL to an imported page
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('onerror', $html);

        $html = $this->html($this->imported('notion_page', self::TASKS));
        $this->assertStringContainsString('Databases come over as a table', $html);
        $this->assertStringContainsString('<th>Notes</th>', $html);
        $this->assertStringContainsString('Line one<br>line two, with comma', $html);
        $this->assertStringContainsString('release, "v1"', $html);
        $this->assertStringContainsString('Ünïcødé row', $html);
        $this->assertStringContainsString('href="'.$this->url($this->imported('notion_page', self::WRITE_DOCS)).'"', $html);
        $csv = collect($documents)->first(fn ($f) => str_ends_with($f, '.csv'));
        $this->assertStringContainsString('href="/storage/'.$csv.'" rel="noopener noreferrer">Tasks.csv</a>', $html);

        $this->assertStringContainsString("Status: Done<br>\nTags: docs", $this->html($this->imported('notion_page', self::WRITE_DOCS)));

        // report + result
        $this->assertSame(['folders' => 3, 'pages' => 6, 'tables' => 1, 'images' => 1, 'files' => 2], $run->report['created']);
        $this->assertSame(1, $run->report['unsupported']['Database views, formulas and relations (databases come over as tables)']);
        $this->assertSame(1, $run->report['unsupported']['Files of types the editor doesn’t accept']);
        $this->assertSame(2, $run->report['unsupported']['Links to files or pages missing from the export']);
        $this->assertContains('“Empty Page” was empty and was skipped.', $run->report['warnings']);
        $this->assertSame($this->url($docs), $run->result['url']); // two top-level entries: the parent folder
        Storage::disk('local')->assertMissing($run->file_path);
    }

    public function test_a_nested_part_zip_is_unpacked_and_empty_pages_can_be_kept(): void
    {
        $this->upload($this->exportZip(nested: true));
        $run = ImportRun::sole();
        $this->assertSame(ImportRun::PREVIEWED, $run->status, (string) $run->error);
        $this->assertSame(['Archive', 'Team Wiki'], array_column($run->preview['tree'], 'title'));

        $this->start($run, ['skip_empty' => false]);
        $this->assertSame(ImportRun::COMPLETED, $run->fresh()->status);
        $empty = Page::where('name', 'Empty Page')->sole();
        $this->assertSame('draft', $empty->status);
        $this->assertSame('', $this->html($empty));
        $this->assertNull($this->imported('notion_folder', self::WIKI)->parent_id); // project root
    }

    public function test_a_crash_halfway_resumes_without_duplicates(): void
    {
        $this->upload($this->exportZip());
        $run = ImportRun::sole();

        // 1st attempt: crash in the tree pass, after four records
        $created = 0;
        $crashCreate = true;
        Page::creating(function () use (&$created, &$crashCreate) {
            if ($crashCreate && ++$created > 4) {
                throw new RuntimeException('crash');
            }
        });
        try {
            $this->start($run, ['skip_empty' => true]);
        } catch (RuntimeException) {
            // the sync queue rethrows; the job marked the run failed
        }
        $this->assertSame(4, Page::count());

        // 2nd attempt: crash in the content pass, after two pages got their content
        $crashCreate = false;
        $updated = 0;
        $crashUpdate = true;
        Page::updating(function () use (&$updated, &$crashUpdate) {
            if ($crashUpdate && ++$updated > 2) {
                throw new RuntimeException('crash');
            }
        });
        $run->forceFill(['status' => ImportRun::FAILED])->save();
        try {
            $this->post(route('imports.start', [$this->workspace->slug, $run->uuid]));
        } catch (RuntimeException) {
        }
        $this->assertSame(10, Page::count()); // the whole tree: 6 pages + 1 table page + 3 folders

        // 3rd attempt: completes
        $crashUpdate = false;
        $run->forceFill(['status' => ImportRun::FAILED])->save();
        $this->post(route('imports.start', [$this->workspace->slug, $run->uuid]))->assertRedirect();
        $run->refresh();
        $this->assertSame(ImportRun::COMPLETED, $run->status, (string) $run->error);

        $this->assertSame(10, Page::count());
        $this->assertSame(1, Page::where('name', 'Getting Started')->count());
        $this->assertCount(1, Storage::disk('public')->files('uploads/images'));
        $this->assertCount(2, Storage::disk('public')->files('uploads/documents'));
        $this->assertSame(['folders' => 3, 'pages' => 6, 'tables' => 1, 'images' => 1, 'files' => 2], $run->report['created']);
        $this->assertSame(2, $run->report['unsupported']['Links to files or pages missing from the export']); // not counted twice
        $getting = $this->imported('notion_page', self::GETTING_STARTED);
        $this->assertStringContainsString('href="'.$this->url($this->imported('notion_page', self::WIKI)).'"', $this->html($getting));
    }

    public function test_imported_pages_are_drafts_only_the_importer_and_admins_see(): void
    {
        $this->upload($this->exportZip());
        $this->start(ImportRun::sole(), ['skip_empty' => true]);
        $page = $this->imported('notion_page', self::GETTING_STARTED);
        $folder = $this->imported('notion_folder', self::WIKI);
        $show = fn (Page $p) => route('pages.show', [$this->workspace->slug, $this->project->slug, $p->uuid]);

        $this->actingAs($this->editor)->get($show($page))->assertOk();
        $this->actingAs($this->viewer)->get($show($page))->assertForbidden();
        $this->actingAs($this->projectMember('editor'))->get($show($page))->assertForbidden();
        $this->actingAs(User::find($this->workspace->owner_id))->get($show($page))->assertOk();
        $this->actingAs($this->viewer)->get($show($folder))->assertOk(); // folders follow project access
        $this->assertFalse(Page::query()->visibleTo($this->viewer)->whereKey($page->id)->exists());
    }

    public function test_only_people_who_may_create_pages_can_import(): void
    {
        $this->upload($this->exportZip(), $this->viewer)->assertForbidden();
        $this->upload($this->exportZip(), $this->outsider)->assertForbidden();
        $this->assertSame(0, ImportRun::count());
    }

    public function test_unsafe_or_wrong_archives_fail_in_the_preview(): void
    {
        $link = $this->tmp.'/link.zip';
        $zip = new ZipArchive;
        $zip->open($link, ZipArchive::CREATE);
        $zip->addFromString('Page 0123456789abcdef0123456789abcdef.md', '/etc/passwd');
        $zip->setExternalAttributesName('Page 0123456789abcdef0123456789abcdef.md', ZipArchive::OPSYS_UNIX, (0120777 << 16));
        $zip->close();
        $notAZip = $this->tmp.'/plain.zip';
        file_put_contents($notAZip, 'just text');

        $cases = [
            'unsafe file name' => $this->zipOf(['../evil 0123456789abcdef0123456789abcdef.md' => '# Evil']),
            'symbolic link' => $link,
            'isn’t a readable ZIP' => $notAZip,
            'doesn’t look like a Notion export' => $this->zipOf(['readme.txt' => 'hello', 'photo.png' => 'x']),
            'its CSV file is damaged' => $this->zipOf(['Db 0123456789abcdef0123456789abcdef.csv' => "Name,Notes\nA,\"never closed\n"]),
            'is too long to import' => $this->zipOf(['Table 0123456789abcdef0123456789abcdef.md' => "|a|\n|-|\n".str_repeat("|x|\n", 60000)]),
            'more than 256 KB of text' => $this->zipOf(['Huge 0123456789abcdef0123456789abcdef.md' => '# Huge'."\n".str_repeat('a', 300 * 1024)]),
            'unsafe file name ("..")' => $this->exportZip(nested: true, extra: fn (ZipArchive $z) => $z->addFromString('a/../../evil.md', 'x')),
        ];
        foreach ($cases as $message => $path) {
            $this->upload($path)->assertRedirect();
            $run = ImportRun::query()->latest('id')->first();
            $this->assertSame(ImportRun::FAILED, $run->status, $message);
            $this->assertStringContainsString($message, (string) $run->error);
            Storage::disk('local')->assertMissing('imports/'.$run->uuid);
        }
        $this->assertSame(0, Page::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_markdown_html_is_sanitized_and_keeps_editor_features(): void
    {
        $html = MarkdownHtml::toHtml("- [ ] todo\n- [x] done\n\n```php\necho 1;\n```\n\nA [link](javascript:alert(1)) <img src=x onerror=alert(1)><script>x</script>\n\n| a | b |\n|---|---|\n| 1 | 2 |");

        $this->assertStringContainsString('<li aria-checked="false">todo</li>', $html);
        $this->assertStringContainsString('<li aria-checked="true">done</li>', $html);
        $this->assertStringContainsString('<pre data-language="php"><code>echo 1;', $html);
        $this->assertStringContainsString('<td>1</td>', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertSame('', MarkdownHtml::toHtml("  \n"));
    }
}
