import { useEffect, useMemo, useRef, useState, type MouseEvent, type PointerEvent, type ReactElement } from 'react';
import { toast } from 'sonner';
import {
    CalendarDays, Check, CircleDot, Flag, Hash, Layers, Link2, Loader2, Maximize2, Shapes, Tag, Trash2, UserRound,
} from 'lucide-react';
import type { Board, BoardColumn, Item, Project, Workspace } from '@/types';
import {
    ContextMenu, ContextMenuCheckboxItem, ContextMenuContent, ContextMenuItem, ContextMenuLabel, ContextMenuSeparator,
    ContextMenuShortcut, ContextMenuSub, ContextMenuSubContent, ContextMenuSubTrigger, ContextMenuTrigger,
} from '@/components/ui/context-menu';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { ItemTypeIcon } from '@/components/ItemTypeIcon';
import { AssigneeAvatar, LabelChip, PriorityIndicator, StatusLozenge, statusCategory } from '@/components/board/ItemPrimitives';
import { BulkDeleteDialog, canDeleteItem, issueNoun, useBulkItemEdit, type BulkChanges, type BulkEditOptions } from '@/components/board/bulkItemEdit';

/** The attribute that marks a row / card as an issue of the menu: `data-context-item={item.id}`. */
export const CONTEXT_ITEM_ATTR = 'data-context-item';

/** Right-clicks here keep the browser's own menu (copy/paste, "Open link in new tab"…). */
const NATIVE_MENU_TARGETS = 'input:not([type="checkbox"]):not([type="radio"]), textarea, select, [contenteditable]:not([contenteditable="false"]), a[href]';

interface Props {
    workspace: Workspace;
    project: Project;
    board: Board;
    columns: BoardColumn[];
    /** Every issue the menu can act on, in display order (multi-selections keep this order). */
    items: Item[];
    /** The list's checkbox selection: a right-click on a selected row acts on all of them. */
    selectedIds?: ReadonlySet<number>;
    /** null = read-only (viewers): Open and the copy entries only. */
    edit: BulkEditOptions | null;
    onOpenItem: (item: Item) => void;
    /** The element holding the rows / cards (a `<tbody>`, the board); it becomes the trigger. */
    children: ReactElement;
}

interface Target {
    /** The row / card under the pointer. */
    id: number;
    /** What the edits apply to: the selection, or just `id`. */
    ids: number[];
}

