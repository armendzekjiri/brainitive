import { DEFAULT_COLOR, IssueSummary, newId, PlanBar, PlanData, PlanRow, PlanStatus } from './types';

/**
 * Plan rows linked to board issues. The issue is the source of truth: these helpers copy
 * its title, assignee, status, dates and progress onto the row. Edits made in the plan
 * reach the issue through the server on save (PlanIssueSync::writeThrough).
 */

export const STATUS_FROM_CATEGORY: Record<IssueSummary['status_category'], PlanStatus> = {
    todo: 'not_started',
    in_progress: 'in_progress',
    done: 'done',
};

export const CATEGORY_CLASSES: Record<IssueSummary['status_category'], string> = {
    todo: 'bg-muted text-muted-foreground',
    in_progress: 'bg-primary/10 text-primary',
    done: 'bg-brand-green/10 text-brand-green',
};

/** The bars an issue's dates stand for; a due date without a start is a milestone. */
function barsFromIssue(issue: IssueSummary, existing: PlanBar | undefined): PlanBar[] | null {
    const start = issue.start_date;
    const due = issue.due_date;
    if (!start && !due) return null;
    const base = existing ?? { id: newId('b'), type: 'bar' as const, color: DEFAULT_COLOR };
    if (!start && due) return [{ ...base, type: 'milestone', start: due, end: due, progress: undefined }];
    const end = due ?? start!;
    const bar: PlanBar = { ...base, type: 'bar', start: start!, end: end < start! ? start! : end };
    if (issue.progress !== null) bar.progress = issue.progress;
    return [bar];
}

export function rowFromIssue(row: PlanRow, issue: IssueSummary): PlanRow {
    const next: PlanRow = {
        ...row,
        itemId: issue.id,
        name: issue.title,
        // Without an assignee the plan's own "Who" (e.g. "Team") is kept
        who: issue.assignee?.name ?? row.who,
        status: STATUS_FROM_CATEGORY[issue.status_category],
    };
    if (row.bars.length <= 1) {
        const bars = barsFromIssue(issue, row.bars[0]);
        if (bars) next.bars = bars;
        else if (row.bars[0]?.type === 'bar' && issue.progress !== null) next.bars = [{ ...row.bars[0], progress: issue.progress }];
    }
    return next;
}

/** Apply issue data to every linked row; returns the same object when nothing changed. */
export function syncFromIssues(data: PlanData, issues: Record<number, IssueSummary>): PlanData {
    let changed = false;
    const rows = data.rows.map((row) => {
        const issue = row.itemId ? issues[row.itemId] : undefined;
        if (!issue) return row;
        const next = rowFromIssue(row, issue);
        if (JSON.stringify(next) === JSON.stringify(row)) return row;
        changed = true;
        return next;
    });
    return changed ? { ...data, rows } : data;
}

/** New rows for issues added from the board. */
export function rowsFromIssues(issues: IssueSummary[], level: number): PlanRow[] {
    return issues.map((issue) => rowFromIssue({ id: newId('r'), name: issue.title, level, bars: [] }, issue));
}

/** Start / due date a row stands for (same rule as the server). */
export function rowIssueDates(row: PlanRow): { start_date: string | null; due_date: string | null } {
    if (row.bars.length === 0) return { start_date: null, due_date: null };
    if (row.bars.length === 1 && row.bars[0].type === 'milestone') return { start_date: null, due_date: row.bars[0].start };
    return {
        start_date: row.bars.map((b) => b.start).sort()[0],
        due_date: row.bars.map((b) => b.end).sort().reverse()[0],
    };
}

export function unlinkRows(data: PlanData, rowIds: string[]): PlanData {
    const ids = new Set(rowIds);
    return {
        ...data,
        rows: data.rows.map((row) => {
            if (!ids.has(row.id) || row.itemId === undefined) return row;
            const { itemId: _removed, ...rest } = row;
            return rest;
        }),
    };
}
