import { DEFAULT_COLOR, newId, PlanBar, PlanData, PlanDependency, PlanRow } from './types';
import { endAfterWorkingDays, fromDay, toDay, workingDays } from './dates';

/**
 * Pure operations on a plan document. Rows are a flat outline: a row's descendants are the
 * rows after it that are deeper, up to the next row at the same or a lower level.
 */

export function isGroup(rows: PlanRow[], index: number): boolean {
    return index + 1 < rows.length && rows[index + 1].level > rows[index].level;
}

/** Index just past the row's last descendant. */
export function blockEnd(rows: PlanRow[], index: number): number {
    const level = rows[index].level;
    let end = index + 1;
    while (end < rows.length && rows[end].level > level) end++;
    return end;
}

export function parentIndex(rows: PlanRow[], index: number): number {
    for (let i = index - 1; i >= 0; i--) if (rows[i].level < rows[index].level) return i;
    return -1;
}

/** Rows that are not hidden inside a collapsed group, with their document index. */
export function visibleRows(rows: PlanRow[]): { row: PlanRow; index: number }[] {
    const out: { row: PlanRow; index: number }[] = [];
    for (let i = 0; i < rows.length; i++) {
        out.push({ row: rows[i], index: i });
        if (rows[i].collapsed && isGroup(rows, i)) i = blockEnd(rows, i) - 1;
    }
    return out;
}

/** Keep the outline valid: first row at level 0, never more than one level deeper than the row above. */
export function normalizeLevels(rows: PlanRow[]): PlanRow[] {
    let previous = -1;
    return rows.map((row) => {
        const level = Math.max(0, Math.min(row.level, previous + 1));
        previous = level;
        return level === row.level ? row : { ...row, level };
    });
}

function withRows(data: PlanData, rows: PlanRow[]): PlanData {
    const barIds = new Set(rows.flatMap((r) => r.bars.map((b) => b.id)));
    return {
        rows: normalizeLevels(rows),
        dependencies: data.dependencies.filter((d) => barIds.has(d.from) && barIds.has(d.to)),
    };
}

export function emptyRow(level = 0, name = ''): PlanRow {
    return { id: newId('r'), name, level, bars: [] };
}

export function insertRows(data: PlanData, index: number, rows: PlanRow[]): PlanData {
    const next = [...data.rows];
    next.splice(Math.max(0, Math.min(index, next.length)), 0, ...rows);
    return withRows(data, next);
}

export function updateRow(data: PlanData, rowId: string, patch: Partial<PlanRow>): PlanData {
    return { ...data, rows: data.rows.map((r) => (r.id === rowId ? { ...r, ...patch } : r)) };
}

/** Indices of the selected rows whose ancestor is not selected too (a block moves as a whole). */
function topSelected(rows: PlanRow[], ids: Set<string>): number[] {
    const out: number[] = [];
    for (let i = 0; i < rows.length; i++) {
        if (!ids.has(rows[i].id)) continue;
        out.push(i);
        i = blockEnd(rows, i) - 1;
    }
    return out;
}

/** Deletes the rows together with their sub-rows. */
export function deleteRows(data: PlanData, rowIds: string[]): PlanData {
    const ids = new Set(rowIds);
    const drop = new Set<number>();
    for (const i of topSelected(data.rows, ids)) {
        for (let j = i; j < blockEnd(data.rows, i); j++) drop.add(j);
    }
    return withRows(data, data.rows.filter((_, i) => !drop.has(i)));
}

export function indentRows(data: PlanData, rowIds: string[]): PlanData {
    const rows = [...data.rows];
    for (const i of topSelected(rows, new Set(rowIds))) {
        if (i === 0 || rows[i - 1].level < rows[i].level) continue;
        const end = blockEnd(rows, i);
        for (let j = i; j < end; j++) rows[j] = { ...rows[j], level: rows[j].level + 1 };
    }
    return withRows(data, rows);
}

export function outdentRows(data: PlanData, rowIds: string[]): PlanData {
    const rows = [...data.rows];
    for (const i of topSelected(rows, new Set(rowIds)).reverse()) {
        if (rows[i].level === 0) continue;
        const end = blockEnd(rows, i);
        for (let j = i; j < end; j++) rows[j] = { ...rows[j], level: rows[j].level - 1 };
    }
    return withRows(data, rows);
}

