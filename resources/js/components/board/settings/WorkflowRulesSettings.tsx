import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { STATUS_CATEGORY_CLASSES, statusCategory } from '@/components/board/ItemPrimitives';
import { cn } from '@/lib/utils';
import { describeRule, EMPTY_RULE, isEmptyRule, ruleKey } from '@/lib/workflowRules';
import type { Board, BoardColumn, PageProps, Project, Workspace, WorkflowRuleMember, WorkflowRuleRole, WorkflowRuleSettings } from '@/types';
import { router } from '@inertiajs/react';
import { Check, ChevronDown, GitBranch, Lock, Plus, ShieldCheck, Undo2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

interface Props {
    workspace: Workspace;
    project: Project;
    board: Board & { columns: BoardColumn[] };
    rules: Record<number, WorkflowRuleSettings>;
    members: WorkflowRuleMember[];
    roles: WorkflowRuleRole[];
}

interface PickerOption {
    value: string;
    label: string;
    hint?: string;
    group?: string;
}

/** A sentence slot: "[Testing, Review ▾]" — a compact multi-select inside the rule sentence. */
function SlotPicker({ label, placeholder, selected, options, onChange }: {
    label: string;
    placeholder: string;
    selected: string[];
    options: PickerOption[];
    onChange: (values: string[]) => void;
}) {
    const chosen = options.filter((o) => selected.includes(o.value));
    const toggle = (value: string) => onChange(selected.includes(value) ? selected.filter((v) => v !== value) : [...selected, value]);
    const groups = [...new Set(options.map((o) => o.group ?? ''))];

    return (
        <Popover>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    aria-label={label}
                    className={cn(
                        'inline-flex max-w-full items-center gap-1 rounded-md border px-2 py-0.5 align-baseline text-sm transition-colors',
                        chosen.length
                            ? 'border-primary/40 bg-primary/10 font-medium text-foreground'
                            : 'border-dashed border-muted-foreground/40 text-muted-foreground hover:text-foreground',
                    )}
                >
                    <span className="truncate">{chosen.length ? chosen.map((o) => o.label).join(', ') : placeholder}</span>
                    <ChevronDown className="size-3.5 shrink-0" />
                </button>
            </PopoverTrigger>
            <PopoverContent className="w-72 p-1" align="start" role="menu" aria-label={label}>
                {options.length === 0 && <p className="p-2 text-xs text-muted-foreground">Nothing to choose here.</p>}
                <div className="max-h-72 overflow-y-auto">
                    {groups.map((group) => (
                        <div key={group || 'all'}>
                            {group && <div className="px-2 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{group}</div>}
                            {options.filter((o) => (o.group ?? '') === group).map((option) => {
                                const on = selected.includes(option.value);
                                return (
                                    <button
                                        key={option.value}
                                        type="button"
                                        role="menuitemcheckbox"
                                        aria-checked={on}
                                        onClick={() => toggle(option.value)}
                                        className="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm hover:bg-secondary"
                                    >
                                        <span className={cn('grid size-4 shrink-0 place-items-center rounded border', on ? 'border-primary bg-primary text-primary-foreground' : 'border-input')}>
                                            {on && <Check className="size-3" />}
                                        </span>
                                        <span className="min-w-0 flex-1 truncate">{option.label}</span>
                                        {option.hint && <span className="shrink-0 text-[11px] text-muted-foreground">{option.hint}</span>}
                                    </button>
                                );
                            })}
                        </div>
                    ))}
                </div>
                {selected.length > 0 && (
                    <div className="border-t p-1">
                        <button type="button" onClick={() => onChange([])} className="w-full rounded px-2 py-1.5 text-left text-xs text-muted-foreground hover:bg-secondary hover:text-foreground">
                            Clear
                        </button>
                    </div>
                )}
            </PopoverContent>
        </Popover>
    );
}

/** "[✓ an assignee]" — a condition the card must meet, toggled in the sentence. */
function ConditionToggle({ on, label, onChange }: { on: boolean; label: string; onChange: (on: boolean) => void }) {
    return (
        <button
            type="button"
            aria-pressed={on}
            onClick={() => onChange(!on)}
            className={cn(
                'inline-flex items-center gap-1 rounded-md border px-2 py-0.5 align-baseline text-sm transition-colors',
                on ? 'border-primary/40 bg-primary/10 font-medium text-foreground' : 'border-dashed border-muted-foreground/40 text-muted-foreground hover:text-foreground',
            )}
        >
            {on && <Check className="size-3.5" />}
            {label}
        </button>
    );
}

function StatusChip({ column, columns }: { column: BoardColumn; columns: BoardColumn[] }) {
    return (
        <span className={cn('inline-flex h-5 items-center rounded-[3px] border px-1.5 text-[11px] font-bold uppercase tracking-[0.02em]', STATUS_CATEGORY_CLASSES[statusCategory(column, columns)])}>
            {column.name}
        </span>
    );
}

/** One column's rule as an editable sentence, saved on its own. */
function RuleEditor({ workspace, project, board, column, saved, members, roles, memberName }: {
    workspace: Workspace;
    project: Project;
    board: Board & { columns: BoardColumn[] };
    column: BoardColumn;
    saved: WorkflowRuleSettings;
    members: WorkflowRuleMember[];
    roles: WorkflowRuleRole[];
    memberName: (id: number) => string;
}) {
    const [draft, setDraft] = useState<WorkflowRuleSettings>(saved);
    const [saving, setSaving] = useState(false);
    const dirty = ruleKey(draft) !== ruleKey(saved);
    const hasRule = !isEmptyRule(saved);
    // columns without a rule start folded; a link from Columns (#rule-<id>) opens its editor
    const [open, setOpen] = useState(() => hasRule || (typeof window !== 'undefined' && window.location.hash === `#rule-${column.id}`));
    const columns = board.columns;
    const preview = describeRule(draft, column, columns, memberName);
    const set = (changes: Partial<WorkflowRuleSettings>) => setDraft((d) => ({ ...d, ...changes }));

    const fromOptions: PickerOption[] = columns.filter((c) => c.id !== column.id).map((c) => ({ value: String(c.id), label: c.name }));
    const whoOptions: PickerOption[] = [
        ...roles.map((r) => ({ value: `role:${r.key}`, label: r.label, group: 'Roles' })),
        ...members
            .filter((m) => m.can_move || draft.allowed_user_ids.includes(m.id))
            .map((m) => ({ value: `user:${m.id}`, label: m.name, hint: m.role, group: 'People' })),
    ];
    const whoSelected = [...draft.allowed_roles.map((r) => `role:${r}`), ...draft.allowed_user_ids.map((id) => `user:${id}`)];
    const formerListed = members.filter((m) => !m.can_move && draft.allowed_user_ids.includes(m.id));

    const send = (rule: WorkflowRuleSettings) => {
        setSaving(true);
        router.put(route('boards.columns.rule.update', [workspace.slug, project.slug, board.slug, column.id]), { ...rule }, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => toast.success((page.props as PageProps).flash?.success ?? 'Saved.'),
            onError: (errors) => toast.error(Object.values(errors)[0] ?? 'Could not save the rule.'),
            onFinish: () => setSaving(false),
        });
    };

    if (!open) {
        return (
            <Card id={`rule-${column.id}`} className="scroll-mt-6" aria-label={`Rule for ${column.name}`} role="group">
                <CardContent className="flex flex-wrap items-center gap-x-3 gap-y-2 p-3 sm:px-5">
                    <StatusChip column={column} columns={columns} />
                    <span className="text-xs text-muted-foreground">No rule — any editor can move cards here</span>
                    <Button size="sm" variant="outline" className="ml-auto gap-1" onClick={() => setOpen(true)} aria-label={`Add a rule for ${column.name}`}>
                        <Plus className="size-3.5" />Add rule
                    </Button>
                </CardContent>
            </Card>
        );
    }

    return (
        <Card id={`rule-${column.id}`} className={cn('scroll-mt-6', hasRule && 'border-primary/30')} aria-label={`Rule for ${column.name}`} role="group">
            <CardContent className="space-y-4 p-4 sm:p-5">
                <div className="flex flex-wrap items-center gap-2">
                    <StatusChip column={column} columns={columns} />
                    {hasRule
                        ? <Badge variant="secondary" className="gap-1"><Lock className="size-3" />Rule active</Badge>
                        : <span className="text-xs text-muted-foreground">No rule — any editor can move cards here</span>}
                    {dirty && <span className="ml-auto text-xs text-amber-700 dark:text-amber-400">Unsaved changes</span>}
                </div>

                <div className="space-y-3 text-sm leading-8">
                    <p>
                        Cards can enter <strong>{column.name}</strong> from{' '}
                        <SlotPicker
                            label={`Allowed from for ${column.name}`}
                            placeholder="any column"
                            selected={draft.allowed_from_column_ids.map(String)}
                            options={fromOptions}
                            onChange={(values) => set({ allowed_from_column_ids: values.map(Number) })}
                        />
                    </p>
                    <p>
                        Moved here by{' '}
                        <SlotPicker
                            label={`Who may move cards to ${column.name}`}
                            placeholder="anyone who can edit cards"
                            selected={whoSelected}
                            options={whoOptions}
                            onChange={(values) => set({
                                allowed_roles: values.filter((v) => v.startsWith('role:')).map((v) => v.slice(5)),
                                allowed_user_ids: values.filter((v) => v.startsWith('user:')).map((v) => Number(v.slice(5))),
                            })}
                        />
                    </p>
                    <p className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                        <span>Only when the card has</span>
                        <ConditionToggle on={draft.require_assignee} label="an assignee" onChange={(on) => set({ require_assignee: on })} />
                        <ConditionToggle on={draft.require_subtasks_done} label="all subtasks done" onChange={(on) => set({ require_subtasks_done: on })} />
                        <ConditionToggle on={draft.require_due_date} label="a due date" onChange={(on) => set({ require_due_date: on })} />
                    </p>
                </div>

                {formerListed.length > 0 && (
                    <p className="text-xs text-amber-700 dark:text-amber-400">
                        {formerListed.map((m) => m.name).join(', ')} can no longer edit cards in this project — they can't move cards here until they can again.
                    </p>
                )}

                <div className="rounded-md bg-muted/50 px-3 py-2 text-xs text-muted-foreground" aria-live="polite">
                    {preview.length === 0
                        ? 'No restrictions: cards can enter this column from anywhere, moved by anyone who can edit cards.'
                        : (
                            <ul className="space-y-0.5">
                                {preview.map((line) => <li key={line}>{line}</li>)}
                                {draft.allowed_from_column_ids.length > 0 && <li>New cards can't be created directly in {column.name}.</li>}
                            </ul>
                        )}
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <Button size="sm" onClick={() => send(draft)} disabled={!dirty || saving}>
                        {saving ? 'Saving…' : `Save rule for ${column.name}`}
                    </Button>
                    {(dirty || !hasRule) && (
                        <Button
                            size="sm"
                            variant="ghost"
                            className="gap-1"
                            onClick={() => { setDraft(saved); if (!hasRule) setOpen(false); }}
                            disabled={saving}
                        >
                            <Undo2 className="size-3.5" />{hasRule ? 'Discard' : 'Cancel'}
                        </Button>
                    )}
                    {hasRule && !dirty && (
                        <Button size="sm" variant="ghost" className="ml-auto text-muted-foreground" onClick={() => send(EMPTY_RULE)} disabled={saving}>
                            Remove rule
                        </Button>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

/**
 * Board settings → Workflow rules (v1.52.0): per column, who may move cards in, from which columns and what a card
 * needs first — written as a sentence. The server enforces them everywhere (WorkflowGuard); nobody bypasses them.
 */
export default function WorkflowRulesSettings({ workspace, project, board, rules, members, roles }: Props) {
    const columns = board.columns;
    const names = new Map(members.map((m) => [m.id, m.name]));
    const memberName = (id: number) => names.get(id) ?? 'a former member';
    const active = columns.filter((c) => rules[c.id] && !isEmptyRule(rules[c.id]));

    return (
        <div className="space-y-6">
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2"><GitBranch className="size-5" /> Workflow rules</CardTitle>
                    <CardDescription>
                        Decide who may move cards into a column, from which columns, and what a card needs first. Rules apply everywhere cards
                        move or are created — the board, the issue dialog, bulk edits, the apps, AI assistants and automations. Nobody bypasses
                        them, admins included.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Rules on this board</h3>
                    {columns.length === 0 ? (
                        <p className="text-sm text-muted-foreground">Add columns first — rules belong to a column.</p>
                    ) : active.length === 0 ? (
                        <div className="flex items-start gap-3 rounded-md border border-dashed p-4 text-sm text-muted-foreground">
                            <ShieldCheck className="mt-0.5 size-5 shrink-0" />
                            <p>No rules yet: every editor can move any card to any column. Pick a column below to add one — for example “Done only from Testing”.</p>
                        </div>
                    ) : (
                        <ul className="divide-y rounded-md border" aria-label="Rules on this board">
                            {active.map((column) => (
                                <li key={column.id} className="flex flex-col gap-1.5 p-3 sm:flex-row sm:items-start sm:gap-3">
                                    <a href={`#rule-${column.id}`} className="shrink-0 sm:w-32"><StatusChip column={column} columns={columns} /></a>
                                    <span className="text-sm text-foreground/90">{describeRule(rules[column.id], column, columns, memberName).join(' ')}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </CardContent>
            </Card>

            {columns.map((column) => {
                const saved = rules[column.id] ?? EMPTY_RULE;
                return (
                    <RuleEditor
                        key={`${column.id}:${ruleKey(saved)}`}
                        workspace={workspace}
                        project={project}
                        board={board}
                        column={column}
                        saved={saved}
                        members={members}
                        roles={roles}
                        memberName={memberName}
                    />
                );
            })}
        </div>
    );
}
