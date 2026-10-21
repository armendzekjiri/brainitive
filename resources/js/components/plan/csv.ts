import { DEFAULT_COLOR, newId, PlanData, PlanRow, PlanStatus, STATUS_META } from './types';
import { fromDay, toDay } from './dates';
import { normalizeLevels, summarize } from './ops';

/**
 * CSV / TSV in and out: export for Excel, import from Excel or a CSV file, and the text
 * format used when copying rows to the clipboard.
 */

const HEADER = ['Level', 'Name', 'Who', 'Status', 'Start', 'End', 'Working days', 'Progress', 'Type', 'Notes'];

function escape(value: string, delimiter: string): string {
    return /["\n\r]/.test(value) || value.includes(delimiter) ? `"${value.replace(/"/g, '""')}"` : value;
}

function rowValues(rows: PlanRow[], index: number): string[] {
    const row = rows[index];
    const summary = summarize(rows, index);
    const onlyMilestones = row.bars.length > 0 && row.bars.every((b) => b.type === 'milestone');
    return [
        String(row.level),
        row.name,
        row.who ?? '',
        row.status ? STATUS_META[row.status].label : '',
        summary.start !== null ? fromDay(summary.start) : '',
        summary.end !== null ? fromDay(summary.end) : '',
        summary.days !== null ? String(summary.days) : '',
        summary.progress !== null ? String(summary.progress) : '',
        summary.isGroup ? 'Group' : onlyMilestones ? 'Milestone' : row.bars.length > 0 ? 'Task' : '',
        row.notes ?? '',
    ];
}

export function toCsv(data: PlanData): string {
    const lines = [HEADER, ...data.rows.map((_, i) => rowValues(data.rows, i))];
    // BOM so Excel opens UTF-8 (umlauts) correctly
    return '﻿' + lines.map((line) => line.map((v) => escape(v, ',')).join(',')).join('\r\n');
}

/** Tab-separated, Excel pastes this straight into cells. */
export function toTsv(data: PlanData, rows: PlanRow[]): string {
    const indexOf = new Map(data.rows.map((r, i) => [r.id, i]));
    return rows
        .map((row) => rowValues(data.rows, indexOf.get(row.id) ?? 0).map((v) => v.replace(/[\t\r\n]+/g, ' ')))
        .map((values) => [`${'  '.repeat(Number(values[0]))}${values[1]}`, ...values.slice(2)].join('\t'))
        .join('\n');
}

export function parseTable(text: string): string[][] {
    const source = text.replace(/^﻿/, '').replace(/^sep=.\r?\n/i, '');
    const firstLine = source.split(/\r?\n/, 1)[0] ?? '';
    const delimiter = firstLine.includes('\t')
        ? '\t'
        : (firstLine.match(/;/g)?.length ?? 0) > (firstLine.match(/,/g)?.length ?? 0) ? ';' : ',';

    const rows: string[][] = [];
    let row: string[] = [];
    let field = '';
    let quoted = false;
    for (let i = 0; i < source.length; i++) {
        const c = source[i];
        if (quoted) {
            if (c === '"' && source[i + 1] === '"') { field += '"'; i++; }
            else if (c === '"') quoted = false;
            else field += c;
        } else if (c === '"' && field === '') quoted = true;
        else if (c === delimiter) { row.push(field); field = ''; }
        else if (c === '\n' || c === '\r') {
            if (c === '\r' && source[i + 1] === '\n') i++;
            row.push(field); rows.push(row); row = []; field = '';
        } else field += c;
    }
    if (field !== '' || row.length > 0) { row.push(field); rows.push(row); }
    return rows.filter((r) => r.some((v) => v.trim() !== ''));
}

/** 2026-11-02, 02.11.2026, 2.11.26, 02/11/2026 (day first) → day number. */
export function parseDate(value: string): number | null {
    const v = value.trim();
    let m = v.match(/^(\d{4})-(\d{1,2})-(\d{1,2})/);
    if (m) return toDay(`${m[1]}-${m[2].padStart(2, '0')}-${m[3].padStart(2, '0')}`);
    m = v.match(/^(\d{1,2})[./](\d{1,2})[./](\d{2,4})$/);
    if (m) {
        const year = m[3].length === 2 ? `20${m[3]}` : m[3];
        return toDay(`${year}-${m[2].padStart(2, '0')}-${m[1].padStart(2, '0')}`);
    }
    const parsed = Date.parse(`${v} UTC`);
    if (!Number.isNaN(parsed)) return Math.round(parsed / 86_400_000);
    return null;
}

function parseStatus(value: string): PlanStatus | undefined {
    const v = value.trim().toLowerCase().replace(/[\s-]+/g, '_');
    if (!v) return undefined;
    const match = (Object.keys(STATUS_META) as PlanStatus[]).find((key) => key === v || STATUS_META[key].label.toLowerCase().replace(/\s+/g, '_') === v);
    if (match) return match;
    if (['complete', 'completed', 'erledigt', 'fertig'].includes(v)) return 'done';
    if (['open', 'todo', 'to_do', 'offen'].includes(v)) return 'not_started';
    return undefined;
}

const HEADER_ALIASES: Record<string, string[]> = {
    level: ['level', 'outline', 'outline level', 'ebene'],
    name: ['name', 'task', 'task name', 'title', 'aufgabe', 'vorgang', 'vorgangsname'],
    who: ['who', 'owner', 'assignee', 'resource', 'resources', 'resource names', 'wer', 'verantwortlich'],
    status: ['status'],
    start: ['start', 'start date', 'begin', 'anfang', 'beginn'],
    end: ['end', 'end date', 'finish', 'due', 'due date', 'ende'],
    days: ['working days', 'days', 'duration', 'dauer'],
    progress: ['progress', '%', '% complete', 'complete', 'fortschritt'],
    notes: ['notes', 'note', 'description', 'notizen', 'beschreibung'],
};

/**
 * Rows from a table (CSV file or Excel paste). With a header row columns are matched by
 * name; without one the columns are Name, Start, End. Leading spaces indent like an outline.
 */
export function rowsFromTable(table: string[][]): PlanRow[] {
    if (table.length === 0) return [];
    const header = table[0].map((h) => h.trim().toLowerCase());
    const columns: Record<string, number> = {};
    for (const [key, aliases] of Object.entries(HEADER_ALIASES)) {
        const index = header.findIndex((h) => aliases.includes(h));
        if (index >= 0) columns[key] = index;
    }
    const hasHeader = columns.name !== undefined;
    const body = hasHeader ? table.slice(1) : table;
    if (!hasHeader) Object.assign(columns, { name: 0, start: 1, end: 2 });

    const get = (line: string[], key: string) => (columns[key] !== undefined ? line[columns[key]] ?? '' : '');

    const rows = body.map((line): PlanRow => {
        const rawName = get(line, 'name');
        const indent = rawName.match(/^[ \t]*/)?.[0] ?? '';
        const levelValue = Number.parseInt(get(line, 'level'), 10);
        const level = Number.isFinite(levelValue) ? levelValue : Math.floor(indent.replace(/\t/g, '  ').length / 2);
        const start = parseDate(get(line, 'start'));
        let end = parseDate(get(line, 'end'));
        const days = Number.parseInt(get(line, 'days'), 10);
        if (start !== null && end === null && Number.isFinite(days) && days > 0) {
            end = start + Math.max(0, days - 1) + Math.floor((days - 1) / 5) * 2;
        }
        const progress = Number.parseInt(get(line, 'progress').replace('%', ''), 10);
        const bars = start !== null || end !== null
            ? [{
                id: newId('b'),
                start: fromDay(Math.min(start ?? end!, end ?? start!)),
                end: fromDay(Math.max(start ?? end!, end ?? start!)),
                type: (start ?? end) === (end ?? start) && /milestone|meilenstein/i.test(line.join(' ')) ? 'milestone' as const : 'bar' as const,
                color: DEFAULT_COLOR,
                ...(Number.isFinite(progress) ? { progress: Math.max(0, Math.min(100, progress)) } : {}),
            }]
            : [];
        return {
            id: newId('r'),
            name: rawName.trim(),
            level: Math.max(0, level),
            who: get(line, 'who').trim() || undefined,
            status: parseStatus(get(line, 'status')),
            notes: get(line, 'notes').trim() || undefined,
            bars,
        };
    });

    return normalizeLevels(rows);
}

export function downloadFile(filename: string, content: string, type: string): void {
    const blob = new Blob([content], { type });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

