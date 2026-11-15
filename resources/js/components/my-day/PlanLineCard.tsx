import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import WorkDoneToggle from '@/components/WorkDoneToggle';
import { lineDone, lineTitle, NOTE_COLORS, noteColorClass, SOMEDAY, workHref } from '@/lib/myWeek';
import { cn } from '@/lib/utils';
import type { PlanLine } from '@/types';
import { Link, router } from '@inertiajs/react';
import { format, nextMonday, parseISO } from 'date-fns';
import { CalendarDays, Check, ExternalLink, MoreHorizontal, Palette, Trash2 } from 'lucide-react';
import { useState, type HTMLAttributes, type KeyboardEvent } from 'react';
import { toast } from 'sonner';

const options = { preserveScroll: true, preserveState: true } as const;

/** The circle of a free-form line: ticks the note itself (a linked line uses WorkDoneToggle). */
export function NoteDoneToggle({ line, className }: { line: PlanLine; className?: string }) {
    const [done, setDone] = useState(line.done);
    const [busy, setBusy] = useState(false);
    const [seen, setSeen] = useState(line.done);
    if (seen !== line.done) {
        setSeen(line.done);
        setDone(line.done);
    }
    const toggle = () => {
        if (busy) return;
        const next = !done;
        router.patch(route('my-day.entries.update', line.id), { done: next }, {
            ...options,
            onStart: () => {
                setBusy(true);
                setDone(next);
            },
            onError: () => {
                setDone(!next);
                toast.error(next ? 'Could not tick it.' : 'Could not reopen it.');
            },
            onFinish: () => setBusy(false),
        });
    };
    return (
        <button
            type="button"
            onClick={toggle}
            disabled={busy}
            role="checkbox"
            aria-checked={done}
            aria-label={done ? `Reopen “${line.title}”` : `Mark “${line.title}” as done`}
            title={done ? 'Reopen' : 'Mark as done'}
            className={cn(
                'grid size-[18px] shrink-0 place-items-center rounded-full border transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                done ? 'border-brand-green bg-brand-green text-white' : 'border-muted-foreground/40 bg-card text-transparent hover:border-brand-green hover:text-brand-green/60',
                className,
            )}
        >
            <Check className="size-3" strokeWidth={3} />
        </button>
    );
}

/** Note text that turns into an input on click; Enter / blur saves, Escape cancels. */
export function EditableNoteTitle({ line, className }: { line: PlanLine; className?: string }) {
    const [editing, setEditing] = useState(false);
    const [value, setValue] = useState(line.title ?? '');
    // the saved text shows right away, until the server's answer brings the new title
    const [pending, setPending] = useState<string | null>(null);
    const done = line.done;

    const save = () => {
        setEditing(false);
        const title = value.trim();
        if (title === '' || title === line.title) {
            setValue(line.title ?? '');
            return;
        }
        setPending(title);
        router.patch(route('my-day.entries.update', line.id), { title }, {
            ...options,
            onError: () => {
                toast.error('Could not save the line.');
                setValue(title);
                setEditing(true); // keep what was typed
            },
            onFinish: () => setPending(null),
        });
    };
    const onKeyDown = (e: KeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) {
            e.preventDefault();
            save();
        } else if (e.key === 'Escape') {
            setValue(line.title ?? '');
            setEditing(false);
        }
    };

    if (editing) {
        return (
            <textarea
                autoFocus
                value={value}
                rows={Math.min(4, Math.max(1, Math.ceil(value.length / 28)))}
                maxLength={500}
                onChange={(e) => setValue(e.target.value)}
                onBlur={save}
                onKeyDown={onKeyDown}
                aria-label="Edit line"
                className={cn('w-full resize-none rounded-sm bg-background px-1 text-[12.5px] font-medium leading-snug outline-none ring-1 ring-ring', className)}
            />
        );
    }
    const shown = pending ?? line.title;
    return (
        <button
            type="button"
            onClick={() => {
                setValue(shown ?? '');
                setEditing(true);
            }}
            title="Click to edit"
            className={cn('block w-full whitespace-pre-wrap break-words text-left font-medium leading-snug', done && 'text-muted-foreground line-through', className)}
        >
            {shown}
        </button>
    );
}