function toISODate(d: Date): string {
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

function addDays(d: Date, days: number): Date {
    const next = new Date(d);
    next.setDate(next.getDate() + days);
    return next;
}

/** The value all targets share, or undefined when they differ. */
function shared<T>(items: Item[], value: (item: Item) => T): T | undefined {
    if (items.length === 0) return undefined;
    const first = value(items[0]);
    return items.every((item) => value(item) === first) ? first : undefined;
}

const Current = ({ when }: { when: boolean }) =>
    when ? <Check className="ml-auto size-3.5 text-primary" aria-label="(current)" /> : null;

/**
 * The right-click menu on the board's issues (GitHub #143): the list view's rows and the kanban
 * cards. One menu for the whole view; the row / card is found from the event target. The edits are
 * the bulk bar's (`useBulkItemEdit`): same requests, same server-side permission checks.
 *
 * Touch screens keep their own long-press menu: Radix opens on a 700 ms press, which would fight
 * the cards' drag and start anywhere on the board.
 */
export function ItemContextMenu({ workspace, project, board, columns, items, selectedIds, edit, onOpenItem, children }: Props) {
    const { busy, update, destroy } = useBulkItemEdit(workspace, project, board);
    const [open, setOpen] = useState(false);
    const [target, setTarget] = useState<Target | null>(null);
    const [dialog, setDialog] = useState<'due' | 'delete' | null>(null);
    const [dueDate, setDueDate] = useState('');
    const pending = useRef<Target | null>(null);
    const container = useRef<HTMLElement>(null);
    const coarsePointer = useMemo(() => typeof window !== 'undefined' && !!window.matchMedia?.('(pointer: coarse)').matches, []);

    // Targets are ids: after an edit the menu shows the reloaded issues (label toggles stay open)
    const byId = useMemo(() => new Map(items.map((item) => [item.id, item])), [items]);
    const targets = useMemo(
        () => (target ? target.ids.map((id) => byId.get(id)).filter((item): item is Item => !!item) : []),
        [target, byId],
    );
    const pointed = target ? byId.get(target.id) : undefined;

    /** The row / card under the pointer, like a file manager: the whole selection when it is part of it. */
    const resolve = (eventTarget: EventTarget | null): Target | null => {
        if (!(eventTarget instanceof Element) || eventTarget.closest(NATIVE_MENU_TARGETS)) return null;
        const element = eventTarget.closest(`[${CONTEXT_ITEM_ATTR}]`);
        if (!element || !container.current?.contains(element)) return null;
        const id = Number(element.getAttribute(CONTEXT_ITEM_ATTR));
        if (!byId.has(id)) return null;
        const ids = selectedIds?.has(id) ? items.filter((item) => selectedIds.has(item.id)).map((item) => item.id) : [id];
        return { id, ids };
    };

    // Capture phase: runs before Radix' handler on the same element; stopping the event here keeps
    // the browser's menu (headers, links, inputs, popovers rendered through portals)
    const onContextMenuCapture = (e: MouseEvent) => {
        pending.current = resolve(e.target);
        if (!pending.current) e.stopPropagation();
    };
    // Pen long-press on a fine-pointer device: Radix opens without a contextmenu event
    const onPointerDownCapture = (e: PointerEvent) => {
        if (e.pointerType !== 'mouse') pending.current = resolve(e.target);
    };
    const onOpenChange = (next: boolean) => {
        if (!next) {
            setOpen(false);
            return;
        }
        if (!pending.current) return;
        setTarget(pending.current);
        pending.current = null;
        setOpen(true);
    };

    // Mark the rows / cards the menu acts on while it is open
    useEffect(() => {
        const root = container.current;
        if (!open || !root || !target) return;
        const marked = target.ids
            .map((id) => root.querySelector(`[${CONTEXT_ITEM_ATTR}="${id}"]`))
            .filter((element): element is Element => !!element);
        marked.forEach((element) => element.setAttribute('data-context-target', ''));
        return () => marked.forEach((element) => element.removeAttribute('data-context-target'));
    }, [open, target]);

    const count = targets.length;
    const noun = issueNoun(count);
    const multiple = count > 1;
    const apply = (changes: BulkChanges, what: string) => update(targets, changes, what);
    const allDeletable = !!edit && targets.every((item) => canDeleteItem(item, edit));

    const copy = (text: string, what: string) => {
        navigator.clipboard.writeText(text).then(
            () => toast.success(what),
            () => toast.error('Could not copy to the clipboard.'),
        );
    };
    const itemUrl = (item: Item) => route('boards.items.show', [workspace.slug, project.slug, board.slug, item.id]);

    const today = new Date();
    const dueShortcuts = [
        { label: 'Today', date: today },
        { label: 'Tomorrow', date: addDays(today, 1) },
        // next Monday
        { label: 'Next week', date: addDays(today, ((8 - today.getDay()) % 7) || 7) },
    ];

    const column = shared(targets, (item) => item.column_id);
    const assignee = shared(targets, (item) => item.assigned_to ?? null);
    const priority = shared(targets, (item) => item.priority_id ?? null);
    const type = shared(targets, (item) => item.item_type_id ?? null);
    const sprint = shared(targets, (item) => item.sprint_id ?? null);
    const anyDue = targets.some((item) => !!item.due_date);

    return (
        <>
            <ContextMenu open={open} onOpenChange={onOpenChange}>
                <ContextMenuTrigger
                    asChild
                    ref={container}
                    disabled={coarsePointer}
                    // the trigger sets `-webkit-touch-callout: none`; keep the phone's own long-press menu
                    style={coarsePointer ? { WebkitTouchCallout: 'default' } : undefined}
                    onContextMenuCapture={onContextMenuCapture}
                    onPointerDownCapture={onPointerDownCapture}
                >
                    {children}
                </ContextMenuTrigger>
                {pointed && (
                    <ContextMenuContent className="w-60" aria-label={multiple ? `${count} issues` : pointed.key}>
                        <ContextMenuLabel className="flex items-center gap-2 text-xs font-semibold text-muted-foreground">
                            {busy && <Loader2 className="size-3 animate-spin" aria-hidden />}
                            {multiple ? `${count} issues` : pointed.key}
                        </ContextMenuLabel>
                        <ContextMenuItem onSelect={() => onOpenItem(pointed)}>
                            <Maximize2 />{multiple ? `Open ${pointed.key}` : 'Open'}
                        </ContextMenuItem>
                        <ContextMenuItem onSelect={() => copy(targets.map(itemUrl).join('\n'), multiple ? 'Links copied' : 'Link copied')}>
                            <Link2 />{multiple ? 'Copy links' : 'Copy link'}
                        </ContextMenuItem>
                        <ContextMenuItem onSelect={() => copy(targets.map((item) => item.key).join(', '), multiple ? 'Keys copied' : 'Key copied')}>
                            <Hash />{multiple ? 'Copy keys' : 'Copy key'}
                        </ContextMenuItem>

                        {edit && (
                            <>
                                <ContextMenuSeparator />

                                <ContextMenuSub>
                                    <ContextMenuSubTrigger disabled={busy}><CircleDot />Status</ContextMenuSubTrigger>
                                    <ContextMenuSubContent className="max-h-80 w-52">
                                        {columns.map((c) => (
                                            <ContextMenuItem key={c.id} onSelect={() => apply({ column_id: c.id }, `Moved to ${c.name}`)}>
                                                <StatusLozenge name={c.name} category={statusCategory(c, columns)} />
                                                <Current when={column === c.id} />
                                            </ContextMenuItem>
                                        ))}
                                    </ContextMenuSubContent>
                                </ContextMenuSub>

                                <ContextMenuSub>
                                    <ContextMenuSubTrigger disabled={busy}><UserRound />Assignee</ContextMenuSubTrigger>
                                    <ContextMenuSubContent className="max-h-80 w-56">
                                        <ContextMenuItem onSelect={() => apply({ assigned_to: null }, 'Unassigned')}>
                                            <span className="flex size-[18px] items-center justify-center rounded-full border border-dashed text-muted-foreground"><UserRound className="size-3" /></span>
                                            Unassigned
                                            <Current when={assignee === null} />
                                        </ContextMenuItem>
                                        {edit.users.map((user) => (
                                            <ContextMenuItem key={user.id} onSelect={() => apply({ assigned_to: user.id }, `Assigned to ${user.name}`)}>
                                                <AssigneeAvatar user={user} />
                                                <span className="truncate">{user.name}</span>
                                                <Current when={assignee === user.id} />
                                            </ContextMenuItem>
                                        ))}
                                    </ContextMenuSubContent>
                                </ContextMenuSub>

                                <ContextMenuSub>
                                    <ContextMenuSubTrigger disabled={busy}><Flag />Priority</ContextMenuSubTrigger>
                                    <ContextMenuSubContent className="w-48">
                                        {edit.priorities.map((p) => (
                                            <ContextMenuItem key={p.id} onSelect={() => apply({ priority_id: p.id }, `Priority set to ${p.name}`)}>
                                                <PriorityIndicator slug={p.slug} />
                                                {p.name}
                                                <Current when={priority === p.id} />
                                            </ContextMenuItem>
                                        ))}
                                    </ContextMenuSubContent>
                                </ContextMenuSub>

                                <ContextMenuSub>
                                    <ContextMenuSubTrigger disabled={busy}><Shapes />Type</ContextMenuSubTrigger>
                                    <ContextMenuSubContent className="max-h-80 w-48">
                                        {edit.itemTypes.map((t) => (
                                            <ContextMenuItem key={t.id} onSelect={() => apply({ item_type_id: t.id }, `Type set to ${t.name}`)}>
                                                <ItemTypeIcon type={t} className="size-4" />
                                                {t.name}
                                                <Current when={type === t.id} />
                                            </ContextMenuItem>
                                        ))}
                                    </ContextMenuSubContent>
                                </ContextMenuSub>

                                {edit.sprints && (
                                    <ContextMenuSub>
                                        <ContextMenuSubTrigger disabled={busy}><Layers />Sprint</ContextMenuSubTrigger>
                                        <ContextMenuSubContent className="max-h-80 w-52">
                                            {edit.sprints.map((s) => (
                                                <ContextMenuItem key={s.id} onSelect={() => apply({ sprint_id: s.id }, `Moved to ${s.name}`)}>
                                                    <span className="truncate">{s.name}</span>
                                                    <Current when={sprint === s.id} />
                                                </ContextMenuItem>
                                            ))}
                                            <ContextMenuSeparator />
                                            <ContextMenuItem onSelect={() => apply({ sprint_id: null }, 'Moved to the backlog')}>
                                                Backlog
                                                <Current when={sprint === null} />
                                            </ContextMenuItem>
                                        </ContextMenuSubContent>
                                    </ContextMenuSub>
                                )}

                                <ContextMenuSub>
                                    <ContextMenuSubTrigger disabled={busy}><CalendarDays />Due date</ContextMenuSubTrigger>
                                    <ContextMenuSubContent className="w-56">
                                        {dueShortcuts.map(({ label, date }) => (
                                            <ContextMenuItem key={label} onSelect={() => apply({ due_date: toISODate(date) }, 'Due date set')}>
                                                {label}
                                                <ContextMenuShortcut>{date.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' })}</ContextMenuShortcut>
                                            </ContextMenuItem>
                                        ))}
                                        <ContextMenuItem onSelect={() => { setDueDate(''); setDialog('due'); }}>Pick a date…</ContextMenuItem>
                                        <ContextMenuSeparator />
                                        <ContextMenuItem disabled={!anyDue} onSelect={() => apply({ due_date: null }, 'Due date cleared')}>Clear due date</ContextMenuItem>
                                    </ContextMenuSubContent>
                                </ContextMenuSub>

                                <ContextMenuSub>
                                    <ContextMenuSubTrigger disabled={busy}><Tag />Labels</ContextMenuSubTrigger>
                                    <ContextMenuSubContent className="max-h-80 w-56">
                                        {edit.labels.length === 0 ? (
                                            <p className="px-2 py-1.5 text-sm text-muted-foreground">This project has no labels yet.</p>
                                        ) : edit.labels.map((label) => {
                                            const having = targets.filter((item) => item.labels?.some((l) => l.id === label.id)).length;
                                            const all = having === count;
                                            return (
                                                <ContextMenuCheckboxItem
                                                    key={label.id}
                                                    checked={all ? true : having > 0 ? 'indeterminate' : false}
                                                    disabled={busy}
                                                    // a toggle: the menu stays open for the next label
                                                    onSelect={(e) => {
                                                        e.preventDefault();
                                                        if (all) apply({ remove_label_ids: [label.id] }, `Label “${label.name}” removed`);
                                                        else apply({ add_label_ids: [label.id] }, `Label “${label.name}” added`);
                                                    }}
                                                >
                                                    <LabelChip label={label} />
                                                </ContextMenuCheckboxItem>
                                            );
                                        })}
                                    </ContextMenuSubContent>
                                </ContextMenuSub>

                                <ContextMenuSeparator />
                                <ContextMenuItem
                                    disabled={busy || !allDeletable}
                                    className="text-destructive focus:text-destructive"
                                    onSelect={() => setDialog('delete')}
                                >
                                    <Trash2 />{multiple ? `Delete ${count} issues…` : 'Delete…'}
                                </ContextMenuItem>
                                {!allDeletable && (
                                    <p className="px-2 pb-1 text-[11px] leading-snug text-muted-foreground">
                                        Only project admins can delete issues created by someone else.
                                    </p>
                                )}
                            </>
                        )}
                    </ContextMenuContent>
                )}
            </ContextMenu>

            {edit && (
                <>
                    <BulkDeleteDialog
                        open={dialog === 'delete'}
                        onOpenChange={(next) => setDialog(next ? 'delete' : null)}
                        items={targets}
                        onConfirm={() => destroy(targets)}
                    />
                    <Dialog open={dialog === 'due'} onOpenChange={(next) => setDialog(next ? 'due' : null)}>
                        <DialogContent className="sm:max-w-sm">
                            <DialogHeader>
                                <DialogTitle>Due date</DialogTitle>
                                <DialogDescription>{count === 1 ? targets[0]?.key : `${count} ${noun}`}</DialogDescription>
                            </DialogHeader>
                            <form
                                className="space-y-4"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    if (!dueDate) return;
                                    setDialog(null);
                                    apply({ due_date: dueDate }, 'Due date set');
                                }}
                            >
                                <label className="block space-y-1.5 text-sm font-medium">
                                    <span>Due date for {count} {noun}</span>
                                    <input
                                        type="date"
                                        autoFocus
                                        value={dueDate}
                                        onChange={(e) => setDueDate(e.target.value)}
                                        className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring dark:[color-scheme:dark]"
                                    />
                                </label>
                                <DialogFooter>
                                    <Button type="button" variant="ghost" onClick={() => setDialog(null)}>Cancel</Button>
                                    <Button type="submit" disabled={!dueDate}>Set</Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>
                </>
            )}
        </>
    );
}