/**
 * Move a row with its sub-rows so it lands before `toIndex` (an index in the current
 * array; `rows.length` = at the end), taking the level of the row it is dropped on.
 */
export function moveRow(data: PlanData, rowId: string, toIndex: number, keepLevel = false): PlanData {
    const rows = data.rows;
    const from = rows.findIndex((r) => r.id === rowId);
    if (from < 0) return data;
    const end = blockEnd(rows, from);
    if (toIndex >= from && toIndex <= end) return data;

    const block = rows.slice(from, end);
    const rest = [...rows.slice(0, from), ...rows.slice(end)];
    const insertAt = toIndex > from ? toIndex - block.length : toIndex;
    const below = rest[insertAt];
    const above = rest[insertAt - 1];
    const targetLevel = keepLevel
        ? block[0].level
        : Math.min(below ? below.level : (above ? above.level : 0), above ? above.level + 1 : 0);
    const delta = targetLevel - block[0].level;
    rest.splice(insertAt, 0, ...block.map((r) => ({ ...r, level: r.level + delta })));
    return withRows(data, rest);
}

/** Swap a row (with sub-rows) with its previous / next sibling. */
export function moveRowBy(data: PlanData, rowId: string, direction: -1 | 1): PlanData {
    const rows = data.rows;
    const i = rows.findIndex((r) => r.id === rowId);
    if (i < 0) return data;
    const level = rows[i].level;
    if (direction < 0) {
        for (let j = i - 1; j >= 0; j--) {
            if (rows[j].level < level) return data;
            if (rows[j].level === level) return moveRow(data, rowId, j, true);
        }
        return data;
    }
    const next = blockEnd(rows, i);
    if (next >= rows.length || rows[next].level !== level) return data;
    return moveRow(data, rowId, blockEnd(rows, next), true);
}

/** Deep copy of rows (new row/bar ids); dependencies inside the copy are copied too. */
export function cloneRows(rows: PlanRow[], dependencies: PlanDependency[]): { rows: PlanRow[]; dependencies: PlanDependency[] } {
    const barMap = new Map<string, string>();
    const copy = rows.map((row) => ({
        ...row,
        id: newId('r'),
        bars: row.bars.map((bar) => {
            const id = newId('b');
            barMap.set(bar.id, id);
            return { ...bar, id };
        }),
    }));
    const deps = dependencies
        .filter((d) => barMap.has(d.from) && barMap.has(d.to))
        .map((d) => ({ id: newId('d'), from: barMap.get(d.from)!, to: barMap.get(d.to)! }));
    return { rows: copy, dependencies: deps };
}

/** Rows with their sub-rows, in document order (for copy / duplicate). */
export function selectedBlocks(rows: PlanRow[], rowIds: string[]): PlanRow[] {
    return topSelected(rows, new Set(rowIds)).flatMap((i) => rows.slice(i, blockEnd(rows, i)));
}

export function duplicateRows(data: PlanData, rowIds: string[]): { data: PlanData; newIds: string[] } {
    const tops = topSelected(data.rows, new Set(rowIds));
    if (tops.length === 0) return { data, newIds: [] };
    const blocks = selectedBlocks(data.rows, rowIds);
    const { rows, dependencies } = cloneRows(blocks, data.dependencies);
    const at = blockEnd(data.rows, tops[tops.length - 1]);
    const next = insertRows({ ...data, dependencies: [...data.dependencies, ...dependencies] }, at, rows);
    return { data: next, newIds: rows.map((r) => r.id) };
}

export function findBar(data: PlanData, barId: string): { row: PlanRow; bar: PlanBar; rowIndex: number } | null {
    for (let i = 0; i < data.rows.length; i++) {
        const bar = data.rows[i].bars.find((b) => b.id === barId);
        if (bar) return { row: data.rows[i], bar, rowIndex: i };
    }
    return null;
}