/** The ⋯ menu of a line: colour (notes), move to another day, open (linked), remove. */
export function PlanLineMenu({ line, date, weekDates }: { line: PlanLine; date: string; weekDates: string[] }) {
    const title = lineTitle(line);
    const move = (to: string) =>
        router.patch(route('my-day.entries.update', line.id), { plan_date: to }, {
            ...options,
            onSuccess: () => toast.success(to === SOMEDAY ? 'Moved to Someday.' : `Moved to ${format(parseISO(to), 'EEEE, d MMM')}.`),
            onError: () => toast.error('Could not move it.'),
        });
    const remove = () =>
        router.delete(route('my-day.entries.destroy', line.id), { ...options, onError: () => toast.error('Could not remove it.') });
    const color = (value: string | null) =>
        router.patch(route('my-day.entries.update', line.id), { color: value }, { ...options, onError: () => toast.error('Could not change the colour.') });
    const href = line.item ? workHref(line.item, line.project) : null;
    const from = date === SOMEDAY ? weekDates[0] : date;
    const nextMon = from ? format(nextMonday(parseISO(from)), 'yyyy-MM-dd') : null;
    const targets = [...weekDates.filter((d) => d !== date), ...(nextMon && !weekDates.includes(nextMon) ? [nextMon] : [])];

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    aria-label={`Options for “${title}”`}
                    className="grid size-6 shrink-0 place-items-center rounded text-muted-foreground opacity-100 transition-opacity hover:bg-secondary hover:text-foreground focus-visible:opacity-100 data-[state=open]:opacity-100 md:opacity-0 md:group-hover:opacity-100"
                >
                    <MoreHorizontal className="size-4" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-52">
                {href && (
                    <DropdownMenuItem asChild>
                        <Link href={href}>
                            <ExternalLink className="mr-2 size-4" /> Open {line.item?.key}
                        </Link>
                    </DropdownMenuItem>
                )}
                {line.kind === 'note' && (
                    <DropdownMenuSub>
                        <DropdownMenuSubTrigger>
                            <Palette className="mr-2 size-4" /> Colour
                        </DropdownMenuSubTrigger>
                        <DropdownMenuSubContent>
                            <DropdownMenuItem onSelect={() => color(null)}>
                                <span className="mr-2 size-3 rounded-full border border-muted-foreground/40" /> None
                            </DropdownMenuItem>
                            {NOTE_COLORS.map((c) => (
                                <DropdownMenuItem key={c.value} onSelect={() => color(c.value)}>
                                    <span className={cn('mr-2 size-3 rounded-full', c.className)} /> {c.label}
                                    {line.color === c.value && <Check className="ml-auto size-3.5" />}
                                </DropdownMenuItem>
                            ))}
                        </DropdownMenuSubContent>
                    </DropdownMenuSub>
                )}
                <DropdownMenuSub>
                    <DropdownMenuSubTrigger>
                        <CalendarDays className="mr-2 size-4" /> Move to
                    </DropdownMenuSubTrigger>
                    <DropdownMenuSubContent>
                        <DropdownMenuLabel className="text-[11px] font-semibold text-muted-foreground">Move to</DropdownMenuLabel>
                        {targets.map((d) => (
                            <DropdownMenuItem key={d} onSelect={() => move(d)}>
                                {d === nextMon && !weekDates.includes(nextMon) ? `Next week · ${format(parseISO(d), 'EEE d MMM')}` : format(parseISO(d), 'EEEE d MMM')}
                            </DropdownMenuItem>
                        ))}
                        {date !== SOMEDAY && (
                            <>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem onSelect={() => move(SOMEDAY)}>Someday</DropdownMenuItem>
                            </>
                        )}
                    </DropdownMenuSubContent>
                </DropdownMenuSub>
                <DropdownMenuSeparator />
                <DropdownMenuItem onSelect={remove} className="text-destructive focus:text-destructive">
                    <Trash2 className="mr-2 size-4" /> {line.kind === 'note' ? 'Delete line' : 'Remove from plan'}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * A line on the week board as a small card: circle + title; linked work shows its key and project
 * underneath. Notes carry their colour as a stripe on the left.
 */
export default function PlanLineCard({
    line,
    date,
    weekDates,
    className,
    ...rest
}: { line: PlanLine; date: string; weekDates: string[] } & HTMLAttributes<HTMLLIElement>) {
    const done = lineDone(line);
    const stripe = line.kind === 'note' ? noteColorClass(line.color) : null;
    const href = line.item ? workHref(line.item, line.project) : null;
    const ws = line.project?.workspace?.slug;

    return (
        <li
            {...rest}
            data-line-id={line.id}
            className={cn(
                'group relative flex cursor-grab items-start gap-2 overflow-hidden rounded-md border border-border/70 bg-card py-[7px] pl-2.5 pr-1 text-[12.5px] shadow-[0_1px_1px_rgba(20,32,44,0.04)] transition-[border-color,box-shadow] hover:border-border hover:shadow-[0_2px_6px_rgba(20,32,44,0.07)] active:cursor-grabbing',
                done && 'opacity-60',
                className,
            )}
        >
            {stripe && <span aria-hidden className={cn('absolute inset-y-0 left-0 w-[3px]', stripe)} />}
            <span className="mt-px">
                {line.item && ws && line.project ? (
                    <WorkDoneToggle item={line.item} workspaceSlug={ws} projectSlug={line.project.slug} className="size-4" />
                ) : line.kind === 'note' ? (
                    <NoteDoneToggle line={line} className="size-4" />
                ) : null}
            </span>
            <div className="min-w-0 flex-1">
                {line.kind === 'note' ? (
                    <EditableNoteTitle line={line} />
                ) : href ? (
                    <Link href={href} className={cn('line-clamp-3 font-medium leading-snug hover:text-primary', done && 'text-muted-foreground line-through')}>
                        {line.item?.title}
                    </Link>
                ) : (
                    <span className="line-clamp-3 font-medium leading-snug">{line.item?.title}</span>
                )}
                {line.item && line.project && (
                    <span className="mt-1 flex min-w-0 items-center gap-1.5 text-[10.5px] text-muted-foreground">
                        <span className="size-1.5 shrink-0 rounded-[2px]" style={{ backgroundColor: line.project.color || '#0E83DC' }} />
                        <span className="shrink-0 font-mono">{line.item.key}</span>
                        <span className="truncate">· {line.project.name}</span>
                    </span>
                )}
            </div>
            <PlanLineMenu line={line} date={date} weekDates={weekDates} />
        </li>
    );
}
