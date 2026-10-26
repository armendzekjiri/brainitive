<?php

namespace App\Support\Automation;

use App\Models\Board;
use App\Models\Helpdesk;
use App\Models\ItemField;
use App\Models\ItemFieldOption;
use App\Models\ItemPriority;
use App\Models\ItemType;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The project's boards, helpdesks, columns, types, priorities, labels and people a rule may point at
 * (v1.40.0, BRAI-63): offered by the builder, enforced by AutomationDefinition, and used to word a
 * rule ("Assign to Max Member"). Everything is scoped to the one project; lookups are memoised.
 */
final class AutomationOptions
{
    /** @var array<string, Collection> */
    private array $cache = [];

    public function __construct(public readonly Project $project) {}

    /** @return Collection<int, array{id: int, name: string, columns: list<array{id: int, name: string}>}> */
    public function boards(): Collection
    {
        return $this->cache['boards'] ??= Board::where('project_id', $this->project->id)->orderBy('name')
            ->with('columns:id,board_id,name,position')->get(['id', 'name'])
            ->map(fn (Board $board) => [
                'id' => $board->id,
                'name' => $board->name,
                'columns' => $board->columns->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values()->all(),
            ])->values();
    }

    /** @return Collection<int, array{id: int, name: string, columns: list<array{id: int, name: string}>}> */
    public function helpdesks(): Collection
    {
        return $this->cache['helpdesks'] ??= Helpdesk::where('project_id', $this->project->id)->orderBy('name')
            ->with('columns:id,helpdesk_id,name,position')->get(['id', 'name'])
            ->map(fn (Helpdesk $helpdesk) => [
                'id' => $helpdesk->id,
                'name' => $helpdesk->name,
                'columns' => $helpdesk->columns->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values()->all(),
            ])->values();
    }

    public function itemTypes(): Collection
    {
        return $this->cache['itemTypes'] ??= ItemType::forProject($this->project)->orderBy('name')->get(['id', 'name'])
            ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values();
    }

    public function priorities(): Collection
    {
        return $this->cache['priorities'] ??= ItemPriority::forProject($this->project)->orderBy('order')->get(['id', 'name'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values();
    }

    public function labels(): Collection
    {
        return $this->cache['labels'] ??= $this->project->labels()->orderBy('name')->get(['id', 'name', 'color'])
            ->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color])->values();
    }

    /** People who can open the project: its members and the workspace's owners / admins. */
    public function members(): Collection
    {
        return $this->cache['members'] ??= $this->project->workspace->users()
            ->where(fn ($q) => $q->whereIn('workspace_user.role', ['owner', 'admin'])
                ->orWhereIn('users.id', $this->project->users()->select('users.id')))
            ->orderBy('name')->get(['users.id', 'users.name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values();
    }

    /**
     * Every custom field of the project, archived ones included (a condition may still point at one:
     * it then fails closed). Options include archived ones too, flagged.
     *
     * @return Collection<int, array{id: int, name: string, type: string, archived: bool, options: list<array{id: int, name: string, archived: bool}>}>
     */
    public function allCustomFields(): Collection
    {
        return $this->cache['customFields'] ??= ItemField::where('project_id', $this->project->id)
            ->with('options')->orderBy('position')->orderBy('id')->get()
            ->map(fn (ItemField $field) => [
                'id' => $field->id,
                'name' => $field->name,
                'type' => $field->type,
                'archived' => $field->isArchived(),
                'options' => $field->options->map(fn (ItemFieldOption $o) => [
                    'id' => $o->id, 'name' => $o->label, 'archived' => $o->archived_at !== null,
                ])->values()->all(),
            ])->values();
    }

    /** Active custom fields (the builder offers these), with their active options. */
    public function customFields(): Collection
    {
        return $this->allCustomFields()->where('archived', false)
            ->map(fn (array $field) => [
                'id' => $field['id'],
                'name' => $field['name'],
                'type' => $field['type'],
                'options' => array_values(array_map(
                    fn (array $o) => ['id' => $o['id'], 'name' => $o['name']],
                    array_filter($field['options'], fn (array $o) => ! $o['archived']),
                )),
            ])->values();
    }

    /** A custom field of this project by id (archived ones too), or null when it is gone. */
    public function customField(int $id): ?array
    {
        return $this->allCustomFields()->firstWhere('id', $id);
    }

    /** @return array<string, mixed> props for the builder */
    public function forFrontend(): array
    {
        return [
            'boards' => $this->boards()->all(),
            'helpdesks' => $this->helpdesks()->all(),
            'itemTypes' => $this->itemTypes()->all(),
            'priorities' => $this->priorities()->all(),
            'labels' => $this->labels()->all(),
            'members' => $this->members()->all(),
            'customFields' => $this->customFields()->all(),
        ];
    }

    /** Is $value a valid choice of this kind (scoped to the project and the rule's subject)? */
    public function valid(string $kind, mixed $value, string $subject): bool
    {
        return match ($kind) {
            'assignee' => $value === 'reporter' ? $subject === AutomationCatalog::SUBJECT_ITEM : $this->valid('member', $value, $subject),
            'recipient' => $value === 'assignee' || $this->valid('member', $value, $subject),
            'days' => is_numeric($value) && (int) $value == $value && (int) $value >= 0 && (int) $value <= 365,
            'text' => is_string($value) && trim($value) !== '' && mb_strlen($value) <= 200,
            default => is_numeric($value) && $this->name($kind, $value, $subject) !== null,
        };
    }

    /** Display name of an id of this kind, or null when it isn't one of the project's. */
    public function name(string $kind, mixed $value, string $subject): ?string
    {
        if (! is_numeric($value)) {
            return match (true) {
                $kind === 'assignee' && $value === 'reporter' => 'the reporter',
                $kind === 'recipient' && $value === 'assignee' => 'the assignee',
                $kind === 'text' && is_string($value) => '"'.$value.'"',
                default => null,
            };
        }
        $id = (int) $value;
        $find = fn (Collection $rows) => $rows->firstWhere('id', $id)['name'] ?? null;

        return match ($kind) {
            'board' => $find($this->boards()),
            'helpdesk' => $find($this->helpdesks()),
            'column' => $this->columnName($id, $subject),
            'item_type' => $find($this->itemTypes()),
            'priority' => $find($this->priorities()),
            'label' => $find($this->labels()),
            'member', 'assignee', 'recipient' => $find($this->members()),
            'days' => $id === 0 ? 'today' : "today + {$id} ".($id === 1 ? 'day' : 'days'),
            default => null,
        };
    }

    /** "Main Board → Done" (issues) or "Support → Waiting" (tickets). */
    private function columnName(int $id, string $subject): ?string
    {
        $containers = $subject === AutomationCatalog::SUBJECT_TICKET ? $this->helpdesks() : $this->boards();
        foreach ($containers as $container) {
            foreach ($container['columns'] as $column) {
                if ($column['id'] === $id) {
                    return $containers->count() > 1 ? "{$container['name']} → {$column['name']}" : $column['name'];
                }
            }
        }

        return null;
    }

    /** Board id of a board column of this project (null if it isn't one). */
    public function boardOfColumn(int $columnId): ?int
    {
        foreach ($this->boards() as $board) {
            foreach ($board['columns'] as $column) {
                if ($column['id'] === $columnId) {
                    return $board['id'];
                }
            }
        }

        return null;
    }
}
