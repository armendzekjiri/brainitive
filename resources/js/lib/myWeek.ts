import type { MyWorkItem, PlanLine, PlanNoteColor, PlanProject } from '@/types';
import { addDays, format, parseISO } from 'date-fns';

/** My Week (v1.53.0): pure helpers shared by the week board and the Day view. */

/** The undated list's key where a date would go (`plan_date = someday`). */
export const SOMEDAY = 'someday';

/** Drag payloads: a plan line (entry id) or a My Work row ("board:12" / "list:4"). */
export const PLAN_LINE_MIME = 'application/x-brainitive-plan-line';
export const MY_WORK_MIME = 'application/x-brainitive-work';

export const NOTE_COLORS: { value: PlanNoteColor; label: string; className: string }[] = [
    { value: 'red', label: 'Red', className: 'bg-red-500' },
    { value: 'orange', label: 'Orange', className: 'bg-orange-500' },
    { value: 'yellow', label: 'Yellow', className: 'bg-amber-400' },
    { value: 'green', label: 'Green', className: 'bg-emerald-500' },
    { value: 'blue', label: 'Blue', className: 'bg-sky-500' },
    { value: 'purple', label: 'Purple', className: 'bg-violet-500' },
    { value: 'pink', label: 'Pink', className: 'bg-pink-500' },
];

export const noteColorClass = (color: PlanNoteColor | null) => NOTE_COLORS.find((c) => c.value === color)?.className ?? null;

export const isItemDone = (item: MyWorkItem) => !!item.completed_at || item.status === 'done';

export const lineDone = (line: PlanLine) => (line.item ? isItemDone(line.item) : line.done);

export const lineTitle = (line: PlanLine) => line.item?.title ?? line.title ?? '';

/** Where a linked line opens (null for notes or a project without workspace). */
export function workHref(item: MyWorkItem, project: PlanProject | null): string | null {
    const ws = project?.workspace?.slug;
    if (!ws || !project) return null;
    return item.work_type === 'list'
        ? route('task-lists.show', [ws, project.slug, item.source.slug]) + `#task-${item.id}`
        : route('boards.items.show', [ws, project.slug, item.source.slug, item.id]);
}

export const dateOf = (iso: string) => parseISO(iso);
export const shiftDate = (iso: string, days: number) => format(addDays(parseISO(iso), days), 'yyyy-MM-dd');

/** "16 – 22 Nov 2026" / "30 Nov – 6 Dec 2026" / "29 Dec 2026 – 4 Jan 2027" */
export function weekRangeLabel(start: string): string {
    const from = parseISO(start);
    const to = addDays(from, 6);
    if (from.getFullYear() !== to.getFullYear()) return `${format(from, 'd MMM yyyy')} – ${format(to, 'd MMM yyyy')}`;
    if (from.getMonth() !== to.getMonth()) return `${format(from, 'd MMM')} – ${format(to, 'd MMM yyyy')}`;
    return `${format(from, 'd')} – ${format(to, 'd MMM yyyy')}`;
}

/** Ids of a day's lines after moving `id` to `index` (it may come from another day). */
export function idsWithMove(ids: number[], id: number, index: number): number[] {
    const without = ids.filter((x) => x !== id);
    const removedBefore = ids.slice(0, index).includes(id) ? 1 : 0;
    const at = Math.max(0, Math.min(without.length, index - removedBefore));
    return [...without.slice(0, at), id, ...without.slice(at)];
}
