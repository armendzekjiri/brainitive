<?php

namespace Tests\Feature\Boards;

use App\Models\Board;
use App\Models\Item;
use App\Models\ItemField;
use App\Models\ItemFieldValue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ItemFieldsTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Notification::fake();
        $this->admin = $this->projectMember('admin');
    }

    private function field(string $type, array $attributes = []): ItemField
    {
        $field = $this->project->itemFields()->create($attributes + ['name' => ucfirst($type).' field', 'type' => $type]);
        if ($type === 'select') {
            $field->options()->create(['label' => 'Gold', 'position' => 1]);
            $field->options()->create(['label' => 'Silver', 'position' => 2]);
        }

        return $field->load('options');
    }

    private function setValues(Item $item, array $values, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->editor)
            ->from(route('boards.show', [$this->workspace->slug, $this->project->slug, $this->board->slug]))
            ->put($this->itemRoute('boards.items.fields.update', $item), ['values' => $values]);
    }

    private function storedValue(Item $item, ItemField $field): ?ItemFieldValue
    {
        return ItemFieldValue::where('item_id', $item->id)->where('item_field_id', $field->id)->first();
    }

    private function fieldRoute(string $name, ?ItemField $field = null): string
    {
        return route($name, array_filter([$this->workspace->slug, $this->project->slug, $field?->id]));
    }

    // ── Values per type ──────────────────────────────────────────────────────

    public function test_each_type_is_stored_in_its_typed_column(): void
    {
        $item = $this->item();
        $text = $this->field('text');
        $number = $this->field('number');
        $date = $this->field('date');
        $checkbox = $this->field('checkbox');
        $select = $this->field('select');
        $gold = $select->options->first();

        $this->setValues($item, [
            $text->id => '  ACME Corp  ',
            $number->id => '12.5',
            $date->id => '2026-12-01',
            $checkbox->id => true,
            $select->id => $gold->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame('ACME Corp', $this->storedValue($item, $text)->value_text);
        $this->assertSame(12.5, $this->storedValue($item, $number)->value_number);
        $this->assertSame('2026-12-01', $this->storedValue($item, $date)->value_date->toDateString());
        $this->assertTrue($this->storedValue($item, $checkbox)->value_bool);
        $this->assertSame($gold->id, $this->storedValue($item, $select)->option_id);
        $this->assertNull($this->storedValue($item, $number)->value_text);
    }

    public function test_zero_and_false_are_values_while_null_clears(): void
    {
        $item = $this->item();
        $number = $this->field('number');
        $checkbox = $this->field('checkbox');

        $this->setValues($item, [$number->id => 0, $checkbox->id => false])->assertSessionHasNoErrors();

        $this->assertSame(0.0, $this->storedValue($item, $number)->value_number);
        $this->assertFalse($this->storedValue($item, $checkbox)->value_bool);

        $this->setValues($item, [$number->id => null, $checkbox->id => null])->assertSessionHasNoErrors();

        $this->assertNull($this->storedValue($item, $number));
        $this->assertNull($this->storedValue($item, $checkbox));
    }

    public function test_empty_text_clears_the_value(): void
    {
        $item = $this->item();
        $text = $this->field('text');
        $this->setValues($item, [$text->id => 'Something']);

        $this->setValues($item, [$text->id => ''])->assertSessionHasNoErrors();

        $this->assertNull($this->storedValue($item, $text));
    }

    public function test_invalid_values_are_rejected_and_nothing_is_written(): void
    {
        $item = $this->item();
        $text = $this->field('text');
        $number = $this->field('number');
        $date = $this->field('date');
        $checkbox = $this->field('checkbox');
        $select = $this->field('select');
        $otherSelect = $this->field('select', ['name' => 'Other select']);

        $cases = [
            [$number, 'twelve'],
            [$number, true],
            [$number, '1e20'],
            [$date, '2026-02-30'],
            [$date, '01.12.2026'],
            [$checkbox, 'maybe'],
            [$select, $otherSelect->options->first()->id],
            [$select, 'Gold'],
            [$text, str_repeat('a', 1001)],
        ];

        foreach ($cases as [$field, $value]) {
            $this->setValues($item, [$text->id => 'valid', $field->id => $value])
                ->assertSessionHasErrors("values.{$field->id}");
        }

        $this->assertSame(0, ItemFieldValue::count());
    }

    // ── Scoping & permissions ────────────────────────────────────────────────

    public function test_fields_of_other_projects_archived_fields_and_other_boards_are_rejected(): void
    {
        $item = $this->item();
        $otherProject = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->editor->id]);
        $foreign = $otherProject->itemFields()->create(['name' => 'Foreign', 'type' => 'text']);
        $archived = $this->field('text', ['name' => 'Old', 'archived_at' => now()]);
        $otherBoard = Board::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $elsewhere = $this->field('text', ['name' => 'Elsewhere', 'board_ids' => [$otherBoard->id]]);

        foreach ([$foreign, $archived, $elsewhere] as $field) {
            $this->setValues($item, [$field->id => 'x'])->assertSessionHasErrors("values.{$field->id}");
        }
        $this->setValues($item, ['abc' => 'x'])->assertSessionHasErrors('values.abc');

        $this->assertSame(0, ItemFieldValue::count());
    }

    public function test_viewers_and_outsiders_cannot_write_values(): void
    {
        $item = $this->item();
        $text = $this->field('text');

        $this->setValues($item, [$text->id => 'x'], $this->viewer)->assertForbidden();
        $this->setValues($item, [$text->id => 'x'], $this->outsider)->assertForbidden();

        $this->assertSame(0, ItemFieldValue::count());
    }

    public function test_viewers_read_fields_and_values_on_the_board_and_item_page(): void
    {
        $item = $this->item();
        $number = $this->field('number');
        $this->setValues($item, [$number->id => 0]);

        $this->actingAs($this->viewer)
            ->get(route('boards.show', [$this->workspace->slug, $this->project->slug, $this->board->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('customFields.0.id', $number->id)
                ->where('customFields.0.type', 'number')
                ->where('board.columns.0.items.0.field_values.0.item_field_id', $number->id)
                ->where('board.columns.0.items.0.field_values.0.value_number', 0)
                ->where('canManageFields', false)
                ->where('canEditItems', false));

        $this->actingAs($this->viewer)
            ->get($this->itemRoute('boards.items.show', $item))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('customFields.0.id', $number->id)
                ->where('item.field_values.0.value_number', 0));
    }

    public function test_board_only_lists_active_fields_that_apply_to_it(): void
    {
        $otherBoard = Board::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $everywhere = $this->field('text', ['name' => 'Everywhere']);
        $here = $this->field('text', ['name' => 'Here', 'board_ids' => [$this->board->id]]);
        $this->field('text', ['name' => 'There', 'board_ids' => [$otherBoard->id]]);
        $this->field('text', ['name' => 'Archived', 'archived_at' => now()]);

        $this->actingAs($this->viewer)
            ->get(route('boards.show', [$this->workspace->slug, $this->project->slug, $this->board->slug]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('customFields', 2)
                ->where('customFields.0.id', $everywhere->id)
                ->where('customFields.1.id', $here->id));
    }

    // ── Field definitions ────────────────────────────────────────────────────

    public function test_project_admin_creates_a_select_field_with_options(): void
    {
        $this->actingAs($this->admin)
            ->post($this->fieldRoute('projects.fields.store'), [
                'name' => 'Customer tier',
                'type' => 'select',
                'board_ids' => [$this->board->id],
                'options' => [['label' => 'Gold'], ['label' => 'Silver', 'color' => '#c0c0c0']],
            ])
            ->assertSessionHasNoErrors();

        $field = ItemField::firstWhere('name', 'Customer tier');
        $this->assertSame([$this->board->id], $field->board_ids);
        $this->assertSame(['Gold', 'Silver'], $field->options->pluck('label')->all());
    }

    public function test_editors_cannot_manage_fields(): void
    {
        $field = $this->field('text');

        $this->actingAs($this->editor)->post($this->fieldRoute('projects.fields.store'), ['name' => 'X', 'type' => 'text'])->assertForbidden();
        $this->actingAs($this->editor)->put($this->fieldRoute('projects.fields.update', $field), ['name' => 'Y'])->assertForbidden();
        $this->actingAs($this->editor)->delete($this->fieldRoute('projects.fields.destroy', $field))->assertForbidden();
    }

    public function test_field_validation_rejects_bad_definitions(): void
    {
        $otherProject = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->editor->id]);
        $foreignBoard = Board::factory()->create(['project_id' => $otherProject->id, 'created_by' => $this->editor->id]);
        $this->field('text', ['name' => 'Customer']);

        $this->actingAs($this->admin)->post($this->fieldRoute('projects.fields.store'), ['name' => 'Formula', 'type' => 'formula'])->assertSessionHasErrors('type');
        $this->actingAs($this->admin)->post($this->fieldRoute('projects.fields.store'), ['name' => 'customer', 'type' => 'text'])->assertSessionHasErrors('name');
        $this->actingAs($this->admin)->post($this->fieldRoute('projects.fields.store'), ['name' => 'B', 'type' => 'text', 'board_ids' => [$foreignBoard->id]])->assertSessionHasErrors('board_ids.0');
        $this->actingAs($this->admin)->post($this->fieldRoute('projects.fields.store'), ['name' => 'S', 'type' => 'select', 'options' => [['label' => 'A'], ['label' => 'a']]])->assertSessionHasErrors('options.1.label');
    }

    public function test_fields_from_another_project_cannot_be_edited_through_this_project(): void
    {
        $otherProject = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->editor->id]);
        $foreign = $otherProject->itemFields()->create(['name' => 'Foreign', 'type' => 'text']);

        $this->actingAs($this->admin)->put($this->fieldRoute('projects.fields.update', $foreign), ['name' => 'Mine'])->assertNotFound();
        $this->assertSame('Foreign', $foreign->fresh()->name);
    }

    public function test_renaming_an_option_keeps_values_and_removing_it_archives(): void
    {
        $item = $this->item();
        $select = $this->field('select');
        [$gold, $silver] = $select->options->all();
        $this->setValues($item, [$select->id => $gold->id]);

        $this->actingAs($this->admin)->put($this->fieldRoute('projects.fields.update', $select), [
            'name' => $select->name,
            'options' => [['id' => $gold->id, 'label' => 'Platinum']],
        ])->assertSessionHasNoErrors();

        $this->assertSame('Platinum', $gold->fresh()->label);
        $this->assertSame($gold->id, $this->storedValue($item, $select)->option_id);
        $this->assertNotNull($silver->fresh()->archived_at, 'Removed options are archived, not deleted.');

        // The archived option can't be newly chosen…
        $other = $this->item();
        $this->setValues($other, [$select->id => $silver->id])->assertSessionHasErrors("values.{$select->id}");

        // …but an issue that already has an archived option keeps it.
        $this->actingAs($this->admin)->put($this->fieldRoute('projects.fields.update', $select), [
            'name' => $select->name,
            'options' => [['id' => $silver->id, 'label' => 'Silver']],
        ]);
        $this->assertNotNull($gold->fresh()->archived_at);
        $this->setValues($item, [$select->id => $gold->id])->assertSessionHasNoErrors();
        $this->assertSame($gold->id, $this->storedValue($item, $select)->option_id);
    }

    public function test_options_of_another_field_cannot_be_hijacked(): void
    {
        $select = $this->field('select');
        $other = $this->field('select', ['name' => 'Other']);

        $this->actingAs($this->admin)->put($this->fieldRoute('projects.fields.update', $select), [
            'name' => $select->name,
            'options' => [['id' => $other->options->first()->id, 'label' => 'Mine now']],
        ])->assertSessionHasErrors('options.0.id');

        $this->assertSame('Gold', $other->options->first()->fresh()->label);
    }

    public function test_type_change_is_blocked_once_a_field_has_values(): void
    {
        $unused = $this->field('text', ['name' => 'Unused']);
        $this->actingAs($this->admin)->put($this->fieldRoute('projects.fields.update', $unused), ['name' => 'Unused', 'type' => 'number'])
            ->assertSessionHasNoErrors();
        $this->assertSame('number', $unused->fresh()->type);

        $used = $this->field('text', ['name' => 'Used']);
        $this->setValues($this->item(), [$used->id => 'value']);
        $this->actingAs($this->admin)->put($this->fieldRoute('projects.fields.update', $used), ['name' => 'Used', 'type' => 'number'])
            ->assertSessionHasErrors('type');
        $this->assertSame('text', $used->fresh()->type);
    }

    public function test_archiving_keeps_values_and_delete_only_works_for_unused_fields(): void
    {
        $item = $this->item();
        $used = $this->field('text', ['name' => 'Used']);
        $this->setValues($item, [$used->id => 'kept']);

        $this->actingAs($this->admin)->delete($this->fieldRoute('projects.fields.destroy', $used))->assertSessionHasErrors('field');
        $this->actingAs($this->admin)->put($this->fieldRoute('projects.fields.update', $used), ['name' => 'Used', 'archived' => true])->assertSessionHasNoErrors();

        $this->assertTrue($used->fresh()->isArchived());
        $this->assertSame('kept', $this->storedValue($item, $used)->value_text);

        $unused = $this->field('text', ['name' => 'Typo']);
        $this->actingAs($this->admin)->delete($this->fieldRoute('projects.fields.destroy', $unused))->assertSessionHasNoErrors();
        $this->assertNull($unused->fresh());
    }

    public function test_reorder_only_accepts_fields_of_the_project(): void
    {
        $a = $this->field('text', ['name' => 'A']);
        $b = $this->field('text', ['name' => 'B']);
        $otherProject = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->editor->id]);
        $foreign = $otherProject->itemFields()->create(['name' => 'Foreign', 'type' => 'text']);

        $this->actingAs($this->admin)->post($this->fieldRoute('projects.fields.reorder'), ['ids' => [$b->id, $foreign->id]])->assertSessionHasErrors('ids.1');
        $this->actingAs($this->admin)->post($this->fieldRoute('projects.fields.reorder'), ['ids' => [$b->id, $a->id]])->assertSessionHasNoErrors();

        $this->assertSame([$b->id, $a->id], $this->project->itemFields()->orderBy('position')->pluck('id')->all());
    }

    public function test_editors_cannot_reorder_fields_and_colors_must_be_hex(): void
    {
        $a = $this->field('text', ['name' => 'A']);

        $this->actingAs($this->editor)->post($this->fieldRoute('projects.fields.reorder'), ['ids' => [$a->id]])->assertForbidden();
        $this->actingAs($this->admin)->post($this->fieldRoute('projects.fields.store'), [
            'name' => 'Tier', 'type' => 'select', 'options' => [['label' => 'Gold', 'color' => 'red;background:url(x)']],
        ])->assertSessionHasErrors('options.0.color');
    }

    public function test_updating_without_board_ids_keeps_the_boards(): void
    {
        $field = $this->field('text', ['name' => 'Scoped', 'board_ids' => [$this->board->id]]);

        $this->actingAs($this->admin)->put($this->fieldRoute('projects.fields.update', $field), ['name' => 'Renamed'])->assertSessionHasNoErrors();

        $this->assertSame([$this->board->id], $field->fresh()->board_ids);
    }

    public function test_moving_an_issue_to_another_project_drops_that_projects_values_only(): void
    {
        $parent = $this->item();
        $child = $this->item(['parent_id' => $parent->id]);
        $text = $this->field('text', ['name' => 'Customer']);
        $this->setValues($parent, [$text->id => 'ACME']);
        $this->setValues($child, [$text->id => 'Child value']);

        $otherProject = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->editor->id]);
        $otherProject->users()->attach($this->editor->id, ['role' => 'editor']);
        $otherBoard = Board::factory()->create(['project_id' => $otherProject->id, 'created_by' => $this->editor->id]);

        $this->actingAs($this->editor)
            ->patch($this->itemRoute('boards.items.move-to-board', $parent), ['target_board_id' => $otherBoard->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, ItemFieldValue::count());
        $this->assertSame(0, $text->values()->count(), 'The old project can delete or retype its field again.');
        $removed = $parent->activities()->where('action', 'field_changed')->latest('id')->first();
        $this->assertSame(['from' => 'ACME', 'to' => null], $removed->changes['value']);

        // Moving within the project keeps values
        $stay = $this->item();
        $this->setValues($stay, [$text->id => 'kept']);
        $sameProjectBoard = Board::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $this->actingAs($this->editor)->patch($this->itemRoute('boards.items.move-to-board', $stay), ['target_board_id' => $sameProjectBoard->id]);
        $this->assertSame('kept', $this->storedValue($stay, $text)->value_text);
    }

    // ── History, duplicate, performance ──────────────────────────────────────

    public function test_changes_are_recorded_in_history_with_display_values(): void
    {
        $item = $this->item();
        $select = $this->field('select', ['name' => 'Tier']);
        $checkbox = $this->field('checkbox', ['name' => 'Needs QA']);
        $gold = $select->options->first();

        $this->setValues($item, [$select->id => $gold->id, $checkbox->id => false]);
        $this->setValues($item, [$select->id => $gold->id]); // unchanged: no entry

        $entries = $item->activities()->where('action', 'field_changed')->get();
        $this->assertCount(2, $entries);
        $tier = $entries->firstWhere('metadata.field', 'Tier');
        $this->assertSame(['from' => null, 'to' => 'Gold'], $tier->changes['value']);
        $this->assertSame(['from' => null, 'to' => 'No'], $entries->firstWhere('metadata.field', 'Needs QA')->changes['value']);

        // Renaming the option later doesn't rewrite history
        $gold->update(['label' => 'Platinum']);
        $this->assertSame('Gold', $tier->fresh()->changes['value']['to']);
    }

    public function test_duplicating_an_issue_copies_its_values(): void
    {
        $item = $this->item(['column_id' => $this->column('to-do')]);
        $date = $this->field('date');
        $this->setValues($item, [$date->id => '2026-11-30']);

        $this->actingAs($this->editor)->post($this->itemRoute('boards.items.duplicate', $item))->assertSessionHasNoErrors();

        $copy = Item::where('id', '!=', $item->id)->latest('id')->first();
        $this->assertSame('2026-11-30', $this->storedValue($copy, $date)->value_date->toDateString());
    }

    public function test_board_query_count_does_not_grow_with_items(): void
    {
        $fields = collect(['text', 'number', 'select'])->map(fn ($type) => $this->field($type));
        $makeItems = function (int $count) use ($fields) {
            for ($i = 0; $i < $count; $i++) {
                $item = $this->item(['column_id' => $this->column('to-do')]);
                foreach ($fields as $field) {
                    ItemFieldValue::create([
                        'item_id' => $item->id,
                        'item_field_id' => $field->id,
                        ItemFieldValue::COLUMNS[$field->type] => match ($field->type) {
                            'text' => "Value {$i}",
                            'number' => $i,
                            'select' => $field->options->first()->id,
                        },
                    ]);
                }
            }
        };
        $countQueries = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->viewer)->get(route('boards.show', [$this->workspace->slug, $this->project->slug, $this->board->slug]))->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $makeItems(3);
        $countQueries(); // the first request also fills settings / permission caches
        $few = $countQueries();
        $makeItems(20);

        $this->assertSame($few, $countQueries());
    }
}