/** Insert or replace a bar, optionally moving it to another row. */
export function setBar(data: PlanData, rowId: string, bar: PlanBar): PlanData {
    const normalized: PlanBar = bar.type === 'milestone' ? { ...bar, end: bar.start, progress: undefined } : bar;
    return {
        ...data,
        rows: data.rows.map((row) => {
            const without = row.bars.filter((b) => b.id !== bar.id);
            if (row.id === rowId) {
                const index = row.bars.findIndex((b) => b.id === bar.id);
                const bars = [...without];
                bars.splice(index >= 0 ? index : bars.length, 0, normalized);
                return { ...row, bars };
            }
            return without.length === row.bars.length ? row : { ...row, bars: without };
        }),
    };
}

export function removeBars(data: PlanData, barIds: string[]): PlanData {
    const ids = new Set(barIds);
    return {
        rows: data.rows.map((row) => (row.bars.some((b) => ids.has(b.id)) ? { ...row, bars: row.bars.filter((b) => !ids.has(b.id)) } : row)),
        dependencies: data.dependencies.filter((d) => !ids.has(d.from) && !ids.has(d.to)),
    };
}

export function addDependency(data: PlanData, from: string, to: string): PlanData {
    if (from === to || data.dependencies.some((d) => d.from === from && d.to === to)) return data;
    return { ...data, dependencies: [...data.dependencies, { id: newId('d'), from, to }] };
}

export function removeDependencies(data: PlanData, ids: string[]): PlanData {
    const drop = new Set(ids);
    return { ...data, dependencies: data.dependencies.filter((d) => !drop.has(d.id)) };
}

// ── Roll-ups for the grid ────────────────────────────────────────────────

export interface RowSummary {
    start: number | null;
    end: number | null;
    /** Mon–Fri days between start and end */
    days: number | null;
    progress: number | null;
    isGroup: boolean;
    /** Start / end / duration can be typed in (0 or 1 bar, not a group) */
    editableDates: boolean;
}

export function summarize(rows: PlanRow[], index: number): RowSummary {
    const group = isGroup(rows, index);
    const scope = group ? rows.slice(index, blockEnd(rows, index)) : [rows[index]];
    const bars = scope.flatMap((r) => r.bars);
    if (bars.length === 0) {
        return { start: null, end: null, days: null, progress: null, isGroup: group, editableDates: !group };
    }
    const start = Math.min(...bars.map((b) => toDay(b.start)));
    const end = Math.max(...bars.map((b) => toDay(b.end)));
    const spans = bars.filter((b) => b.type === 'bar');
    const weight = spans.reduce((sum, b) => sum + workingDays(toDay(b.start), toDay(b.end)), 0);
    const progress = spans.length === 0
        ? null
        : weight === 0
            ? Math.round(spans.reduce((sum, b) => sum + (b.progress ?? 0), 0) / spans.length)
            : Math.round(spans.reduce((sum, b) => sum + (b.progress ?? 0) * workingDays(toDay(b.start), toDay(b.end)), 0) / weight);
    return {
        start,
        end,
        days: workingDays(start, end),
        progress,
        isGroup: group,
        editableDates: !group && rows[index].bars.length <= 1,
    };
}

/**
 * Typing a start / end / duration into the grid edits the row's only bar, or creates one.
 */
export function setRowDates(data: PlanData, rowId: string, change: { start?: number; end?: number; days?: number; progress?: number }): PlanData {
    const row = data.rows.find((r) => r.id === rowId);
    if (!row || row.bars.length > 1) return data;
    const existing = row.bars[0];
    let start = existing ? toDay(existing.start) : (change.start ?? change.end ?? null);
    if (start === null) return data;
    let end = existing ? toDay(existing.end) : start;

    if (change.start !== undefined) {
        const length = end - start;
        start = change.start;
        end = existing?.type === 'milestone' ? start : start + length;
    }
    if (change.end !== undefined) {
        end = Math.max(change.end, start);
        if (existing?.type === 'milestone') start = end;
    }
    if (change.days !== undefined && existing?.type !== 'milestone') {
        end = endAfterWorkingDays(start, change.days);
    }

    const bar: PlanBar = {
        ...(existing ?? { id: newId('b'), type: 'bar', color: DEFAULT_COLOR }),
        start: fromDay(start),
        end: fromDay(end),
        ...(change.progress !== undefined ? { progress: Math.max(0, Math.min(100, Math.round(change.progress))) } : {}),
    };
    return setBar(data, rowId, bar);
}
