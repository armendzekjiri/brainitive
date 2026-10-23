import { AssigneeAvatar } from '@/components/board/ItemPrimitives';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { TaskListItem, User } from '@/types';
import { router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useRef, useState, type FormEvent } from 'react';
import { toast } from 'sonner';
import { DUE_TONE_CLASSES, RECURRENCE_LABELS, dueInfo, reminderLabel, type Recurrence } from './taskDates';
import { AssigneePicker, DuePicker, PickerChip, PickerIcons, ReminderPicker, RepeatPicker } from './TaskPickers';

const EMPTY = { title: '', due_date: null as string | null, due_time: null as string | null, assignee_id: null as number | null, recurrence: null as Recurrence | null, reminder_minutes: null as number | null };

/** "Add task" row that opens into a composer: Enter adds and stays open for the next one. */
export default function TaskComposer({ storeUrl, members }: { storeUrl: string; members: User[] }) {
    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState(EMPTY);
    const [saving, setSaving] = useState(false);
    const input = useRef<HTMLInputElement>(null);
    const set = (patch: Partial<typeof EMPTY>) => setDraft((d) => ({ ...d, ...patch }));

    const submit = (e?: FormEvent) => {
        e?.preventDefault();
        if (!draft.title.trim() || saving) return;
        setSaving(true);
        router.post(storeUrl, { ...draft, title: draft.title.trim() }, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setDraft(EMPTY);
                input.current?.focus();
            },
            onError: (errors) => toast.error(Object.values(errors)[0] ?? 'Could not add the task.'),
            onFinish: () => setSaving(false),
        });
    };

    if (!open) {
        return (
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="group flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm text-muted-foreground transition-colors hover:text-primary"
            >
                <span className="flex size-5 items-center justify-center rounded-full text-primary transition-colors group-hover:bg-primary group-hover:text-primary-foreground">
                    <Plus className="size-4" />
                </span>
                Add task
            </button>
        );
    }

    const due = dueInfo({ due_date: draft.due_date, due_time: draft.due_time, status: 'todo' } as TaskListItem);
    const assignee = members.find((m) => m.id === draft.assignee_id);

    return (
        <form
            onSubmit={submit}
            onKeyDown={(e) => { if (e.key === 'Escape') { setOpen(false); setDraft(EMPTY); } }}
            className="rounded-xl border bg-card p-2.5 shadow-sm focus-within:border-primary/40"
        >
            <input
                ref={input}
                autoFocus
                value={draft.title}
                onChange={(e) => set({ title: e.target.value })}
                placeholder="Task name — press Enter to add"
                aria-label="Task name"
                maxLength={500}
                className="w-full bg-transparent px-1 py-1 text-sm font-medium outline-none placeholder:font-normal placeholder:text-muted-foreground"
            />
            <div className="mt-2 flex flex-wrap items-center gap-1.5">
                <DuePicker date={draft.due_date} time={draft.due_time} onChange={(d, t) => set({ due_date: d, due_time: t, ...(d ? {} : { reminder_minutes: null, recurrence: null }) })}>
                    <PickerChip icon={<PickerIcons.due className="size-3.5" />} active={!!due} tone={due ? DUE_TONE_CLASSES[due.tone] : undefined}>
                        {due?.label ?? 'Date'}
                    </PickerChip>
                </DuePicker>
                <AssigneePicker value={draft.assignee_id} members={members} onChange={(id) => set({ assignee_id: id })}>
                    <PickerChip icon={assignee ? <AssigneeAvatar user={assignee} size={16} /> : <PickerIcons.assignee className="size-3.5" />} active={!!assignee}>
                        {assignee?.name ?? 'Assignee'}
                    </PickerChip>
                </AssigneePicker>
                <RepeatPicker value={draft.recurrence} hasDueDate={!!draft.due_date} onChange={(r) => set({ recurrence: r })}>
                    <PickerChip icon={<PickerIcons.repeat className="size-3.5" />} active={!!draft.recurrence}>
                        {draft.recurrence ? RECURRENCE_LABELS[draft.recurrence] : 'Repeat'}
                    </PickerChip>
                </RepeatPicker>
                <ReminderPicker value={draft.reminder_minutes} hasDueDate={!!draft.due_date} onChange={(m) => set({ reminder_minutes: m })}>
                    <PickerChip icon={<PickerIcons.reminder className="size-3.5" />} active={draft.reminder_minutes != null}>
                        {reminderLabel(draft.reminder_minutes) ?? 'Reminder'}
                    </PickerChip>
                </ReminderPicker>
                <div className="ml-auto flex items-center gap-1.5">
                    <Button type="button" size="sm" variant="ghost" className="h-7 text-xs" onClick={() => { setOpen(false); setDraft(EMPTY); }}>
                        Cancel
                    </Button>
                    <Button type="submit" size="sm" className={cn('h-7 text-xs')} disabled={!draft.title.trim() || saving}>
                        Add task
                    </Button>
                </div>
            </div>
        </form>
    );
}
