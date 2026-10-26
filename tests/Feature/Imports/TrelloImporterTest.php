<?php

namespace Tests\Feature\Imports;

use App\Models\Board;
use App\Models\ImportMapping;
use App\Models\ImportRun;
use App\Models\Item;
use App\Models\Label;
use App\Models\User;
use App\Support\Imports\Trello\TrelloExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * BRAI-65: Trello board JSON → a new board. The fixture (tests/Fixtures/imports/trello/board.json)
 * is synthetic: 5 open lists (one archived), 7 cards (2 archived), checklists, labels (one unnamed),
 * 3 members (one with an email), comments, a custom field, attachments and a power-up.
 */
class TrelloImporterTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    private const ID = '65f0a1b2c3d4e5f6000000';

    private User $jane;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Storage::fake('local');
        $this->jane = $this->projectMember('editor');
        $this->jane->forceFill(['email' => 'Jane.Doe@example.test', 'name' => 'Jane Brainitive'])->save();
    }

    private function fixture(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/imports/trello/board.json')), true);
    }

    private function upload(array|string|null $content = null, ?User $as = null)
    {
        $content ??= $this->fixture();

        return $this->actingAs($as ?? $this->editor)->post(route('imports.store', $this->workspace->slug), [
            'source' => 'trello',
            'project_id' => $this->project->id,
            'file' => UploadedFile::fake()->createWithContent('trello-board.json', is_string($content) ? $content : json_encode($content)),
        ]);
    }

    private function start(ImportRun $run, array $options = [])
    {
        return $this->post(route('imports.start', [$this->workspace->slug, $run->uuid]), ['options' => $options + [
            'board_name' => 'Launch (from Trello)',
            'done_list' => self::ID.'l3',
            'include_archived' => false,
            'checklists' => 'description',
            'attachment_links' => false,
            'members' => [self::ID.'m1' => $this->jane->id, self::ID.'m2' => null],
        ]]);
    }

    private function importedBoard(): Board
    {
        return Board::where('project_id', $this->project->id)->where('id', '!=', $this->board->id)->sole();
    }

    private function issue(string $title): Item
    {
        return Item::where('title', $title)->sole();
    }

    public function test_preview_describes_the_board_and_writes_nothing(): void
    {
        $this->upload()->assertRedirect();
        $run = ImportRun::sole();
        $this->assertSame(ImportRun::PREVIEWED, $run->status, (string) $run->error);
        $preview = $run->preview;

        $this->assertSame(['name' => 'Launch plan 🚀', 'url' => 'https://trello.com/b/AbCdEf12/launch-plan'], $preview['board']);
        $this->assertSame(['Backlog 📋', 'Doing', 'Done ✅', 'Later'], array_column($preview['lists'], 'name'));
        $this->assertSame([2, 2, 1, 0], array_column($preview['lists'], 'cards'));
        $this->assertSame(1, $preview['archived_lists']);
        $this->assertSame(self::ID.'l3', $preview['done_list']); // "Done ✅", not the last list
        $this->assertSame(['total' => 7, 'open' => 5, 'archived' => 2], $preview['cards']);
        $this->assertSame(['Frontend', 'Bug', 'Purple', 'Unused', 'No colour'], array_column($preview['labels'], 'name'));
        $this->assertSame(TrelloExport::color('red_dark'), $preview['labels'][1]['color']);
        $this->assertSame(['checklists' => 3, 'items' => 4, 'cards' => 2], $preview['checklists']);
        $this->assertSame(['Comments' => 3, 'Custom fields' => 1, 'Attachments' => 2, 'Power-ups' => 1], $preview['unsupported']);

        // people: matched by email when the export has one, otherwise left for the user to map
        $members = collect($preview['members'])->keyBy('name');
        $this->assertSame($this->jane->id, $members['Jane Doe']['suggested_user_id']);
        $this->assertNull($members['Bob Builder']['suggested_user_id']);
        $this->assertSame('Zoë Ångström', $members['Zoë Ångström']['name']);
        $this->assertContains($this->jane->id, array_column($preview['project_members'], 'id'));
        $this->assertNotContains($this->outsider->id, array_column($preview['project_members'], 'id'));

        // dry run
        $this->assertSame(1, Board::count());
        $this->assertSame(0, Item::count());
        $this->assertSame(0, Label::count());
        $this->assertSame(0, ImportMapping::count());
        $this->get(route('imports.show', [$this->workspace->slug, $run->uuid]))->assertOk();
    }

    public function test_import_keeps_order_dates_labels_and_member_mapping(): void
    {
        Notification::fake();
        $this->upload();
        $run = ImportRun::sole();
        $this->start($run)->assertRedirect()->assertSessionHasNoErrors();

        $run->refresh();
        $this->assertSame(ImportRun::COMPLETED, $run->status, (string) $run->error);
        $board = $this->importedBoard();
        $this->assertSame('Launch (from Trello)', $board->name);
        $this->assertSame('kanban', $board->type);
        $this->assertSame(['project_id' => $this->project->id, 'board_id' => $board->id, 'url' => route('boards.show', [$this->workspace->slug, $this->project->slug, $board->slug])], $run->result);

        // lists → columns in order; the chosen list is the done column
        $columns = $board->columns()->get();
        $this->assertSame(['Backlog 📋', 'Doing', 'Done ✅', 'Later'], $columns->pluck('name')->all());
        $this->assertSame([false, false, true, false], $columns->pluck('is_done_column')->all());

        // cards → issues in list + card order, with new project keys
        $byColumn = fn (string $name) => Item::where('column_id', $columns->firstWhere('name', $name)->id)->orderBy('position')->pluck('title')->all();
        $this->assertSame(['Write the docs — Ünïcødé 文档 🚀', 'Set up the repository'], $byColumn('Backlog 📋'));
        $this->assertSame(['Implement the API', 'Empty fields card'], $byColumn('Doing'));
        $this->assertSame(['Ship v1'], $byColumn('Done ✅'));
        $this->assertSame(['BRAI-1', 'BRAI-2', 'BRAI-3', 'BRAI-4', 'BRAI-5'], Item::where('board_id', $board->id)->orderBy('id')->pluck('key')->all());
        $this->assertSame(0, Item::whereIn('title', ['Archived card', 'Card in an archived list'])->count());

        // dates (the importer's time zone: UTC here)
        $setup = $this->issue('Set up the repository');
        $this->assertSame('2024-03-03', $setup->due_date->toDateString());
        $this->assertStringStartsWith('12:00', (string) $setup->due_time);
        $docs = $this->issue('Write the docs — Ünïcødé 文档 🚀');
        $this->assertSame('2024-04-01', $docs->start_date->toDateString());
        $this->assertNull($this->issue('Empty fields card')->due_date);
        $this->assertNull($this->issue('Empty fields card')->description);
        $this->assertNotNull($this->issue('Ship v1')->completed_at);
        $this->assertNull($setup->completed_at);

        // labels: created once per name, only those the imported cards use
        $this->assertEqualsCanonicalizing(['Frontend', 'Bug', 'Purple'], Label::where('project_id', $this->project->id)->pluck('name')->all());
        $this->assertSame(['Frontend'], $setup->labels()->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['Bug', 'Purple'], $docs->labels()->pluck('name')->all());
        $this->assertSame(TrelloExport::color('green'), Label::where('name', 'Frontend')->value('color'));

        // members: mapped → assignee; unmapped ones are named in the description
        $this->assertSame($this->jane->id, $setup->assigned_to);
        $this->assertSame($this->jane->id, $docs->assigned_to);
        $this->assertStringContainsString('Also on this card in Trello: Bob Builder', $docs->description);
        $api = $this->issue('Implement the API');
        $this->assertNull($api->assigned_to);
        $this->assertStringContainsString('On this card in Trello: Zoë Ångström', $api->description);
        $this->assertSame($this->editor->id, $setup->reporter_id);

        // description: Markdown → sanitized HTML, the checklist as a check list
        $this->assertStringContainsString('<strong>protect</strong>', $setup->description);
        $this->assertStringContainsString('<a href="https://example.com/readme"', $setup->description);
        $this->assertStringContainsString('<a href="https://example.com/setup"', $setup->description);
        $this->assertStringNotContainsString('<script', $setup->description);
        $this->assertMatchesRegularExpression('#<li role="checkbox"[^>]*aria-checked="true"[^>]*>Install PHP &lt;8\.2&gt;</li><li role="checkbox"[^>]*aria-checked="false"[^>]*>Install Node</li>#', $setup->description);
        $this->assertStringContainsString('<ol><li>Intro</li><li>Setup</li></ol>', $docs->description);
        $this->assertStringNotContainsString('api-spec.pdf', $api->description); // attachment links weren't asked for

        // report
        $this->assertSame(['boards' => 1, 'columns' => 4, 'labels' => 3, 'issues' => 5], $run->report['created']);
        $this->assertSame(['Comments' => 3, 'Custom fields' => 1, 'Attachments' => 2, 'Power-ups' => 1], $run->report['unsupported']);
        $this->assertContains('2 archived card(s) were skipped.', $run->report['warnings']);

        // no per-record notifications (Jane was assigned twice)
        Notification::assertNothingSent();
    }

    public function test_archived_cards_subtasks_and_attachment_links_on_request(): void
    {
        Label::create(['project_id' => $this->project->id, 'name' => 'bug', 'color' => '#000000']);
        $this->upload();
        $run = ImportRun::sole();
        $this->start($run, ['include_archived' => true, 'checklists' => 'subtasks', 'attachment_links' => true, 'done_list' => self::ID.'l5'])->assertSessionHasNoErrors();
        $this->assertSame(ImportRun::COMPLETED, $run->fresh()->status, (string) $run->fresh()->error);

        $columns = $this->importedBoard()->columns()->get()->keyBy('name');
        $this->assertTrue($columns['Later']->is_done_column);
        $this->assertFalse($columns['Done ✅']->is_done_column);
        $this->assertSame($columns['Later']->id, $this->issue('Archived card')->column_id);
        $this->assertSame($columns['Later']->id, $this->issue('Card in an archived list')->column_id);

        // the existing "bug" label is reused (matched by name, case-insensitive)
        $this->assertSame(1, Label::where('project_id', $this->project->id)->whereRaw('lower(name) = ?', ['bug'])->count());
        $this->assertSame(['bug'], $this->issue('Implement the API')->labels()->pluck('name')->all());

        // checklists → subtasks; a done item goes to the done column
        $setup = $this->issue('Set up the repository');
        $this->assertStringNotContainsString('checkbox', (string) $setup->description);
        $subtasks = Item::where('parent_id', $setup->id)->orderBy('id')->get();
        $this->assertSame(['Install PHP <8.2>', 'Install Node'], $subtasks->pluck('title')->all());
        $this->assertSame([$columns['Later']->id, $setup->column_id], $subtasks->pluck('column_id')->all());
        $this->assertSame(['subtask', 'subtask'], $subtasks->map(fn (Item $i) => $i->itemType?->slug)->all());
        $api = $this->issue('Implement the API');
        $this->assertSame(['QA: Test the endpoints ✓', 'Release: Tag the release'], Item::where('parent_id', $api->id)->orderBy('id')->pluck('title')->all());

        // attachment links: http(s) only, never downloaded
        $this->assertStringContainsString('<a href="https://trello.com/1/cards/aaaa0003/attachments/f1/download/api-spec.pdf" rel="noopener noreferrer">api-spec.pdf</a>', $api->description);
        $this->assertStringNotContainsString('javascript:', $api->description);
        $this->assertSame(7 + 4, Item::where('project_id', $this->project->id)->count());
    }

    public function test_a_crashed_run_resumes_without_duplicates(): void
    {
        $this->upload();
        $run = ImportRun::sole();
        $created = 0;
        $crash = true;
        Item::creating(function () use (&$created, &$crash) {
            if ($crash && ++$created === 3) {
                throw new \RuntimeException('crash halfway');
            }
        });

        try {
            $this->start($run);
        } catch (\RuntimeException) {
            // the sync queue rethrows; the job marks the run failed
        }
        $this->assertSame(2, Item::count());

        $crash = false;
        $run->forceFill(['status' => ImportRun::FAILED])->save();
        $this->post(route('imports.start', [$this->workspace->slug, $run->uuid]))->assertRedirect();
        $this->assertSame(ImportRun::COMPLETED, $run->fresh()->status);

        $this->assertSame(2, Board::where('project_id', $this->project->id)->count());
        $this->assertSame(4, $this->importedBoard()->columns()->count());
        $this->assertSame(5, Item::count());
        $this->assertSame(3, Label::count());
        $this->assertSame(['BRAI-1', 'BRAI-2', 'BRAI-3', 'BRAI-4', 'BRAI-5'], Item::orderBy('id')->pluck('key')->all()); // the crashed key was rolled back
        $this->assertSame(1 + 4 + 3 + 5, ImportMapping::where('import_run_id', $run->id)->count());
    }

    public function test_malformed_or_oversized_exports_fail_in_the_preview(): void
    {
        $fixture = $this->fixture();
        $tooMany = $fixture;
        $tooMany['cards'] = array_map(fn ($i) => ['id' => 'c'.$i, 'name' => "Card $i", 'idList' => self::ID.'l1', 'pos' => $i], range(1, TrelloExport::MAX_CARDS + 1));
        $duplicate = $fixture;
        $duplicate['cards'][] = $fixture['cards'][0];
        $noList = $fixture;
        unset($noList['cards'][2]['idList']);
        $noOpenLists = $fixture;
        $noOpenLists['lists'] = array_map(fn ($l) => ['closed' => true] + $l, $fixture['lists']);

        $cases = [
            'not json {' => 'isn’t valid JSON',
            '[1, 2, 3]' => 'isn’t a Trello board export',
            json_encode(['name' => 'A Jira export?', 'issues' => []]) => 'isn’t a Trello board export',
            json_encode($tooMany) => 'more than 5,000 cards',
            json_encode($duplicate) => 'contains the card "Set up the repository" twice',
            json_encode($noList) => 'Card #3 in the export is malformed',
            json_encode($noOpenLists) => 'no open lists',
        ];
        foreach ($cases as $content => $message) {
            $this->upload($content);
            $run = ImportRun::latest('id')->first();
            $this->assertSame(ImportRun::FAILED, $run->status, $message);
            $this->assertStringContainsString($message, (string) $run->error);
            Storage::disk('local')->assertMissing('imports/'.$run->uuid.'/source.json');
        }

        // over the size limit: refused by the upload rules, before any run exists
        $runs = ImportRun::count();
        $this->actingAs($this->editor)->post(route('imports.store', $this->workspace->slug), [
            'source' => 'trello', 'project_id' => $this->project->id,
            'file' => UploadedFile::fake()->create('huge.json', 51 * 1024, 'application/json'),
        ])->assertSessionHasErrors('file');
        $this->actingAs($this->editor)->post(route('imports.store', $this->workspace->slug), [
            'source' => 'trello', 'project_id' => $this->project->id,
            'file' => UploadedFile::fake()->createWithContent('board.csv', 'a,b'),
        ])->assertSessionHasErrors('file');
        $this->assertSame($runs, ImportRun::count());

        $this->assertSame(1, Board::count());
        $this->assertSame(0, Item::count());
    }

    public function test_permissions_and_member_mapping_are_checked(): void
    {
        $this->upload(null, $this->viewer)->assertForbidden();
        $this->upload(null, $this->outsider)->assertForbidden();

        $this->upload();
        $run = ImportRun::sole();
        // only people who can open the project can be assignees; only lists from the preview
        $this->start($run, ['members' => [self::ID.'m1' => $this->outsider->id]])->assertSessionHasErrors('options.members.'.self::ID.'m1');
        $this->start($run, ['done_list' => 'not-a-list'])->assertSessionHasErrors('options.done_list');
        $this->start($run, ['checklists' => 'anything'])->assertSessionHasErrors('options.checklists');
        $this->assertSame(ImportRun::PREVIEWED, $run->fresh()->status);
        $this->assertSame(1, Board::count());
    }

    public function test_done_list_defaults_to_the_last_open_list_without_a_done_name(): void
    {
        $fixture = $this->fixture();
        $fixture['lists'] = array_values(array_filter($fixture['lists'], fn ($l) => $l['name'] !== 'Done ✅'));
        $this->assertSame(self::ID.'l5', TrelloExport::fromArray($fixture)->defaultDoneList());
    }
}
