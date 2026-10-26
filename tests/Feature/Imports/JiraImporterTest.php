<?php

namespace Tests\Feature\Imports;

use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Comment;
use App\Models\ImportMapping;
use App\Models\ImportRun;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\ItemPriority;
use App\Models\ItemType;
use App\Models\Label;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\User;
use App\Support\Imports\ImportFailed;
use App\Support\Imports\Jira\CsvReader;
use App\Support\Imports\Jira\JiraExport;
use App\Support\Imports\Jira\JiraMarkup;
use Database\Seeders\ItemPrioritySeeder;
use Database\Seeders\ItemTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Jira CSV importer (v1.41.0, BRAI-139): the synthetic fixture (tests/Fixtures/imports/jira) has an
 * epic → story → sub-task, repeated Labels / Sprint / Comment columns, quotes, newlines and
 * semicolons inside cells, Unicode, a semicolon + BOM variant and damaged files.
 */
class JiraImporterTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    private User $jane;

    private User $zoe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ItemTypeSeeder::class, ItemPrioritySeeder::class]);
        $this->setUpBoard();
        Storage::fake('local');
        $this->jane = $this->projectMember('editor');
        $this->jane->update(['name' => 'Jane Doe']);
        $this->zoe = $this->projectMember('viewer');
        $this->zoe->update(['email' => 'zoe@example.test']);
    }

    private function fixture(string $name): string
    {
        return base_path('tests/Fixtures/imports/jira/'.$name);
    }

    private function upload(string $fixture = 'atlas.csv', ?User $as = null)
    {
        return $this->actingAs($as ?? $this->editor)->post(route('imports.store', $this->workspace->slug), [
            'source' => 'jira',
            'project_id' => $this->project->id,
            'file' => UploadedFile::fake()->createWithContent($fixture, (string) file_get_contents($this->fixture($fixture))),
        ]);
    }

    private function start(ImportRun $run, array $options = [])
    {
        return $this->post(route('imports.start', [$this->workspace->slug, $run->uuid]), [
            'options' => $options + $run->preview['defaults'],
        ]);
    }

    private function issue(string $jiraKey): Item
    {
        $id = ImportMapping::where('source_type', 'issue')->where('source_id', $jiraKey)->value('target_id');

        return Item::findOrFail($id);
    }

    private function typeId(string $slug): int
    {
        return ItemType::whereNull('project_id')->where('slug', $slug)->value('id');
    }

    public function test_the_preview_reads_everything_and_writes_nothing(): void
    {
        $this->upload()->assertRedirect();
        $run = ImportRun::sole();
        $this->assertSame(ImportRun::PREVIEWED, $run->status, (string) $run->error);
        $preview = $run->preview;

        $this->assertSame(5, $preview['issues']);
        $this->assertSame([['key' => 'ATL', 'name' => 'Atlas']], $preview['projects']);
        $this->assertSame(['To Do', 'In Progress', 'Code Review', 'Done', 'Closed'], array_column($preview['statuses'], 'name'));
        $this->assertSame([['name' => 'ATL Sprint 1', 'count' => 2], ['name' => 'ATL Sprint 2', 'count' => 1]], $preview['sprints']);
        $this->assertSame(2, $preview['comments']);
        $this->assertSame(3, $preview['links']);
        $this->assertSame(2, $preview['nested']);
        $this->assertSame(2, $preview['labels']);
        $this->assertSame([
            'Custom fields' => 2, 'Attachments' => 1, 'Watchers' => 2, 'Work log entries' => 1, 'Components' => 1, 'Versions (fix / affects)' => 1,
        ], $preview['unsupported']);

        // default mapping: done statuses → one Done column, types / priorities / people matched
        $defaults = $preview['defaults'];
        $this->assertSame(['To Do', 'In Progress', 'Code Review', 'Done', 'Done'], array_column($defaults['statuses'], 'column'));
        $types = array_column($defaults['types'], 'target', 'name');
        $this->assertSame((string) $this->typeId('epic'), $types['Epic']);
        $this->assertSame((string) $this->typeId('subtask'), $types['Sub-task']);
        $this->assertSame('create', $types['Improvement']);
        $priorities = array_column($defaults['priorities'], 'target', 'name');
        $this->assertSame(ItemPriority::where('slug', 'highest')->value('id'), $priorities['Blocker']);
        $people = array_column($defaults['people'], 'user_id', 'name');
        $this->assertSame($this->jane->id, $people['Jane Doe']);
        $this->assertSame($this->zoe->id, $people['zoe@example.test']); // by email
        $this->assertNull($people['Ola Nordmann']); // not a member: unassigned
        $this->assertTrue($defaults['create_sprints']);

        $this->assertSame(1, Board::count()); // dry run: only the board from setUp
        $this->assertSame(0, Item::count());
        $this->assertSame(0, ImportMapping::count());
    }

    public function test_the_semicolon_variant_with_a_bom_reads_the_same(): void
    {
        $comma = JiraExport::read($this->fixture('atlas.csv'));
        $semicolon = JiraExport::read($this->fixture('atlas-semicolon.csv'));

        $this->assertSame(',', $comma->delimiter);
        $this->assertSame(';', $semicolon->delimiter);
        $this->assertSame($comma->issues, $semicolon->issues);
        $this->assertSame('Ünïcödé 日本語 – émoji 🚀 task', $semicolon->issues[4]['summary']);
        // the comment cell holds a newline and semicolons; the author's account id → name
        $this->assertSame(['date' => '2024-03-17', 'author' => 'Jane Doe', 'body' => "Looks good; ship it.\nSecond line with *bold*."], $semicolon->issues[1]['comments'][0]);
        $this->assertSame(['payments', 'frontend'], $semicolon->issues[1]['labels']); // repeated Labels columns
        $this->assertSame(['ATL Sprint 1', 'ATL Sprint 2'], $semicolon->issues[3]['sprints']);
    }

    public function test_import_creates_the_board_hierarchy_comments_and_links(): void
    {
        Notification::fake();
        $existing = $this->project->labels()->create(['name' => 'Payments', 'slug' => 'payments', 'color' => '#000000']);
        $this->upload();
        $run = ImportRun::sole();
        $this->start($run)->assertRedirect()->assertSessionHasNoErrors();
        $run->refresh();
        $this->assertSame(ImportRun::COMPLETED, $run->status, (string) $run->error);

        $board = Board::findOrFail($run->result['board_id']);
        $this->assertSame('scrum', $board->type); // sprints exist and were chosen
        $this->assertStringStartsWith('Jira import ', $board->name);
        $this->assertSame(route('boards.backlog', [$this->workspace->slug, $this->project->slug, $board->slug]), $run->result['url']);
        $columns = $board->columns()->get();
        $this->assertSame(['To Do', 'In Progress', 'Code Review', 'Done'], $columns->pluck('name')->all());
        $this->assertSame(['Done'], $columns->where('is_done_column', true)->pluck('name')->values()->all());

        $epic = $this->issue('ATL-1');
        $story = $this->issue('ATL-2');
        $subtask = $this->issue('ATL-3');
        $bug = $this->issue('ATL-4');
        $task = $this->issue('ATL-5');

        // epic → story → sub-task, new Brainitive keys
        $this->assertNull($epic->parent_id);
        $this->assertSame($epic->id, $story->parent_id);
        $this->assertSame($story->id, $subtask->parent_id);
        $this->assertSame(['BRAI-1', 'BRAI-2', 'BRAI-3', 'BRAI-4', 'BRAI-5'], Item::orderBy('key')->pluck('key')->all());
        $this->assertSame([$this->typeId('epic'), $this->typeId('story'), $this->typeId('subtask'), $this->typeId('bug')], [$epic->item_type_id, $story->item_type_id, $subtask->item_type_id, $bug->item_type_id]);
        $improvement = ItemType::where('project_id', $this->project->id)->sole();
        $this->assertSame('Improvement', $improvement->name);
        $this->assertSame($improvement->id, $task->item_type_id);

        // fields
        $this->assertSame('Pay with card ✓', $story->title);
        $this->assertSame('Ünïcödé 日本語 – émoji 🚀 task', $task->title);
        $this->assertSame([$this->columnId($board, 'In Progress'), $this->columnId($board, 'To Do'), $this->columnId($board, 'Code Review'), $this->columnId($board, 'Done'), $this->columnId($board, 'Done')], [$epic->column_id, $story->column_id, $subtask->column_id, $bug->column_id, $task->column_id]);
        $this->assertNotNull($bug->completed_at);
        $this->assertSame('2024-03-20', $bug->completed_at->toDateString());
        $this->assertNull($story->completed_at);
        $this->assertSame(ItemPriority::where('slug', 'highest')->value('id'), $bug->priority_id); // Blocker
        $this->assertSame(ItemPriority::where('slug', 'high')->value('id'), $epic->priority_id);
        $this->assertSame(ItemPriority::where('is_default', true)->value('id'), $task->priority_id); // none in Jira
        $this->assertSame(5, $story->story_points);
        $this->assertSame(120, $subtask->original_estimate); // 7200 s
        $this->assertSame('2024-03-24', $subtask->due_date->toDateString());
        $this->assertSame($this->jane->id, $epic->assigned_to);
        $this->assertNull($story->assigned_to); // Ola Nordmann isn't a member
        $this->assertSame($this->zoe->id, $task->reporter_id); // matched by email
        $this->assertSame($this->editor->id, $bug->reporter_id); // unmapped reporter → the importer
        $this->assertSame($this->editor->id, $story->created_by);

        // descriptions: reference line + converted, sanitized wiki markup
        $this->assertStringContainsString('<em>Jira: ATL-2 · Reported by Jane Doe on 5 Mar 2024</em>', $story->description);
        $this->assertStringContainsString('<table>', $story->description);
        $this->assertStringContainsString('&lt;b&gt;not bold&lt;/b&gt;', $story->description); // code stays text
        $this->assertStringContainsString('<a href="https://example.com/fees" rel="noopener noreferrer">pricing</a>', $story->description);
        $this->assertStringContainsString('<h2>Goal</h2>', $epic->description);
        $this->assertStringContainsString('<ul><li>One page</li><li>Saved cards<ul><li>Visa</li><li>Mastercard</li></ul></li></ul>', $epic->description);
        $this->assertStringContainsString('<ol><li>Open the cart</li>', $bug->description);
        $this->assertStringContainsString('<h3>Environment</h3>', $task->description);

        // labels: the existing "Payments" reused, "frontend" created
        $this->assertSame(['frontend', 'Payments'], Label::where('project_id', $this->project->id)->orderBy('slug')->pluck('name')->all());
        $this->assertSame([$existing->id], $epic->labels()->pluck('labels.id')->all());
        $this->assertCount(2, $story->labels);

        // sprints: planned, the issue's last sprint, subtasks follow their parent
        $this->assertSame(['ATL Sprint 1', 'ATL Sprint 2'], $board->sprints()->pluck('name')->all());
        $this->assertSame([Sprint::STATUS_PLANNED], $board->sprints()->pluck('status')->unique()->values()->all());
        $sprint1 = $board->sprints()->where('name', 'ATL Sprint 1')->value('id');
        $this->assertSame($sprint1, $story->sprint_id);
        $this->assertSame($sprint1, $subtask->sprint_id);
        $this->assertSame($board->sprints()->where('name', 'ATL Sprint 2')->value('id'), $bug->sprint_id);
        $this->assertSame($sprint1, $epic->sprint_id); // the epic takes its stories' sprint

        // comments by the importing user, the original author on top
        $comments = $story->comments()->orderBy('id')->get();
        $this->assertCount(2, $comments);
        $this->assertSame($this->editor->id, $comments[0]->user_id);
        $this->assertStringStartsWith('<p><em>Originally by Jane Doe on 17 Mar 2024</em></p>', $comments[0]->body);
        $this->assertStringContainsString('Looks good; ship it.<br />Second line with <strong>bold</strong>.', $comments[0]->body);
        $this->assertStringStartsWith('<p><em>Originally by a Jira user on 18 Mar 2024</em></p>', $comments[1]->body);

        // links between imported issues only
        $this->assertSame([
            [$story->id, $bug->id, 'blocks'],
            [$story->id, $task->id, 'relates_to'],
        ], ItemLink::orderBy('id')->get()->map(fn ($l) => [$l->source_item_id, $l->target_item_id, $l->link_type])->all());

        $report = $run->report;
        $this->assertEquals(['boards' => 1, 'columns' => 4, 'item types' => 1, 'sprints' => 2, 'labels' => 1, 'issues' => 5, 'comments' => 2, 'links' => 2], $report['created']);
        $this->assertSame(2, $report['unsupported']['Custom fields']);
        $this->assertContains('1 issue links point at issues that aren’t in this file and were skipped.', $report['warnings']);

        Notification::assertNothingSent(); // assignments, comments: nobody is notified per record
    }

    public function test_the_chosen_mapping_is_applied(): void
    {
        $this->upload();
        $run = ImportRun::sole();
        $ola = $this->projectMember('editor');
        $this->start($run, [
            'board_name' => 'Atlas from Jira',
            'statuses' => [
                ['name' => 'To Do', 'column' => 'Backlog'],
                ['name' => 'In Progress', 'column' => 'Doing'],
                ['name' => 'Code Review', 'column' => 'doing'], // merged, case-insensitive
                ['name' => 'Done', 'column' => 'Shipped'],
                ['name' => 'Closed', 'column' => 'Shipped'],
            ],
            'done_column' => 'Shipped',
            'types' => [['name' => 'Improvement', 'target' => (string) $this->typeId('task')]],
            'priorities' => [['name' => 'Blocker', 'target' => ItemPriority::where('slug', 'low')->value('id')]],
            'people' => [['name' => 'Ola Nordmann', 'user_id' => $ola->id], ['name' => 'Jane Doe', 'user_id' => null]],
            'import_comments' => false,
            'create_sprints' => false,
        ])->assertSessionHasNoErrors();
        $run->refresh();
        $this->assertSame(ImportRun::COMPLETED, $run->status, (string) $run->error);

        $board = Board::findOrFail($run->result['board_id']);
        $this->assertSame(['Atlas from Jira', 'atlas-from-jira', 'kanban'], [$board->name, $board->slug, $board->type]);
        $this->assertStringEndsWith('/boards/atlas-from-jira', $run->result['url']); // Kanban: the board itself
        $this->assertSame(['Backlog', 'Doing', 'Shipped'], $board->columns()->pluck('name')->all());
        $this->assertTrue((bool) $board->columns()->where('name', 'Shipped')->value('is_done_column'));
        $this->assertSame($this->columnId($board, 'Doing'), $this->issue('ATL-3')->column_id);
        $this->assertSame($this->typeId('task'), $this->issue('ATL-5')->item_type_id);
        $this->assertSame(0, ItemType::where('project_id', $this->project->id)->count());
        $this->assertSame(ItemPriority::where('slug', 'low')->value('id'), $this->issue('ATL-4')->priority_id);
        $this->assertSame($ola->id, $this->issue('ATL-2')->assigned_to);
        $this->assertNull($this->issue('ATL-1')->assigned_to);
        $this->assertSame(0, Comment::count());
        $this->assertSame(0, Sprint::count());
        $this->assertSame(2, $run->report['unsupported']['Comments (not chosen)']);
    }

    public function test_invalid_options_are_refused(): void
    {
        $this->upload();
        $run = ImportRun::sole();
        $other = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->editor->id]);
        $foreignType = ItemType::create(['project_id' => $other->id, 'name' => 'Other', 'slug' => 'other']);
        $outsider = $this->outsider;

        $this->start($run, [
            'types' => [['name' => 'Epic', 'target' => (string) $foreignType->id]],
            'people' => [['name' => 'Jane Doe', 'user_id' => $outsider->id]],
            'statuses' => [['name' => 'To Do', 'column' => '']],
        ])->assertSessionHasErrors(['options.types.0.target', 'options.people.0.user_id', 'options.statuses.0.column']);
        $this->assertSame(ImportRun::PREVIEWED, $run->fresh()->status);
    }

    public function test_a_crash_halfway_resumes_without_duplicates(): void
    {
        $this->upload();
        $run = ImportRun::sole();
        $created = 0;
        Item::creating(function () use (&$created) {
            if (++$created > 2 && ! app()->bound('jira-test-resume')) {
                throw new \RuntimeException('crash');
            }
        });

        try {
            $this->start($run);
        } catch (\RuntimeException) {
            // the sync queue rethrows; the job marked the run failed
        }
        $this->assertSame(2, Item::count());

        app()->instance('jira-test-resume', true);
        $run->forceFill(['status' => ImportRun::FAILED])->save();
        $this->post(route('imports.start', [$this->workspace->slug, $run->uuid]))->assertRedirect();
        $run->refresh();
        $this->assertSame(ImportRun::COMPLETED, $run->status, (string) $run->error);

        $this->assertSame(5, Item::count());
        $this->assertSame(2, Board::count()); // setUp's board + one import
        $this->assertSame(4, BoardColumn::where('board_id', $run->result['board_id'])->count());
        $this->assertSame(2, Sprint::count());
        $this->assertSame(2, Comment::count());
        $this->assertSame(2, ItemLink::count());
        $this->assertSame(1, ItemType::where('project_id', $this->project->id)->count());
        $this->assertSame(2, Label::count());
        $this->assertSame(['BRAI-1', 'BRAI-2', 'BRAI-3', 'BRAI-4', 'BRAI-5'], Item::orderBy('key')->pluck('key')->all());
        $this->assertSame($this->issue('ATL-2')->id, $this->issue('ATL-3')->parent_id);
        $this->assertSame(5, $run->report['created']['issues']);
        $this->assertSame(1, $run->report['unsupported']['Attachments']); // not counted twice
    }

    public function test_damaged_and_foreign_files_fail_in_the_preview(): void
    {
        foreach ([
            'malformed.csv' => 'unclosed quote',
            'not-jira.csv' => 'doesn’t look like a Jira CSV export',
        ] as $fixture => $message) {
            $this->upload($fixture);
            $run = ImportRun::latest('id')->first();
            $this->assertSame(ImportRun::FAILED, $run->status);
            $this->assertStringContainsString($message, (string) $run->error);
        }
        $this->assertSame(0, Item::count());
        $this->assertSame(1, Board::count());
    }

    public function test_the_csv_reader_limits_and_damaged_rows(): void
    {
        $dir = sys_get_temp_dir().'/jira-csv-'.uniqid();
        mkdir($dir);

        file_put_contents("$dir/rows.csv", "Summary,Issue key\nA,X-1\n\nB,X-2\nC,X-3\n");
        $reader = new CsvReader("$dir/rows.csv");
        $this->assertSame([2 => ['A', 'X-1'], 4 => ['B', 'X-2'], 5 => ['C', 'X-3']], iterator_to_array($reader->rows(10)));
        try {
            iterator_to_array($reader->rows(2));
            $this->fail('the row limit was ignored');
        } catch (ImportFailed $e) {
            $this->assertStringContainsString('more than 2 rows', $e->getMessage());
        }

        file_put_contents("$dir/wide.csv", "Summary,Issue key\nA,X-1,surprise\n");
        $this->expectExceptionMessage('Row 2 has more cells than the header row');
        try {
            iterator_to_array((new CsvReader("$dir/wide.csv"))->rows(10));
        } finally {
            // Windows-1252 cells (a file re-saved in Excel) are converted, tabs are detected
            file_put_contents("$dir/latin.csv", "Summary\tIssue key\n\"Caf\xE9\"\tX-1\n");
            $latin = new CsvReader("$dir/latin.csv");
            $this->assertSame("\t", $latin->delimiter());
            $this->assertSame([2 => ['Café', 'X-1']], iterator_to_array($latin->rows(10)));
            $this->assertTrue($latin->recoded());
        }
    }

    public function test_viewers_cannot_import(): void
    {
        $this->upload('atlas.csv', $this->viewer)->assertForbidden();
        $this->assertSame(0, ImportRun::count());
    }

    public function test_wiki_markup_conversion(): void
    {
        $html = JiraMarkup::toHtml(<<<'WIKI'
h1. Title
Text with *bold*, _italic_, -gone-, +under+, {{code()}}, x^2^ and H~2~O.
Line one\\line two, an escaped \*star\* and {color:red}red{color}.

# first
#* nested bullet
# second

{quote}
Quoted *text*
{quote}

{noformat}
raw *not bold* <script>
{noformat}

{panel:title=Note}
Inside a panel
{panel}

||A||B||
|[x|https://example.com/a_b]|[evil|javascript:alert(1)]|

!screenshot.png|thumbnail! and !https://example.com/pic.png! and [^report.pdf]
----
<script>alert(1)</script> well-known 2024-01-01 snake_case_name
WIKI, ['abc' => 'Jane Doe']);

        $this->assertStringContainsString('<h1>Title</h1>', $html);
        $this->assertStringContainsString('Text with <strong>bold</strong>, <em>italic</em>, <s>gone</s>, <u>under</u>, <code>code()</code>, x<sup>2</sup> and H<sub>2</sub>O.', $html);
        $this->assertStringContainsString('Line one<br />line two, an escaped *star* and red.', $html);
        $this->assertStringContainsString('<ol><li>first<ul><li>nested bullet</li></ul></li><li>second</li></ol>', $html);
        $this->assertStringContainsString('<blockquote><p>Quoted <strong>text</strong></p></blockquote>', $html);
        $this->assertStringContainsString('<pre><code>raw *not bold* &lt;script&gt;</code></pre>', $html);
        $this->assertStringContainsString('<blockquote><p><strong>Note</strong></p><p>Inside a panel</p></blockquote>', $html);
        $this->assertStringContainsString('<th>A</th><th>B</th>', $html);
        $this->assertStringContainsString('<a href="https://example.com/a_b" rel="noopener noreferrer">x</a>', $html);
        $this->assertStringContainsString('<td>evil</td>', $html); // not a link
        $this->assertStringNotContainsString('javascript', $html);
        $this->assertStringContainsString('[image: screenshot.png (not imported)]', $html);
        $this->assertStringNotContainsString('<img', $html); // nothing is fetched
        $this->assertStringContainsString('report.pdf (attachment, not imported)', $html);
        $this->assertStringContainsString('<hr />', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; well-known 2024-01-01 snake_case_name', $html);
        $this->assertStringNotContainsString('<script', $html);

        $this->assertSame('<p>Thanks <strong>&#64;Jane Doe</strong></p>', JiraMarkup::toHtml('Thanks [~accountid:abc]', ['abc' => 'Jane Doe']));

        // Markdown is recognised by unambiguous markers; "# item" stays a wiki list
        $this->assertTrue(JiraMarkup::looksLikeMarkdown('Some **bold** and [a link](https://example.com)'));
        $this->assertFalse(JiraMarkup::looksLikeMarkdown("# one\n# two"));
        $this->assertStringContainsString('<strong>bold</strong>', JiraMarkup::toHtml("```\ncode\n```\n\nSome **bold**"));
        $this->assertSame('', JiraMarkup::toHtml("  \n "));
    }

    private function columnId(Board $board, string $name): int
    {
        return $board->columns()->where('name', $name)->value('id');
    }
}
