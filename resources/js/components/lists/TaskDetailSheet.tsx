import { AssigneeAvatar } from '@/components/board/ItemPrimitives';
import { RichLexicalEditor } from '@/components/editor/RichLexicalEditorFull';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet';
import { cn } from '@/lib/utils';
import type { TaskListItem, User } from '@/types';
import { format } from 'date-fns';
import { Check, Copy, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useRef, useState, type MutableRefObject, type ReactNode } from 'react';
import { DUE_TONE_CLASSES, RECURRENCE_LABELS, dueInfo, isRealDate, parseDay, reminderLabel } from './taskDates';
import { AssigneePicker, DuePicker, PickerChip, PickerIcons, ReminderPicker, RepeatPicker } from './TaskPickers';
import type { TaskRowHandlers } from './TaskRow';

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex min-h-9 items-center gap-3">
            <span className="w-24 shrink-0 text-xs font-medium text-muted-foreground">{label}</span>
            <div className="min-w-0 flex-1">{children}</div>
        </div>
    );
}

function Details({ item, members, canManage, handlers, flushRef }: {
    item: TaskListItem;
    members: User[];
    canManage: boolean;
    handlers: TaskRowHandlers;
    flushRef: MutableRefObject<(() => void) | null>;
}) {
    const [title, setTitle] = useState(item.title);
    const [startDraft, setStartDraft] = useState(item.start_date ?? '');
    const savedNotes = (item.description as { html?: string } | null)?.html ?? '';
    const notes = useRef(savedNotes);
    const update = (patch: Partial<TaskListItem>) => handlers.onUpdate(item, patch);
    const done = item.status === 'done';
    const due = dueInfo(item);
    const assignee = item.assignee ?? members.find((m) => m.id === item.assignee_id) ?? null;

    // What was last sent, so blur + close never save the same text twice.
    const lastTitle = useRef(item.title);
    const lastNotes = useRef(savedNotes);

    const saveTitle = () => {
        const next = title.trim();
        if (!next) return setTitle(lastTitle.current);
        if (next === lastTitle.current) return;
        lastTitle.current = next;
        update({ title: next });
    };
    const saveNotes = () => {
        if (notes.current === lastNotes.current) return;
        lastNotes.current = notes.current;
        update({ description: { type: 'lexical', html: notes.current } });
    };
    const onNotesChange = useCallback((html: string) => { notes.current = html; }, []);

    // Esc / overlay click unmount the panel before any blur fires: the sheet calls this first.
    useEffect(() => {
        flushRef.current = () => {
            saveTitle();
            saveNotes();
        };
    });

    return (
        <div className="flex h-full flex-col">
            <div className="flex items-start gap-3 border-b px-5 pb-4 pt-5 pr-12">
                <button
                    type="button"
                    disabled={!canManage}
                    onClick={() => handlers.onToggle(item)}
                    aria-label={done ? 'Mark as not done' : 'Complete task'}
                    className={cn(
                        'mt-1 flex size-5 shrink-0 items-center justify-center rounded-full border-[1.5px] transition-colors',
                        done ? 'border-brand-green bg-brand-green text-white' : 'border-muted-foreground/50 hover:border-brand-green',
                    )}
                >
                    {done && <Check className="size-3 stroke-[3]" />}
                </button>
                <div className="min-w-0 flex-1">
                    <SheetTitle className="sr-only">{item.title}</SheetTitle>
                    <textarea
                        value={title}
                        readOnly={!canManage}
                        onChange={(e) => setTitle(e.target.value.replace(/\n/g, ''))}
                        onBlur={saveTitle}
                        onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); e.currentTarget.blur(); } }}
                        rows={Math.min(4, Math.max(1, Math.ceil(title.length / 38)))}
                        maxLength={500}
                        aria-label="Task name"
                        className={cn(
                            'w-full resize-none rounded-md border-0 bg-transparent p-0 text-lg font-semibold leading-snug outline-none focus:ring-0',
                            done && 'text-muted-foreground line-through',
                        )}
                    />
                    <SheetDescription className="text-xs">
                        {item.creator ? `Added by ${item.creator.name}` : 'Added'} · {format(new Date(item.created_at), 'MMM d, yyyy')}
                    </SheetDescription>
                </div>
            </div>

            <div className="flex-1 space-y-1 overflow-y-auto px-5 py-4">
                <Field label="Due">
                    <DuePicker date={item.due_date} time={item.due_time} onChange={(d, t) => update({ due_date: d, due_time: t, ...(d ? {} : { reminder_minutes: null, recurrence: null }) })}>
                        <PickerChip disabled={!canManage} icon={<PickerIcons.due className="size-3.5" />} active={!!due} tone={due ? DUE_TONE_CLASSES[due.tone] : undefined}>
                            {due?.label ?? 'No date'}
                        </PickerChip>
                    </DuePicker>
                </Field>
                <Field label="Reminder">
                    <ReminderPicker value={item.reminder_minutes} hasDueDate={!!item.due_date} onChange={(m) => update({ reminder_minutes: m })}>
                        <PickerChip disabled={!canManage} icon={<PickerIcons.reminder className="size-3.5" />} active={!!item.due_date && item.reminder_minutes != null}>
                            {(item.due_date && reminderLabel(item.reminder_minutes)) || 'None'}
                        </PickerChip>
                    </ReminderPicker>
                </Field>
                <Field label="Repeat">
                    <RepeatPicker value={item.recurrence} hasDueDate={!!item.due_date} onChange={(r) => update({ recurrence: r })}>
                        <PickerChip disabled={!canManage} icon={<PickerIcons.repeat className="size-3.5" />} active={!!item.recurrence}>
                            {item.recurrence ? RECURRENCE_LABELS[item.recurrence] : 'Doesn’t repeat'}
                        </PickerChip>
                    </RepeatPicker>
                </Field>
                <Field label="Assignee">
                    <AssigneePicker value={item.assignee_id} members={members} onChange={(id) => update({ assignee_id: id, assignee: members.find((m) => m.id === id) ?? null })}>
                        <PickerChip disabled={!canManage} icon={assignee ? <AssigneeAvatar user={assignee} size={16} /> : <PickerIcons.assignee className="size-3.5" />} active={!!assignee}>
                            {assignee?.name ?? 'Unassigned'}
                        </PickerChip>
                    </AssigneePicker>
                </Field>
                <Field label="Starts">
                    <div className="flex items-center gap-1.5">
                        <input
                            type="date"
                            aria-label="Start date"
                            disabled={!canManage}
                            value={startDraft}
                            onChange={(e) => {
                                setStartDraft(e.target.value);
                                if (!e.target.value) update({ start_date: null, start_time: null });
                                else if (isRealDate(e.target.value)) update({ start_date: e.target.value });
                            }}
                            className="h-7 rounded-md border bg-background px-2 text-xs"
                        />
                        <input
                            type="time"
                            aria-label="Start time"
                            disabled={!canManage || !item.start_date}
                            value={item.start_time?.slice(0, 5) ?? ''}
                            onChange={(e) => update({ start_time: e.target.value || null })}
                            className="h-7 rounded-md border bg-background px-2 text-xs disabled:opacity-50"
                        />
                    </div>
                    {item.start_date && item.start_time && (
                        <p className="mt-1 text-[11px] text-muted-foreground">
                            Reminder 15 minutes before it starts, {parseDay(item.start_date) && format(parseDay(item.start_date)!, 'EEE MMM d')}.
                        </p>
                    )}
                </Field>

                <div className="pt-4">
                    <p className="mb-1.5 text-xs font-medium text-muted-foreground">Notes</p>
                    <div className="rounded-lg border px-1" onBlur={(e) => { if (!e.currentTarget.contains(e.relatedTarget as Node)) saveNotes(); }}>
                        <RichLexicalEditor
                            content={savedNotes || null}
                            onChange={onNotesChange}
                            editable={canManage}
                            showToolbar={canManage}
                            minHeight="120px"
                            maxHeight="420px"
                            placeholder={canManage ? 'Add notes, links, a checklist…' : 'No notes'}
                            className="border-0 bg-transparent shadow-none"
                        />
                    </div>
                </div>
            </div>

            {canManage && (
                <div className="flex items-center justify-between border-t px-5 py-3">
                    <Button variant="ghost" size="sm" className="h-8 text-xs" onClick={() => handlers.onDuplicate(item)}>
                        <Copy className="mr-1.5 size-3.5" /> Duplicate
                    </Button>
                    <Button variant="ghost" size="sm" className="h-8 text-xs text-destructive hover:text-destructive" onClick={() => handlers.onDelete(item)}>
                        <Trash2 className="mr-1.5 size-3.5" /> Delete
                    </Button>
                </div>
            )}
        </div>
    );
}

/** Everything about one task, saved as you go (title on blur / Enter, notes on blur). */
export default function TaskDetailSheet({ item, members, canManage, handlers, onClose }: {
    item: TaskListItem | null;
    members: User[];
    canManage: boolean;
    handlers: TaskRowHandlers;
    onClose: () => void;
}) {
    const flush = useRef<(() => void) | null>(null);

    return (
        <Sheet
            open={!!item}
            onOpenChange={(open) => {
                if (open) return;
                if (canManage) flush.current?.();
                onClose();
            }}
        >
            <SheetContent side="right" className="w-full p-0 sm:max-w-md">
                {item && <Details key={item.id} item={item} members={members} canManage={canManage} handlers={handlers} flushRef={flush} />}
            </SheetContent>
        </Sheet>
    );
}
