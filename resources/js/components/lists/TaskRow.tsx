import { AssigneeAvatar } from '@/components/board/ItemPrimitives';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import type { TaskListItem, User } from '@/types';
import { AlignLeft, Bell, CalendarDays, Check, Copy, GripVertical, MoreHorizontal, PanelRightOpen, Repeat, Trash2 } from 'lucide-react';
import type { DragEvent } from 'react';
import { DUE_TONE_CLASSES, RECURRENCE_LABELS, RECURRENCE_SHORT, dueInfo, reminderLabel } from './taskDates';
import { DuePicker } from './TaskPickers';

export const hasNotes = (item: TaskListItem) => {
    const html = (item.description as { html?: string } | null)?.html ?? '';
    return html.replace(/<[^>]*>/g, '').trim().length > 0;
};

export interface TaskRowHandlers {
    onToggle: (item: TaskListItem) => void;
    onOpen: (item: TaskListItem) => void;
    onUpdate: (item: TaskListItem, patch: Partial<TaskListItem>) => void;
    onDuplicate: (item: TaskListItem) => void;
    onDelete: (item: TaskListItem) => void;
}

export default function TaskRow({ item, members, canManage, handlers, drag }: {
    item: TaskListItem;
    members: User[];
    canManage: boolean;
    handlers: TaskRowHandlers;
    drag?: { onDragStart: (e: DragEvent) => void; onDragOver: (e: DragEvent) => void; onDrop: (e: DragEvent) => void; onDragEnd: () => void; over: 'above' | 'below' | null; dragging: boolean };
}) {
    const done = item.status === 'done';
    const due = dueInfo(item);
    const assignee = item.assignee ?? members.find((m) => m.id === item.assignee_id) ?? null;
    const reminder = item.due_date ? reminderLabel(item.reminder_minutes) : null;
    const notes = hasNotes(item);

    return (
        <div
            id={`task-${item.id}`}
            draggable={!!drag && canManage}
            onDragStart={drag?.onDragStart}
            onDragOver={drag?.onDragOver}
            onDrop={drag?.onDrop}
            onDragEnd={drag?.onDragEnd}
            className={cn(
                'group relative flex items-start gap-2 rounded-lg px-1.5 py-2 transition-colors hover:bg-muted/50',
                drag?.dragging && 'opacity-40',
                drag?.over === 'above' && 'before:absolute before:inset-x-2 before:-top-px before:h-0.5 before:rounded-full before:bg-primary',
                drag?.over === 'below' && 'after:absolute after:inset-x-2 after:-bottom-px after:h-0.5 after:rounded-full after:bg-primary',
            )}
        >
            {drag && canManage ? (
                <span className="mt-0.5 cursor-grab text-muted-foreground/0 transition-colors group-hover:text-muted-foreground/60" aria-hidden>
                    <GripVertical className="size-4" />
                </span>
            ) : (
                <span className="w-4 shrink-0" />
            )}

            <button
                type="button"
                onClick={() => handlers.onToggle(item)}
                disabled={!canManage}
                aria-label={done ? `Mark “${item.title}” as not done` : `Complete “${item.title}”`}
                className={cn(
                    'group/check mt-0.5 flex size-[18px] shrink-0 items-center justify-center rounded-full border-[1.5px] transition-colors disabled:cursor-default',
                    done ? 'border-brand-green bg-brand-green text-white' : cn('border-muted-foreground/50 hover:border-brand-green', due?.tone === 'overdue' && 'border-destructive/70'),
                )}
            >
                <Check className={cn('size-3 stroke-[3]', done ? 'opacity-100' : 'text-brand-green opacity-0 group-hover/check:opacity-100')} />
            </button>

            <button type="button" onClick={() => handlers.onOpen(item)} className="min-w-0 flex-1 text-left">
                <span className={cn('block break-words text-sm leading-5', done && 'text-muted-foreground line-through decoration-muted-foreground/50')}>{item.title}</span>
                {(due || item.recurrence || reminder || notes) && (
                    <span className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs">
                        {due && (
                            <span className={cn('inline-flex items-center gap-1', DUE_TONE_CLASSES[due.tone])}>
                                <CalendarDays className="size-3" /> {due.label}
                            </span>
                        )}
                        {item.recurrence && (
                            <span className="inline-flex items-center gap-1 text-muted-foreground" title={RECURRENCE_LABELS[item.recurrence]}>
                                <Repeat className="size-3" /> {RECURRENCE_SHORT[item.recurrence]}
                            </span>
                        )}
                        {reminder && !done && (
                            <span className="inline-flex items-center gap-1 text-muted-foreground" title={`Reminder ${reminder}`}>
                                <Bell className="size-3" /> {reminder}
                            </span>
                        )}
                        {notes && <AlignLeft className="size-3 text-muted-foreground" aria-label="Has notes" />}
                    </span>
                )}
            </button>

            <div className="flex shrink-0 items-center gap-0.5">
                {canManage && !done && (
                    <DuePicker date={item.due_date} time={item.due_time} onChange={(d, t) => handlers.onUpdate(item, { due_date: d, due_time: t, ...(d ? {} : { reminder_minutes: null, recurrence: null }) })}>
                        <button type="button" aria-label="Set due date" className="rounded-md p-1 text-muted-foreground opacity-0 transition-opacity hover:bg-muted hover:text-foreground focus-visible:opacity-100 group-hover:opacity-100">
                            <CalendarDays className="size-4" />
                        </button>
                    </DuePicker>
                )}
                {canManage && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button type="button" aria-label="More actions" className="rounded-md p-1 text-muted-foreground opacity-0 transition-opacity hover:bg-muted hover:text-foreground focus-visible:opacity-100 group-hover:opacity-100 data-[state=open]:opacity-100">
                                <MoreHorizontal className="size-4" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-44">
                            <DropdownMenuItem onClick={() => handlers.onOpen(item)}><PanelRightOpen className="mr-2 size-4" /> Open details</DropdownMenuItem>
                            <DropdownMenuItem onClick={() => handlers.onDuplicate(item)}><Copy className="mr-2 size-4" /> Duplicate</DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem className="text-destructive focus:text-destructive" onClick={() => handlers.onDelete(item)}>
                                <Trash2 className="mr-2 size-4" /> Delete
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
                {assignee && <AssigneeAvatar user={assignee} size={22} className="ml-1" />}
            </div>
        </div>
    );
}
