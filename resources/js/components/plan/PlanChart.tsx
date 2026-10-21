import { ClipboardEvent, KeyboardEvent, PointerEvent as ReactPointerEvent, ReactNode, useCallback, useEffect, useId, useLayoutEffect, useMemo, useRef, useState } from 'react';
import {
    ArrowDown, ArrowUp, CalendarCheck, ChevronDown, ChevronRight, Columns3, Copy, Diamond, Filter, GripVertical,
    IndentDecrease, IndentIncrease, Keyboard, Palette, Pencil, Plus, Redo2, Rows3, Scissors, Search, StickyNote, Trash2, Undo2, Unlink, X,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu, DropdownMenuCheckboxItem, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuRadioGroup,
    DropdownMenuRadioItem, DropdownMenuSeparator, DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { BarDialog } from './BarDialog';
import { ColorSwatches } from './ColorSwatches';
import { ContextMenu, ContextMenuEntry } from './ContextMenu';
import { parseTable, rowsFromTable, toTsv } from './csv';
import { buildScale, formatDate, fromDay, startOfMonth, startOfWeek, TimeScale, toDay, todayDay, isWeekend } from './dates';
import {
    addDependency, blockEnd, cloneRows, deleteRows, duplicateRows, emptyRow, findBar, indentRows, insertRows, isGroup,
    moveRow, moveRowBy, outdentRows, parentIndex, removeBars, removeDependencies, RowSummary, selectedBlocks, setBar,
    setRowDates, summarize, updateRow, visibleRows,
} from './ops';
import {
    COLUMN_META, COLUMN_ORDER, DEFAULT_COLOR, newId, PlanBar, PlanColumn, PlanData, PlanDependency, PlanDocument, PlanRow,
    PlanSettings, PlanStatus, PlanZoom, STATUS_META,
} from './types';
import type { PlanEditor } from './usePlanEditor';

const ROW_H = 34;
const HEADER_TOP = 22;
const HEADER_BOTTOM = 30;
const NUM_W = 44;
const NAME_MIN = 180;
const BAR_TOP = 7;
const BAR_H = ROW_H - BAR_TOP * 2;
const MILESTONE = 16;
// Tints painted over an opaque background, so the sticky grid hides the bars scrolled under it
const ROW_TINT_SELECTED = 'linear-gradient(hsl(var(--primary) / 0.08), hsl(var(--primary) / 0.08))';
const ROW_TINT_GROUP = 'linear-gradient(hsl(var(--muted) / 0.55), hsl(var(--muted) / 0.55))';

type CellKey = 'name' | PlanColumn;

const ZOOMS: { key: PlanZoom; label: string; title: string }[] = [
    { key: 'day', label: 'Day', title: 'Days' },
    { key: 'week', label: 'Week', title: 'Weeks' },
    { key: 'month', label: 'Month', title: 'Months' },
    { key: 'quarter', label: 'Quarter', title: 'Quarters' },
];

const SHORTCUTS: [string, string][] = [
    ['Enter / F2', 'Edit cell (Enter again: next row)'],
    ['Tab / Shift+Tab', 'Indent / outdent rows'],
    ['Alt+↑ / Alt+↓', 'Move row up / down'],
    ['Ctrl+Enter', 'Insert row below'],
    ['Ctrl+D', 'Duplicate rows'],
    ['Ctrl+C / X / V', 'Copy, cut, paste rows (also from Excel)'],
    ['Ctrl+Z / Ctrl+Y', 'Undo / redo'],
    ['Delete', 'Delete selected bar, link or rows'],
    ['Drag on timeline', 'Draw a bar · click = one unit'],
    ['Drag ● at bar end', 'Link to another bar'],
    ['Double-click bar', 'Edit bar'],
];

type Gesture =
    | { kind: 'draw'; rowId: string; anchor: number; barId: string; startX: number; moved: boolean }
    | { kind: 'move'; barId: string; rowId: string; start: number; end: number; startX: number; startY: number; moved: boolean }
    | { kind: 'resize-start' | 'resize-end'; barId: string; rowId: string; start: number; end: number; startX: number }
    | { kind: 'link'; fromBarId: string }
    | { kind: 'row'; rowId: string; startY: number; moved: boolean }
    | { kind: 'splitter'; startX: number; startWidth: number };

interface Props {
    doc: PlanDocument;
    /** Without an editor the chart is read-only (share link, history preview, print). */
    editor?: PlanEditor | null;
    members?: { id: number; name: string }[];
    printMode?: boolean;
    showToolbar?: boolean;
    toolbarExtra?: ReactNode;
    className?: string;
}

/**
 * Tom's Planner–style chart: an outline grid on the left (sticky) and the Gantt timeline on
 * the right, in one scroll container so both scroll together.
 */
export function PlanChart({ doc, editor, members = [], printMode = false, showToolbar = true, toolbarExtra, className }: Props) {
    const readOnly = !editor;
    const uid = useId().replace(/:/g, '');
    const [localSettings, setLocalSettings] = useState<PlanSettings>(doc.settings);
    const settings = readOnly ? localSettings : doc.settings;
    const data = doc.data;
    const rows = data.rows;

    // ── State ─────────────────────────────────────────────────────────
    const [selectedRows, setSelectedRows] = useState<string[]>([]);
    const [selectionMode, setSelectionMode] = useState<'cells' | 'rows'>('cells');
    const [anchorRow, setAnchorRow] = useState<string | null>(null);
    const [activeCell, setActiveCell] = useState<{ rowId: string; col: CellKey } | null>(null);
    const [editing, setEditing] = useState<{ rowId: string; col: CellKey; initial: string } | null>(null);
    const [selectedBar, setSelectedBar] = useState<string | null>(null);
    const [selectedDep, setSelectedDep] = useState<string | null>(null);
    const [barDialog, setBarDialog] = useState<string | null>(null);
    const [menu, setMenu] = useState<{ x: number; y: number; entries: ContextMenuEntry[] } | null>(null);
    const [query, setQuery] = useState('');
    const [statusFilter, setStatusFilter] = useState<PlanStatus | 'all' | 'none'>('all');
    const [whoFilter, setWhoFilter] = useState<string>('all');
    const [lastColor, setLastColor] = useState(DEFAULT_COLOR);
    const [viewport, setViewport] = useState(1200);
    const [gestureActive, setGestureActive] = useState(false);
    const [dropIndex, setDropIndex] = useState<number | null>(null);
    const [linkDraft, setLinkDraft] = useState<{ x1: number; y1: number; x2: number; y2: number } | null>(null);
    const [hoverDep, setHoverDep] = useState<string | null>(null);

    const rootRef = useRef<HTMLDivElement>(null);
    const scrollerRef = useRef<HTMLDivElement>(null);
    const bodyRef = useRef<HTMLDivElement>(null);
    const gesture = useRef<Gesture | null>(null);
    const clipboard = useRef<{ text: string; rows: PlanRow[]; dependencies: PlanDependency[] } | null>(null);
    /** Row added by Enter on the last row; removed again if it is left empty. */
    const autoRow = useRef<string | null>(null);
    const pendingCenter = useRef<number | null>(null);
    const scrolledInitially = useRef(false);

    // ── Mutations ─────────────────────────────────────────────────────
    const mutate = useCallback((fn: (d: PlanData) => PlanData) => {
        editor?.apply((current) => {
            const next = fn(current.data);
            return next === current.data ? current : { ...current, data: next };
        });
    }, [editor]);

    const setSettings = useCallback((patch: Partial<PlanSettings>) => {
        if (!editor) {
            setLocalSettings((s) => ({ ...s, ...patch }));
            return;
        }
        editor.apply((current) => ({ ...current, settings: { ...current.settings, ...patch } }), { history: false });
    }, [editor]);

    // ── Derived ───────────────────────────────────────────────────────
    const filtering = query.trim() !== '' || statusFilter !== 'all' || whoFilter !== 'all';

    const shown = useMemo(() => {
        if (!filtering) return visibleRows(rows);
        const q = query.trim().toLowerCase();
        const matches = (row: PlanRow) =>
            (!q || [row.name, row.who, row.notes].some((v) => v?.toLowerCase().includes(q)))
            && (statusFilter === 'all' || (statusFilter === 'none' ? !row.status : row.status === statusFilter))
            && (whoFilter === 'all' || (row.who ?? '') === whoFilter);
        const keep = new Set<number>();
        rows.forEach((row, i) => {
            if (!matches(row)) return;
            keep.add(i);
            for (let p = parentIndex(rows, i); p >= 0; p = parentIndex(rows, p)) keep.add(p);
        });
        return rows.map((row, index) => ({ row, index })).filter(({ index }) => keep.has(index));
    }, [rows, filtering, query, statusFilter, whoFilter]);

    // Read-only viewers can fold groups locally
    const [localCollapsed, setLocalCollapsed] = useState<Record<string, boolean>>({});
    const effectiveShown = useMemo(() => {
        if (!readOnly || Object.keys(localCollapsed).length === 0 || filtering) return shown;
        const overridden = rows.map((r) => (r.id in localCollapsed ? { ...r, collapsed: localCollapsed[r.id] || undefined } : r));
        return visibleRows(overridden).map(({ index }) => ({ row: rows[index], index }));
    }, [readOnly, localCollapsed, filtering, shown, rows]);
    const isCollapsed = (row: PlanRow) => (readOnly && row.id in localCollapsed ? localCollapsed[row.id] : !!row.collapsed);
    const summaries = useMemo(() => rows.map((_, i) => summarize(rows, i)), [rows]);
    const visibleIndex = useMemo(() => new Map(effectiveShown.map(({ row }, i) => [row.id, i])), [effectiveShown]);
    const whoOptions = useMemo(() => [...new Set(rows.map((r) => r.who).filter((w): w is string => !!w))].sort(), [rows]);
    const memberNames = useMemo(() => [...new Set([...members.map((m) => m.name), ...whoOptions])], [members, whoOptions]);

    const compact = viewport < 768;
    const columns = compact ? [] : COLUMN_ORDER.filter((c) => settings.columns[c]);
    const columnsWidth = columns.reduce((sum, c) => sum + COLUMN_META[c].width, 0);
    const gridTarget = compact ? Math.max(200, Math.round(viewport * 0.45)) : settings.gridWidth;
    const nameWidth = Math.max(compact ? 140 : NAME_MIN, gridTarget - NUM_W - columnsWidth);
    const gridW = NUM_W + nameWidth + columnsWidth;

    const liveScale = useMemo(() => buildScale(data, settings.zoom, printMode ? 0 : Math.max(0, viewport - gridW), printMode), [data, settings.zoom, viewport, gridW, printMode]);
    const frozenScale = useRef<TimeScale>(liveScale);
    const scale = gestureActive ? frozenScale.current : liveScale;
    frozenScale.current = scale;
    const today = todayDay();

    const cellsOrder: CellKey[] = ['name', ...columns];

    const barGeometry = useCallback((bar: PlanBar) => {
        const s = toDay(bar.start);
        if (bar.type === 'milestone') {
            const cx = scale.x(s) + scale.pxPerDay / 2;
            return { x1: cx - MILESTONE / 2, x2: cx + MILESTONE / 2, cx };
        }
        const x1 = scale.x(s);
        const x2 = scale.x(toDay(bar.end) + 1);
        return { x1, x2: Math.max(x2, x1 + 4), cx: (x1 + x2) / 2 };
    }, [scale]);

    const barPositions = useMemo(() => {
        const map = new Map<string, { x1: number; x2: number; y: number }>();
        effectiveShown.forEach(({ row }, i) => {
            for (const bar of row.bars) {
                const g = barGeometry(bar);
                map.set(bar.id, { x1: g.x1, x2: g.x2, y: i * ROW_H + ROW_H / 2 });
            }
        });
        return map;
    }, [effectiveShown, barGeometry]);

    // ── Viewport + scrolling ──────────────────────────────────────────
    useLayoutEffect(() => {
        const el = scrollerRef.current;
        if (!el) return;
        const observer = new ResizeObserver(() => setViewport(el.clientWidth));
        observer.observe(el);
        setViewport(el.clientWidth);
        return () => observer.disconnect();
    }, []);

    const scrollToDay = useCallback((day: number, align: 'start' | 'center' = 'start') => {
        const el = scrollerRef.current;
        if (!el) return;
        const visible = el.clientWidth - gridW;
        const x = scale.x(day);
        el.scrollLeft = Math.max(0, align === 'center' ? x - visible / 2 : x - Math.min(160, visible / 4));
    }, [gridW, scale]);

    useLayoutEffect(() => {
        if (pendingCenter.current !== null) {
            scrollToDay(pendingCenter.current, 'center');
            pendingCenter.current = null;
            return;
        }
        if (scrolledInitially.current || printMode) return;
        scrolledInitially.current = true;
        const bars = rows.flatMap((r) => r.bars);
        const first = bars.length ? Math.min(...bars.map((b) => toDay(b.start))) : today;
        const last = bars.length ? Math.max(...bars.map((b) => toDay(b.end))) : today;
        scrollToDay(today >= first && today <= last ? today : first);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [scale]);

    const changeZoom = (zoom: PlanZoom) => {
        const el = scrollerRef.current;
        if (el) pendingCenter.current = scale.dayAt(el.scrollLeft + (el.clientWidth - gridW) / 2);
        setSettings({ zoom });
    };

    // ── Selection helpers ─────────────────────────────────────────────
    const focusRoot = () => rootRef.current?.focus({ preventScroll: true });

    const selectRow = (rowId: string, event?: { shiftKey?: boolean; metaKey?: boolean; ctrlKey?: boolean }, mode: 'cells' | 'rows' = 'rows') => {
        setSelectionMode(mode);
        setSelectedBar(null);
        setSelectedDep(null);
        if (event?.shiftKey && anchorRow) {
            const a = visibleIndex.get(anchorRow) ?? 0;
            const b = visibleIndex.get(rowId) ?? 0;
            setSelectedRows(effectiveShown.slice(Math.min(a, b), Math.max(a, b) + 1).map(({ row }) => row.id));
            return;
        }
        if (event?.metaKey || event?.ctrlKey) {
            setSelectedRows((ids) => (ids.includes(rowId) ? ids.filter((id) => id !== rowId) : [...ids, rowId]));
            setAnchorRow(rowId);
            return;
        }
        setSelectedRows([rowId]);
        setAnchorRow(rowId);
    };

    const actionRows = (): string[] => {
        if (selectedRows.length) return selectedRows;
        if (activeCell) return [activeCell.rowId];
        if (selectedBar) {
            const hit = findBar(data, selectedBar);
            if (hit) return [hit.row.id];
        }
        return [];
    };

    const clearSelection = () => {
        setSelectedRows([]);
        setSelectedBar(null);
        setSelectedDep(null);
        setActiveCell(null);
    };

    // ── Row actions ───────────────────────────────────────────────────
    const insertRowAt = (index: number, level: number, edit = true, row?: PlanRow) => {
        const created = row ?? emptyRow(level);
        mutate((d) => insertRows(d, index, [created]));
        setSelectedRows([created.id]);
        setAnchorRow(created.id);
        setSelectionMode('cells');
        setActiveCell({ rowId: created.id, col: 'name' });
        if (edit) setEditing({ rowId: created.id, col: 'name', initial: created.name });
        return created;
    };

    const insertBelow = (rowId?: string) => {
        const index = rowId ? rows.findIndex((r) => r.id === rowId) : -1;
        if (index < 0) return insertRowAt(rows.length, rows[rows.length - 1]?.level ?? 0);
        const row = rows[index];
        const childLevel = isGroup(rows, index) && !row.collapsed ? row.level + 1 : row.level;
        const at = isGroup(rows, index) && !row.collapsed ? index + 1 : blockEnd(rows, index);
        return insertRowAt(at, childLevel);
    };

    const insertAbove = (rowId: string) => {
        const index = rows.findIndex((r) => r.id === rowId);
        if (index >= 0) insertRowAt(index, rows[index].level);
    };

    const addSubRow = (rowId: string) => {
        const index = rows.findIndex((r) => r.id === rowId);
        if (index < 0) return;
        mutate((d) => updateRow(d, rowId, { collapsed: undefined }));
        insertRowAt(blockEnd(rows, index), rows[index].level + 1);
    };

    const addGroup = () => {
        const group = { ...emptyRow(0, 'New group') };
        const child = emptyRow(1);
        mutate((d) => insertRows(d, d.rows.length, [group, child]));
        setSelectedRows([group.id]);
        setActiveCell({ rowId: group.id, col: 'name' });
        setEditing({ rowId: group.id, col: 'name', initial: group.name });
    };

    const addMilestoneRow = () => {
        const row: PlanRow = { ...emptyRow(rows[rows.length - 1]?.level ?? 0, 'Milestone'), bars: [{ id: newId('m'), start: fromDay(today), end: fromDay(today), type: 'milestone', color: '#EF4444' }] };
        insertRowAt(rows.length, row.level, true, row);
    };

    const removeRows = (ids: string[]) => {
        if (!ids.length) return;
        const position = visibleIndex.get(ids[0]) ?? 0;
        mutate((d) => deleteRows(d, ids));
        const next = effectiveShown.filter(({ row }) => !ids.includes(row.id))[Math.max(0, position - 1)]?.row;
        setSelectedRows(next ? [next.id] : []);
        setActiveCell(next ? { rowId: next.id, col: activeCell?.col ?? 'name' } : null);
    };

    const duplicate = (ids: string[]) => {
        if (!ids.length || !editor) return;
        let created: string[] = [];
        mutate((d) => {
            const result = duplicateRows(d, ids);
            created = result.newIds;
            return result.data;
        });
        if (created.length) setSelectedRows([created[0]]);
    };

    const toggleCollapse = (rowId: string) => {
        const row = rows.find((r) => r.id === rowId);
        if (!row) return;
        if (readOnly) {
            setLocalCollapsed((c) => ({ ...c, [rowId]: !(c[rowId] ?? row.collapsed) }));
            return;
        }
        editor?.apply((current) => ({ ...current, data: updateRow(current.data, rowId, { collapsed: row.collapsed ? undefined : true }) }), { history: false });
    };

    // ── Bars ──────────────────────────────────────────────────────────
    const unitSpan = (day: number): [number, number] => {
        switch (settings.zoom) {
            case 'day': return [day, day];
            case 'week': { const s = startOfWeek(day); return [s, s + 4]; }
            default: { const s = startOfMonth(day); return [s, startOfMonth(s + 32) - 1]; }
        }
    };

    const applyColor = (color: string) => {
        setLastColor(color);
        if (selectedBar) {
            const hit = findBar(data, selectedBar);
            if (hit) mutate((d) => setBar(d, hit.row.id, { ...hit.bar, color }));
            return;
        }
        const ids = new Set(actionRows());
        mutate((d) => ({ ...d, rows: d.rows.map((r) => (ids.has(r.id) ? { ...r, bars: r.bars.map((b) => ({ ...b, color })) } : r)) }));
    };

    const addMilestoneTo = (rowId: string) => {
        const index = rows.findIndex((r) => r.id === rowId);
        if (index < 0) return;
        const day = summaries[index]?.end ?? today;
        const bar: PlanBar = { id: newId('m'), start: fromDay(day), end: fromDay(day), type: 'milestone', color: '#EF4444' };
        mutate((d) => setBar(d, rowId, bar));
        setSelectedBar(bar.id);
    };

    const toggleMilestone = () => {
        if (selectedBar) {
            const hit = findBar(data, selectedBar);
            if (hit) mutate((d) => setBar(d, hit.row.id, { ...hit.bar, type: hit.bar.type === 'milestone' ? 'bar' : 'milestone' }));
            return;
        }
        const rowId = actionRows()[0];
        if (!rowId) return addMilestoneRow();
        addMilestoneTo(rowId);
    };

    const deleteSelection = () => {
        if (selectedDep) {
            mutate((d) => removeDependencies(d, [selectedDep]));
            setSelectedDep(null);
        } else if (selectedBar) {
            mutate((d) => removeBars(d, [selectedBar]));
            setSelectedBar(null);
        } else {
            removeRows(actionRows());
        }
    };

    // ── Clipboard ─────────────────────────────────────────────────────
    const copyRows = (event?: ClipboardEvent) => {
        const ids = actionRows();
        if (!ids.length) return false;
        const blocks = selectedBlocks(rows, ids);
        const text = toTsv(data, blocks);
        clipboard.current = { text, rows: blocks, dependencies: data.dependencies };
        if (event) event.clipboardData.setData('text/plain', text);
        else void navigator.clipboard?.writeText(text).catch(() => {});
        return true;
    };

    const pasteText = (text: string) => {
        if (!editor || !text.trim()) return;
        const internal = clipboard.current && clipboard.current.text === text ? clipboard.current : null;
        const pasted = internal ? cloneRows(internal.rows, internal.dependencies) : { rows: rowsFromTable(parseTable(text)), dependencies: [] };
        if (!pasted.rows.length) return;
        // Below the last selected row (with its sub-rows), like pasting in a spreadsheet
        const selectedIds = new Set(actionRows());
        const index = rows.reduce((last, r, i) => (selectedIds.has(r.id) ? i : last), -1);
        const at = index >= 0 ? blockEnd(rows, index) : rows.length;
        const baseLevel = index >= 0 ? rows[index].level : 0;
        const shift = baseLevel - Math.min(...pasted.rows.map((r) => r.level));
        const placed = pasted.rows.map((r) => ({ ...r, level: Math.max(0, r.level + shift) }));
        mutate((d) => insertRows({ ...d, dependencies: [...d.dependencies, ...pasted.dependencies] }, at, placed));
        setSelectedRows(placed.map((r) => r.id));
        setSelectionMode('rows');
    };

    // ── Cell editing ──────────────────────────────────────────────────
    const cellValue = (row: PlanRow, summary: RowSummary, col: CellKey): string => {
        switch (col) {
            case 'name': return row.name;
            case 'who': return row.who ?? '';
            case 'status': return row.status ?? '';
            case 'notes': return row.notes ?? '';
            case 'start': return summary.start !== null ? fromDay(summary.start) : '';
            case 'end': return summary.end !== null ? fromDay(summary.end) : '';
            case 'duration': return summary.days !== null ? String(summary.days) : '';
            case 'progress': return summary.progress !== null ? String(summary.progress) : '';
        }
    };

    const isEditable = (rowIndex: number, col: CellKey) => {
        if (readOnly) return false;
        if (col === 'start' || col === 'end' || col === 'duration' || col === 'progress') {
            const s = summaries[rowIndex];
            if (!s?.editableDates) return false;
            if (col === 'progress' || col === 'duration') return rows[rowIndex].bars[0]?.type !== 'milestone' && s.start !== null;
        }
        return true;
    };

    const commitCell = (rowId: string, col: CellKey, value: string) => {
        const v = value.trim();
        switch (col) {
            case 'name': mutate((d) => updateRow(d, rowId, { name: value.replace(/\s+$/, '') })); break;
            case 'who': mutate((d) => updateRow(d, rowId, { who: v || undefined })); break;
            case 'notes': mutate((d) => updateRow(d, rowId, { notes: v || undefined })); break;
            case 'status': mutate((d) => updateRow(d, rowId, { status: (v || undefined) as PlanStatus | undefined })); break;
            case 'start': if (/^\d{4}-\d{2}-\d{2}$/.test(v)) mutate((d) => setRowDates(d, rowId, { start: toDay(v) })); break;
            case 'end': if (/^\d{4}-\d{2}-\d{2}$/.test(v)) mutate((d) => setRowDates(d, rowId, { end: toDay(v) })); break;
            case 'duration': { const n = Number.parseInt(v, 10); if (n > 0) mutate((d) => setRowDates(d, rowId, { days: Math.min(n, 3650) })); break; }
            case 'progress': { const n = Number.parseInt(v.replace('%', ''), 10); if (Number.isFinite(n)) mutate((d) => setRowDates(d, rowId, { progress: n })); break; }
        }
    };

    const moveActive = (dRow: number, dCol: number, extend = false) => {
        if (!effectiveShown.length) return;
        const current = activeCell ?? { rowId: effectiveShown[0].row.id, col: 'name' as CellKey };
        const r = Math.max(0, Math.min(effectiveShown.length - 1, (visibleIndex.get(current.rowId) ?? 0) + dRow));
        const c = Math.max(0, Math.min(cellsOrder.length - 1, cellsOrder.indexOf(current.col) + dCol));
        const rowId = effectiveShown[r].row.id;
        setActiveCell({ rowId, col: cellsOrder[c] });
        if (extend) selectRow(rowId, { shiftKey: true }, 'rows');
        else {
            setSelectedRows([rowId]);
            setAnchorRow(rowId);
            setSelectionMode('cells');
        }
        setSelectedBar(null);
        setSelectedDep(null);
        // keep the row in view
        const el = scrollerRef.current;
        if (el) {
            const top = r * ROW_H;
            const header = HEADER_TOP + HEADER_BOTTOM;
            if (top < el.scrollTop) el.scrollTop = top;
            else if (top + ROW_H > el.scrollTop + el.clientHeight - header) el.scrollTop = top + ROW_H - el.clientHeight + header;
        }
    };

    const startEditing = (rowId: string, col: CellKey, initial?: string) => {
        const index = rows.findIndex((r) => r.id === rowId);
        if (index < 0 || !isEditable(index, col)) return;
        setActiveCell({ rowId, col });
        setEditing({ rowId, col, initial: initial ?? cellValue(rows[index], summaries[index], col) });
    };

    const finishEditing = (value: string | null, move: 'down' | 'right' | 'left' | null) => {
        const current = editing;
        if (!current) return;
        setEditing(null);
        const isAutoRow = autoRow.current === current.rowId;
        autoRow.current = null;
        if (isAutoRow && current.col === 'name' && (value ?? '').trim() === '') {
            const position = visibleIndex.get(current.rowId) ?? 0;
            mutate((d) => deleteRows(d, [current.rowId]));
            const previous = effectiveShown[position - 1]?.row;
            setSelectedRows(previous ? [previous.id] : []);
            setActiveCell(previous ? { rowId: previous.id, col: 'name' } : null);
            focusRoot();
            return;
        }
        if (value !== null) commitCell(current.rowId, current.col, value);
        focusRoot();
        if (move === 'down') {
            const position = visibleIndex.get(current.rowId) ?? 0;
            if (current.col === 'name' && position === effectiveShown.length - 1 && value !== null && value.trim() !== '') {
                autoRow.current = insertBelow(current.rowId).id;
                return;
            }
            moveActive(1, 0);
        } else if (move === 'right') moveActive(0, 1);
        else if (move === 'left') moveActive(0, -1);
    };

    // ── Keyboard ──────────────────────────────────────────────────────
    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        const target = event.target as HTMLElement;
        if (target.closest('input, textarea, select, [contenteditable="true"], [role="menu"], [role="dialog"]')) return;
        const mod = event.metaKey || event.ctrlKey;
        const key = event.key;

        if (mod && key.toLowerCase() === 'z' && !readOnly) { event.preventDefault(); event.shiftKey ? editor?.redo() : editor?.undo(); return; }
        if (mod && key.toLowerCase() === 'y' && !readOnly) { event.preventDefault(); editor?.redo(); return; }
        if (key === 'Escape') { clearSelection(); return; }
        if (key === 'ArrowDown' && event.altKey && !readOnly) { event.preventDefault(); actionRows().slice(0, 1).forEach((id) => mutate((d) => moveRowBy(d, id, 1))); return; }
        if (key === 'ArrowUp' && event.altKey && !readOnly) { event.preventDefault(); actionRows().slice(0, 1).forEach((id) => mutate((d) => moveRowBy(d, id, -1))); return; }
        if (key === 'ArrowDown') { event.preventDefault(); moveActive(1, 0, event.shiftKey); return; }
        if (key === 'ArrowUp') { event.preventDefault(); moveActive(-1, 0, event.shiftKey); return; }
        if (key === 'ArrowRight') { event.preventDefault(); moveActive(0, 1); return; }
        if (key === 'ArrowLeft') { event.preventDefault(); moveActive(0, -1); return; }
        if (readOnly) return;

        if (key === 'Tab') { event.preventDefault(); const ids = actionRows(); mutate((d) => (event.shiftKey ? outdentRows(d, ids) : indentRows(d, ids))); return; }
        if (key === 'Enter' && mod) { event.preventDefault(); insertBelow(actionRows()[0]); return; }
        if (mod && key.toLowerCase() === 'd') { event.preventDefault(); duplicate(actionRows()); return; }
        if ((key === 'Enter' || key === 'F2') && activeCell) { event.preventDefault(); startEditing(activeCell.rowId, activeCell.col); return; }
        if (key === 'Delete' || key === 'Backspace') {
            event.preventDefault();
            if (selectedBar || selectedDep || selectionMode === 'rows') deleteSelection();
            else if (activeCell) {
                const col = activeCell.col;
                if (['name', 'who', 'notes', 'status'].includes(col)) commitCell(activeCell.rowId, col, '');
            }
            return;
        }
        if (activeCell && !mod && !event.altKey && key.length === 1 && ['name', 'who', 'notes', 'duration', 'progress'].includes(activeCell.col)) {
            event.preventDefault();
            startEditing(activeCell.rowId, activeCell.col, key);
        }
    };

    // ── Pointer gestures on the timeline ──────────────────────────────
    const bodyPoint = (clientX: number, clientY: number) => {
        const rect = bodyRef.current!.getBoundingClientRect();
        return { x: clientX - rect.left - gridW, y: clientY - rect.top };
    };

    const endGesture = (commit: boolean) => {
        window.removeEventListener('pointermove', listeners.move);
        window.removeEventListener('pointerup', listeners.up);
        window.removeEventListener('pointercancel', listeners.cancel);
        gesture.current = null;
        setGestureActive(false);
        setDropIndex(null);
        setLinkDraft(null);
        document.body.style.cursor = '';
        if (commit) editor?.endGesture();
        else editor?.cancelGesture();
    };

    // Handlers read the latest render through a ref
    const latest = useRef({ scale, shown: effectiveShown, rows, data, gridW, lastColor });
    latest.current = { scale, shown: effectiveShown, rows, data, gridW, lastColor };

    function onWindowMove(event: PointerEvent) {
        const g = gesture.current;
        if (!g || !bodyRef.current) return;
        const { scale: sc, shown: list } = latest.current;
        const p = bodyPoint(event.clientX, event.clientY);
        const day = sc.dayAt(p.x);

        if (g.kind === 'splitter') {
            const width = Math.max(260, Math.min(1400, g.startWidth + event.clientX - g.startX));
            editor?.apply((current) => ({ ...current, settings: { ...current.settings, gridWidth: Math.round(width) } }), { history: false });
            return;
        }
        if (g.kind === 'row') {
            if (Math.abs(event.clientY - g.startY) > 4) g.moved = true;
            setDropIndex(Math.max(0, Math.min(list.length, Math.round(p.y / ROW_H))));
            return;
        }
        if (g.kind === 'link') {
            const from = barPositionsRef.current.get(g.fromBarId);
            if (from) setLinkDraft({ x1: from.x2, y1: from.y, x2: p.x, y2: p.y });
            return;
        }
        if (g.kind === 'draw') {
            if (!g.moved && Math.abs(event.clientX - g.startX) < 4) return;
            g.moved = true;
            const [s, e] = [Math.min(g.anchor, day), Math.max(g.anchor, day)];
            mutate((d) => setBar(d, g.rowId, { id: g.barId, start: fromDay(s), end: fromDay(e), type: 'bar', color: latest.current.lastColor }));
            return;
        }
        const hit = findBar(latest.current.data, g.barId);
        if (!hit) return;
        const delta = Math.round((event.clientX - g.startX) / sc.pxPerDay);
        if (g.kind === 'move') {
            if (!g.moved && Math.abs(event.clientX - g.startX) < 3 && Math.abs(event.clientY - g.startY) < 3) return;
            g.moved = true;
            const targetIndex = Math.max(0, Math.min(list.length - 1, Math.floor(p.y / ROW_H)));
            const targetRow = Math.abs(event.clientY - g.startY) > ROW_H / 2 ? list[targetIndex]?.row.id ?? g.rowId : g.rowId;
            mutate((d) => setBar(d, targetRow, { ...hit.bar, start: fromDay(g.start + delta), end: fromDay(g.end + delta) }));
            return;
        }
        if (g.kind === 'resize-start') {
            mutate((d) => setBar(d, hit.row.id, { ...hit.bar, start: fromDay(Math.min(g.start + delta, g.end)) }));
        } else {
            mutate((d) => setBar(d, hit.row.id, { ...hit.bar, end: fromDay(Math.max(g.end + delta, g.start)) }));
        }
    }

    function onWindowUp(event: PointerEvent) {
        const g = gesture.current;
        if (!g) return;
        if (g.kind === 'draw' && !g.moved) {
            const day = latest.current.scale.dayAt(bodyPoint(event.clientX, event.clientY).x);
            const [s, e] = unitSpan(day);
            mutate((d) => setBar(d, g.rowId, { id: g.barId, start: fromDay(s), end: fromDay(e), type: 'bar', color: latest.current.lastColor }));
            setSelectedBar(g.barId);
        } else if (g.kind === 'draw') {
            setSelectedBar(g.barId);
        } else if (g.kind === 'row' && g.moved) {
            const list = latest.current.shown;
            const p = bodyPoint(event.clientX, event.clientY);
            const target = Math.max(0, Math.min(list.length, Math.round(p.y / ROW_H)));
            const toIndex = target >= list.length ? latest.current.rows.length : list[target].index;
            mutate((d) => moveRow(d, g.rowId, toIndex));
        } else if (g.kind === 'link') {
            const el = document.elementFromPoint(event.clientX, event.clientY)?.closest('[data-bar-id]');
            const to = el?.getAttribute('data-bar-id');
            if (to && to !== g.fromBarId) mutate((d) => addDependency(d, g.fromBarId, to));
        }
        endGesture(true);
    }

    function onWindowCancel() {
        endGesture(false);
    }

    const barPositionsRef = useRef(barPositions);
    barPositionsRef.current = barPositions;

    // Window listeners stay the same functions for the whole gesture and call the latest handlers
    const handlers = useRef({ move: onWindowMove, up: onWindowUp, cancel: onWindowCancel });
    handlers.current = { move: onWindowMove, up: onWindowUp, cancel: onWindowCancel };
    const listeners = useRef({
        move: (event: PointerEvent) => handlers.current.move(event),
        up: (event: PointerEvent) => handlers.current.up(event),
        cancel: () => handlers.current.cancel(),
    }).current;

    const beginGesture = (g: Gesture, cursor = '') => {
        gesture.current = g;
        frozenScale.current = scale;
        setGestureActive(true);
        editor?.beginGesture();
        document.body.style.cursor = cursor;
        window.addEventListener('pointermove', listeners.move);
        window.addEventListener('pointerup', listeners.up);
        window.addEventListener('pointercancel', listeners.cancel);
    };

    useEffect(() => () => {
        window.removeEventListener('pointermove', listeners.move);
        window.removeEventListener('pointerup', listeners.up);
        window.removeEventListener('pointercancel', listeners.cancel);
    }, [listeners]);

    const onTimelinePointerDown = (event: ReactPointerEvent, row: PlanRow) => {
        focusRoot();
        setMenu(null);
        if (event.button !== 0) return;
        const target = event.target as HTMLElement;
        const barEl = target.closest('[data-bar-id]');
        const barId = barEl?.getAttribute('data-bar-id') ?? null;
        setSelectedDep(null);

        if (barId) {
            setSelectedBar(barId);
            setSelectedRows([row.id]);
            setSelectionMode('cells');
            if (readOnly) return;
            event.preventDefault();
            const hit = findBar(data, barId);
            if (!hit) return;
            const handle = target.closest('[data-handle]')?.getAttribute('data-handle');
            const start = toDay(hit.bar.start);
            const end = toDay(hit.bar.end);
            if (handle === 'link') beginGesture({ kind: 'link', fromBarId: barId }, 'crosshair');
            else if (handle === 'start') beginGesture({ kind: 'resize-start', barId, rowId: row.id, start, end, startX: event.clientX }, 'ew-resize');
            else if (handle === 'end') beginGesture({ kind: 'resize-end', barId, rowId: row.id, start, end, startX: event.clientX }, 'ew-resize');
            else beginGesture({ kind: 'move', barId, rowId: row.id, start, end, startX: event.clientX, startY: event.clientY, moved: false }, 'grabbing');
            return;
        }

        setSelectedBar(null);
        setSelectedRows([row.id]);
        setAnchorRow(row.id);
        setSelectionMode('cells');
        if (readOnly) return;
        event.preventDefault();
        const anchor = scale.dayAt(bodyPoint(event.clientX, event.clientY).x);
        beginGesture({ kind: 'draw', rowId: row.id, anchor, barId: newId('b'), startX: event.clientX, moved: false }, 'col-resize');
    };

    // ── Context menus ─────────────────────────────────────────────────
    const openRowMenu = (event: React.MouseEvent, row: PlanRow) => {
        event.preventDefault();
        if (!selectedRows.includes(row.id)) selectRow(row.id, undefined, 'rows');
        const ids = selectedRows.includes(row.id) ? selectedRows : [row.id];
        if (readOnly) return;
        setMenu({
            x: event.clientX,
            y: event.clientY,
            entries: [
                { label: 'Insert row above', icon: <ArrowUp />, onSelect: () => insertAbove(row.id) },
                { label: 'Insert row below', icon: <ArrowDown />, shortcut: 'Ctrl+↵', onSelect: () => insertBelow(row.id) },
                { label: 'Add sub-row', icon: <Rows3 />, onSelect: () => addSubRow(row.id) },
                'separator',
                { label: 'Indent', icon: <IndentIncrease />, shortcut: 'Tab', onSelect: () => mutate((d) => indentRows(d, ids)) },
                { label: 'Outdent', icon: <IndentDecrease />, shortcut: '⇧Tab', onSelect: () => mutate((d) => outdentRows(d, ids)) },
                { label: 'Move up', icon: <ArrowUp />, shortcut: 'Alt+↑', onSelect: () => mutate((d) => moveRowBy(d, row.id, -1)) },
                { label: 'Move down', icon: <ArrowDown />, shortcut: 'Alt+↓', onSelect: () => mutate((d) => moveRowBy(d, row.id, 1)) },
                'separator',
                { label: 'Add milestone', icon: <Diamond />, onSelect: () => addMilestoneTo(row.id) },
                { label: 'Duplicate', icon: <Copy />, shortcut: 'Ctrl+D', onSelect: () => duplicate(ids) },
                { label: 'Copy', icon: <Copy />, shortcut: 'Ctrl+C', onSelect: () => { copyRows(); } },
                'separator',
                { label: ids.length > 1 ? `Delete ${ids.length} rows` : 'Delete row', icon: <Trash2 />, destructive: true, onSelect: () => removeRows(ids) },
            ],
        });
    };

    const openBarMenu = (event: React.MouseEvent, row: PlanRow, bar: PlanBar) => {
        event.preventDefault();
        event.stopPropagation();
        setSelectedBar(bar.id);
        if (readOnly) return;
        const links = data.dependencies.filter((d) => d.from === bar.id || d.to === bar.id);
        setMenu({
            x: event.clientX,
            y: event.clientY,
            entries: [
                { label: 'Edit…', icon: <Pencil />, onSelect: () => setBarDialog(bar.id) },
                { custom: <div className="px-2 py-1.5"><ColorSwatches value={bar.color} onChange={(color) => { setLastColor(color); mutate((d) => setBar(d, row.id, { ...bar, color })); setMenu(null); }} /></div> },
                { label: bar.type === 'milestone' ? 'Convert to bar' : 'Convert to milestone', icon: <Diamond />, onSelect: () => mutate((d) => setBar(d, row.id, { ...bar, type: bar.type === 'milestone' ? 'bar' : 'milestone' })) },
                { label: 'Remove links', icon: <Unlink />, disabled: links.length === 0, onSelect: () => mutate((d) => removeDependencies(d, links.map((l) => l.id))) },
                'separator',
                { label: 'Delete', icon: <Trash2 />, destructive: true, shortcut: 'Del', onSelect: () => { mutate((d) => removeBars(d, [bar.id])); setSelectedBar(null); } },
            ],
        });
    };

    // ── Render pieces ─────────────────────────────────────────────────
    const statusMeta = (status?: PlanStatus) => (status ? STATUS_META[status] : null);

    const renderCellContent = (row: PlanRow, rowIndex: number, col: CellKey) => {
        const summary = summaries[rowIndex];
        const group = summary.isGroup;
        switch (col) {
            case 'name':
                return (
                    <span className="flex min-w-0 items-center gap-1" style={{ paddingLeft: row.level * 18 }}>
                        {group ? (
                            <button
                                type="button"
                                className="grid size-5 shrink-0 place-items-center rounded text-muted-foreground hover:bg-accent hover:text-foreground"
                                onClick={(e) => { e.stopPropagation(); toggleCollapse(row.id); }}
                                onDoubleClick={(e) => e.stopPropagation()}
                                aria-label={isCollapsed(row) ? 'Expand' : 'Collapse'}
                                aria-expanded={!isCollapsed(row)}
                            >
                                {isCollapsed(row) ? <ChevronRight className="size-3.5" /> : <ChevronDown className="size-3.5" />}
                            </button>
                        ) : <span className="w-5 shrink-0" />}
                        <span className={cn('truncate', group && 'font-semibold', !row.name && 'text-muted-foreground/60 italic')}>
                            {row.name || (readOnly ? '' : 'Untitled')}
                        </span>
                        {row.notes && !settings.columns.notes && <StickyNote className="ml-1 size-3 shrink-0 text-amber-500" aria-label="Has notes" />}
                    </span>
                );
            case 'who':
                return <span className="truncate">{row.who}</span>;
            case 'status': {
                const meta = statusMeta(row.status);
                return meta ? <span className={cn('inline-flex max-w-full items-center gap-1 truncate rounded px-1.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide', meta.className)}>{meta.label}</span> : null;
            }
            case 'start':
            case 'end': {
                const day = col === 'start' ? summary.start : summary.end;
                return day !== null ? <span className={cn('tabular-nums', group && 'font-medium')}>{formatDate(day)}</span> : null;
            }
            case 'duration':
                return summary.days !== null ? <span className="tabular-nums">{summary.days}</span> : null;
            case 'progress':
                return summary.progress !== null ? <span className="tabular-nums">{summary.progress}%</span> : null;
            case 'notes':
                return <span className="truncate text-muted-foreground">{row.notes}</span>;
        }
    };

    const renderEditor = (row: PlanRow, col: CellKey, initial: string) => (
        <CellEditor
            key={`${row.id}-${col}`}
            col={col}
            initial={initial}
            suggestions={col === 'who' ? memberNames : undefined}
            listId={`${uid}-who`}
            onDone={finishEditing}
        />
    );

    const renderBars = (row: PlanRow, rowIndex: number) => {
        const summary = summaries[rowIndex];
        const out: ReactNode[] = [];
        if (summary.isGroup && row.bars.length === 0 && summary.start !== null && summary.end !== null) {
            const x1 = scale.x(summary.start);
            const x2 = scale.x(summary.end + 1);
            out.push(
                <div key="summary" className="pointer-events-none absolute" style={{ left: x1, width: Math.max(4, x2 - x1), top: ROW_H / 2 - 4, height: 8 }} title={`${row.name}: ${formatDate(summary.start)} – ${formatDate(summary.end)}`}>
                    <div className="h-[6px] rounded-sm bg-foreground/70" />
                    <div className="absolute left-0 top-0 h-[10px] w-[6px] bg-foreground/70 [clip-path:polygon(0_0,100%_0,0_100%)]" />
                    <div className="absolute right-0 top-0 h-[10px] w-[6px] bg-foreground/70 [clip-path:polygon(0_0,100%_0,100%_100%)]" />
                </div>,
            );
        }
        for (const bar of row.bars) {
            const g = barGeometry(bar);
            const selected = selectedBar === bar.id;
            const title = bar.type === 'milestone'
                ? `${bar.label || row.name}: ${formatDate(bar.start)}`
                : `${bar.label || row.name}: ${formatDate(bar.start)} – ${formatDate(bar.end)}${bar.progress ? ` · ${bar.progress}%` : ''}`;
            const label = bar.label;
            if (bar.type === 'milestone') {
                out.push(
                    <div
                        key={bar.id}
                        data-bar-id={bar.id}
                        title={title}
                        className={cn('group/bar absolute hover:z-[16]', selected && 'z-[16]', !readOnly && 'cursor-grab')}
                        style={{ left: g.x1, top: ROW_H / 2 - MILESTONE / 2, width: MILESTONE, height: MILESTONE }}
                        onDoubleClick={() => setBarDialog(bar.id)}
                        onContextMenu={(e) => openBarMenu(e, row, bar)}
                    >
                        <div className={cn('size-full rotate-45 scale-[0.78] rounded-[3px] shadow-sm', selected && 'ring-2 ring-foreground ring-offset-2 ring-offset-background')} style={{ background: bar.color }} />
                        {!readOnly && <LinkHandle selected={selected} />}
                        {label && <BarLabel left={MILESTONE + 6}>{label}</BarLabel>}
                    </div>,
                );
                continue;
            }
            const width = g.x2 - g.x1;
            out.push(
                <div
                    key={bar.id}
                    data-bar-id={bar.id}
                    title={title}
                    className={cn('group/bar absolute rounded-[5px] shadow-[0_1px_2px_rgba(0,0,0,.18)] hover:z-[16]', !readOnly && 'cursor-grab', selected && 'z-[16] ring-2 ring-foreground ring-offset-1 ring-offset-background')}
                    style={{ left: g.x1, width, top: BAR_TOP, height: BAR_H, background: bar.color }}
                    onDoubleClick={() => setBarDialog(bar.id)}
                    onContextMenu={(e) => openBarMenu(e, row, bar)}
                >
                    {!!bar.progress && <div className="pointer-events-none h-full rounded-l-[5px] bg-black/25" style={{ width: `${bar.progress}%`, borderTopRightRadius: bar.progress === 100 ? 5 : 0, borderBottomRightRadius: bar.progress === 100 ? 5 : 0 }} />}
                    {!readOnly && (
                        <>
                            <div data-handle="start" className="absolute inset-y-0 left-0 w-2 cursor-ew-resize rounded-l-[5px] opacity-0 transition-opacity group-hover/bar:opacity-100 group-hover/bar:bg-black/15" />
                            <div data-handle="end" className="absolute inset-y-0 right-0 w-2 cursor-ew-resize rounded-r-[5px] opacity-0 transition-opacity group-hover/bar:opacity-100 group-hover/bar:bg-black/15" />
                            <LinkHandle selected={selected} />
                        </>
                    )}
                    {label && <BarLabel left={width + 8}>{label}</BarLabel>}
                </div>,
            );
        }
        return out;
    };

    const dependencyPath = (from: { x2: number; y: number }, to: { x1: number; y: number }) => {
        const sx = from.x2;
        const sy = from.y;
        const ex = to.x1;
        const ey = to.y;
        if (ex - sx >= 16) return `M ${sx} ${sy} H ${sx + 8} V ${ey} H ${ex - 2}`;
        const midY = ey > sy ? sy + ROW_H / 2 : sy - ROW_H / 2;
        return `M ${sx} ${sy} H ${sx + 8} V ${midY} H ${ex - 12} V ${ey} H ${ex - 2}`;
    };

    const totalHeight = effectiveShown.length * ROW_H;
    const weekendStripes = (settings.zoom === 'day' || settings.zoom === 'week') && settings.showWeekends
        ? (() => {
            const out: number[] = [];
            for (let day = scale.start; day <= scale.end; day++) if (isWeekend(day) && !isWeekend(day - 1)) out.push(day);
            return out;
        })()
        : [];
    const todayX = scale.x(today) + (settings.zoom === 'day' ? scale.pxPerDay / 2 : 0);
    const selectedBarHit = selectedBar ? findBar(data, selectedBar) : null;
    const filterCount = (statusFilter !== 'all' ? 1 : 0) + (whoFilter !== 'all' ? 1 : 0);

    // ── Toolbar ───────────────────────────────────────────────────────
    const toolbar = showToolbar && !printMode && (
        <div className="flex flex-wrap items-center gap-1 border-b bg-background px-3 py-2 sm:px-4" role="toolbar" aria-label="Plan tools">
            {!readOnly && (
                <>
                    <div className="flex items-center">
                        <Button size="sm" className="h-8 gap-1.5 rounded-r-none" onClick={() => insertBelow(actionRows()[0])}>
                            <Plus className="size-4" />Row
                        </Button>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button size="sm" className="h-8 rounded-l-none border-l border-primary-foreground/20 px-1.5" aria-label="More ways to add"><ChevronDown className="size-4" /></Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="start">
                                <DropdownMenuItem onClick={() => insertBelow(actionRows()[0])}><Plus className="mr-2 size-4" />Row</DropdownMenuItem>
                                <DropdownMenuItem onClick={() => { const id = actionRows()[0]; if (id) addSubRow(id); else insertBelow(); }}><Rows3 className="mr-2 size-4" />Sub-row</DropdownMenuItem>
                                <DropdownMenuItem onClick={addGroup}><Rows3 className="mr-2 size-4" />Group</DropdownMenuItem>
                                <DropdownMenuItem onClick={addMilestoneRow}><Diamond className="mr-2 size-4" />Milestone row</DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                    <div className="hidden items-center gap-1 sm:flex">
                        <ToolbarDivider />
                        <ToolButton label="Outdent (Shift+Tab)" onClick={() => mutate((d) => outdentRows(d, actionRows()))} disabled={!actionRows().length}><IndentDecrease /></ToolButton>
                        <ToolButton label="Indent (Tab)" onClick={() => mutate((d) => indentRows(d, actionRows()))} disabled={!actionRows().length}><IndentIncrease /></ToolButton>
                        <ToolbarDivider />
                        <ToolButton label={selectedBarHit ? (selectedBarHit.bar.type === 'milestone' ? 'Convert to bar' : 'Convert to milestone') : 'Add milestone'} onClick={toggleMilestone}><Diamond /></ToolButton>
                        <Popover>
                            <PopoverTrigger asChild>
                                <Button variant="ghost" size="icon" className="size-8 text-muted-foreground" title="Colour" aria-label="Colour" disabled={!selectedBar && !actionRows().length}>
                                    <span className="relative"><Palette className="size-4" /><span className="absolute -bottom-1 left-0 h-1 w-full rounded-full" style={{ background: selectedBarHit?.bar.color ?? lastColor }} /></span>
                                </Button>
                            </PopoverTrigger>
                            <PopoverContent className="w-auto p-3" align="start">
                                <p className="mb-2 text-xs font-medium text-muted-foreground">{selectedBar ? 'Bar colour' : 'Colour of the selected rows’ bars'}</p>
                                <ColorSwatches value={selectedBarHit?.bar.color ?? lastColor} onChange={applyColor} />
                            </PopoverContent>
                        </Popover>
                        <ToolButton label="Delete (Del)" onClick={deleteSelection} disabled={!selectedBar && !selectedDep && !actionRows().length}><Trash2 /></ToolButton>
                        <ToolbarDivider />
                        <ToolButton label="Undo (Ctrl+Z)" onClick={() => editor?.undo()} disabled={!editor?.canUndo}><Undo2 /></ToolButton>
                        <ToolButton label="Redo (Ctrl+Y)" onClick={() => editor?.redo()} disabled={!editor?.canRedo}><Redo2 /></ToolButton>
                        <ToolbarDivider />
                    </div>
                </>
            )}

            <div className="relative">
                <Search className="pointer-events-none absolute left-2 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                <input
                    type="search"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder="Filter rows"
                    aria-label="Filter rows"
                    className="h-8 w-36 rounded-md border bg-background pl-7 pr-2 text-sm outline-none transition-[width] placeholder:text-muted-foreground focus:w-48 focus:ring-2 focus:ring-ring/30"
                />
            </div>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant={filterCount ? 'secondary' : 'ghost'} size="sm" className="h-8 gap-1.5 text-muted-foreground data-[state=open]:bg-accent">
                        <Filter className="size-3.5" />Filter{filterCount > 0 && <span className="rounded bg-primary px-1 text-[10px] font-semibold text-primary-foreground">{filterCount}</span>}
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" className="w-52">
                    <DropdownMenuLabel>Status</DropdownMenuLabel>
                    <DropdownMenuRadioGroup value={statusFilter} onValueChange={(v) => setStatusFilter(v as PlanStatus | 'all' | 'none')}>
                        <DropdownMenuRadioItem value="all">Any status</DropdownMenuRadioItem>
                        {(Object.keys(STATUS_META) as PlanStatus[]).map((s) => <DropdownMenuRadioItem key={s} value={s}>{STATUS_META[s].label}</DropdownMenuRadioItem>)}
                        <DropdownMenuRadioItem value="none">No status</DropdownMenuRadioItem>
                    </DropdownMenuRadioGroup>
                    {whoOptions.length > 0 && (
                        <>
                            <DropdownMenuSeparator />
                            <DropdownMenuLabel>Who</DropdownMenuLabel>
                            <DropdownMenuRadioGroup value={whoFilter} onValueChange={setWhoFilter}>
                                <DropdownMenuRadioItem value="all">Anyone</DropdownMenuRadioItem>
                                {whoOptions.map((w) => <DropdownMenuRadioItem key={w} value={w}>{w}</DropdownMenuRadioItem>)}
                            </DropdownMenuRadioGroup>
                        </>
                    )}
                    {filtering && (
                        <>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem onClick={() => { setQuery(''); setStatusFilter('all'); setWhoFilter('all'); }}><X className="mr-2 size-4" />Clear filters</DropdownMenuItem>
                        </>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>

            <div className="ml-auto flex items-center gap-1">
                {toolbarExtra}
                <Button variant="ghost" size="sm" className="h-8 gap-1.5 text-muted-foreground" onClick={() => scrollToDay(today, 'center')}>
                    <CalendarCheck className="size-3.5" />Today
                </Button>
                <div className="flex rounded-md border p-0.5" role="radiogroup" aria-label="Zoom">
                    {ZOOMS.map((z) => (
                        <button
                            key={z.key}
                            type="button"
                            role="radio"
                            aria-checked={settings.zoom === z.key}
                            title={z.title}
                            onClick={() => changeZoom(z.key)}
                            className={cn('rounded px-2 py-1 text-xs font-medium transition-colors', settings.zoom === z.key ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground')}
                        >
                            <span className="sm:hidden">{z.label[0]}</span><span className="hidden sm:inline">{z.label}</span>
                        </button>
                    ))}
                </div>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon" className="size-8 text-muted-foreground" title="Columns" aria-label="Columns"><Columns3 className="size-4" /></Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-48">
                        <DropdownMenuLabel>Columns</DropdownMenuLabel>
                        {COLUMN_ORDER.map((c) => (
                            <DropdownMenuCheckboxItem
                                key={c}
                                checked={settings.columns[c]}
                                onCheckedChange={(checked) => setSettings({ columns: { ...settings.columns, [c]: !!checked } })}
                                onSelect={(e) => e.preventDefault()}
                            >
                                {c === 'duration' ? 'Working days' : c === 'progress' ? 'Progress' : COLUMN_META[c].label}
                            </DropdownMenuCheckboxItem>
                        ))}
                        <DropdownMenuSeparator />
                        <DropdownMenuCheckboxItem checked={settings.showWeekends} onCheckedChange={(checked) => setSettings({ showWeekends: !!checked })} onSelect={(e) => e.preventDefault()}>
                            Shade weekends
                        </DropdownMenuCheckboxItem>
                    </DropdownMenuContent>
                </DropdownMenu>
                {!readOnly && (
                    <Popover>
                        <PopoverTrigger asChild>
                            <Button variant="ghost" size="icon" className="hidden size-8 text-muted-foreground sm:inline-flex" title="Keyboard shortcuts" aria-label="Keyboard shortcuts"><Keyboard className="size-4" /></Button>
                        </PopoverTrigger>
                        <PopoverContent align="end" className="w-80 p-3">
                            <p className="mb-2 text-sm font-semibold">Shortcuts</p>
                            <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-xs">
                                {SHORTCUTS.map(([keys, what]) => (
                                    <div key={keys} className="contents">
                                        <dt><kbd className="whitespace-nowrap rounded border bg-muted px-1.5 py-0.5 font-mono text-[10px]">{keys}</kbd></dt>
                                        <dd className="text-muted-foreground">{what}</dd>
                                    </div>
                                ))}
                            </dl>
                        </PopoverContent>
                    </Popover>
                )}
            </div>
        </div>
    );

    // ── Layout ────────────────────────────────────────────────────────
    const headerHeight = HEADER_TOP + HEADER_BOTTOM;

    return (
        <div
            ref={rootRef}
            tabIndex={-1}
            className={cn('flex min-h-0 flex-col outline-none', className)}
            onKeyDown={onKeyDown}
            onCopy={(e) => { if (!(e.target as HTMLElement).closest('input, textarea') && copyRows(e)) e.preventDefault(); }}
            onCut={(e) => { if (!readOnly && !(e.target as HTMLElement).closest('input, textarea') && copyRows(e)) { e.preventDefault(); removeRows(actionRows()); } }}
            onPaste={(e) => { if (!(e.target as HTMLElement).closest('input, textarea')) { e.preventDefault(); pasteText(e.clipboardData.getData('text/plain')); } }}
        >
            {toolbar}
            <datalist id={`${uid}-who`}>{memberNames.map((n) => <option key={n} value={n} />)}</datalist>

            <div ref={scrollerRef} className={cn('relative min-h-0 flex-1 select-none bg-background', printMode ? 'overflow-visible' : 'overflow-auto')}>
                <div className="relative" style={{ width: gridW + scale.width, minHeight: '100%' }}>
                    {/* Header */}
                    <div className={cn('z-30 flex border-b bg-muted', !printMode && 'sticky top-0')} style={{ height: headerHeight }}>
                        <div className={cn('z-40 flex shrink-0 items-end border-r bg-muted text-[11px] font-semibold uppercase tracking-wide text-muted-foreground', !printMode && 'sticky left-0')} style={{ width: gridW, height: headerHeight }}>
                            <div className="flex h-[30px] items-center justify-center" style={{ width: NUM_W }}>#</div>
                            <div className="flex h-[30px] items-center px-2" style={{ width: nameWidth }}>Task</div>
                            {columns.map((c) => (
                                <div key={c} className={cn('flex h-[30px] items-center border-l px-2', COLUMN_META[c].align === 'right' && 'justify-end')} style={{ width: COLUMN_META[c].width }}>{COLUMN_META[c].label}</div>
                            ))}
                            {!readOnly && !printMode && !compact && (
                                <div
                                    className="absolute -right-1 top-0 z-50 h-full w-2 cursor-col-resize hover:bg-primary/30"
                                    title="Drag to resize"
                                    onPointerDown={(e) => { e.preventDefault(); beginGesture({ kind: 'splitter', startX: e.clientX, startWidth: gridW }, 'col-resize'); }}
                                />
                            )}
                        </div>
                        <div className="relative shrink-0" style={{ width: scale.width }}>
                            <div className="relative border-b" style={{ height: HEADER_TOP }}>
                                {scale.top.map((c) => (
                                    <div key={c.key} className="absolute top-0 flex h-full items-center overflow-hidden whitespace-nowrap border-l px-2 text-[11px] font-semibold text-foreground/80" style={{ left: c.x, width: c.width }}>
                                        {c.label}
                                    </div>
                                ))}
                            </div>
                            <div className="relative" style={{ height: HEADER_BOTTOM }}>
                                {scale.bottom.map((c) => (
                                    <div
                                        key={c.key}
                                        className={cn(
                                            'absolute top-0 flex h-full flex-col items-center justify-center overflow-hidden whitespace-nowrap border-l text-[11px] leading-tight text-muted-foreground',
                                            c.weekend && settings.showWeekends && 'bg-muted/70',
                                            c.today && 'bg-primary/10 font-semibold text-primary',
                                        )}
                                        style={{ left: c.x, width: c.width }}
                                    >
                                        <span>{c.label}</span>
                                        {c.sub && <span className="text-[9px] opacity-70">{c.sub}</span>}
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>

                    {/* Body */}
                    <div ref={bodyRef} className="relative" style={{ height: totalHeight + (readOnly ? 0 : ROW_H) }}>
                        {/* Timeline background */}
                        <div className="pointer-events-none absolute top-0" style={{ left: gridW, width: scale.width, height: '100%' }}>
                            {weekendStripes.map((day) => (
                                <div key={day} className="absolute top-0 h-full bg-muted/50" style={{ left: scale.x(day), width: scale.pxPerDay * 2 }} />
                            ))}
                            {scale.bottom.map((c) => <div key={c.key} className="absolute top-0 h-full border-l border-border/50" style={{ left: c.x }} />)}
                            {today >= scale.start && today <= scale.end && (
                                <div className="absolute top-0 h-full w-0.5 bg-destructive/70" style={{ left: todayX - 1 }} />
                            )}
                        </div>

                        {effectiveShown.map(({ row, index }, position) => {
                            const isSelected = selectedRows.includes(row.id);
                            return (
                                <div key={row.id} className="absolute left-0 flex" style={{ top: position * ROW_H, height: ROW_H, width: gridW + scale.width }}>
                                    {/* Grid cells */}
                                    <div
                                        className={cn('z-20 flex shrink-0 border-b border-r bg-background text-sm', !printMode && 'sticky left-0')}
                                        style={{ width: gridW, backgroundImage: isSelected ? ROW_TINT_SELECTED : summaries[index].isGroup ? ROW_TINT_GROUP : undefined }}
                                        onContextMenu={(e) => openRowMenu(e, row)}
                                    >
                                        <div
                                            className={cn('group/num relative flex shrink-0 cursor-default select-none items-center justify-center border-r text-[11px] tabular-nums text-muted-foreground', isSelected && selectionMode === 'rows' && 'bg-primary/10 text-primary')}
                                            style={{ width: NUM_W }}
                                            onPointerDown={(e) => {
                                                if (e.button !== 0) return;
                                                focusRoot();
                                                selectRow(row.id, e, 'rows');
                                                setActiveCell({ rowId: row.id, col: activeCell?.col ?? 'name' });
                                                if (!readOnly && !filtering && !e.shiftKey && !e.metaKey && !e.ctrlKey) {
                                                    beginGesture({ kind: 'row', rowId: row.id, startY: e.clientY, moved: false }, 'grabbing');
                                                }
                                            }}
                                        >
                                            <span className={cn(!readOnly && !filtering && 'group-hover/num:opacity-0')}>{index + 1}</span>
                                            {!readOnly && !filtering && <GripVertical className="absolute size-3.5 opacity-0 group-hover/num:opacity-100" />}
                                        </div>
                                        {cellsOrder.map((col) => {
                                            const width = col === 'name' ? nameWidth : COLUMN_META[col].width;
                                            const active = activeCell?.rowId === row.id && activeCell.col === col;
                                            const isEditing = editing?.rowId === row.id && editing.col === col;
                                            return (
                                                <div
                                                    key={col}
                                                    className={cn(
                                                        'relative flex min-w-0 shrink-0 items-center px-2',
                                                        col !== 'name' && 'border-l border-border/60',
                                                        COLUMN_META[col as PlanColumn]?.align === 'right' && 'justify-end',
                                                        active && !isEditing && 'z-10 outline outline-2 -outline-offset-2 outline-primary',
                                                        !isEditable(index, col) && col !== 'name' && 'text-muted-foreground',
                                                    )}
                                                    style={{ width }}
                                                    onPointerDown={(e) => {
                                                        if (e.button !== 0 || isEditing) return;
                                                        if ((e.target as HTMLElement).closest('button')) return;
                                                        focusRoot();
                                                        setActiveCell({ rowId: row.id, col });
                                                        selectRow(row.id, e, e.shiftKey || e.metaKey || e.ctrlKey ? 'rows' : 'cells');
                                                    }}
                                                    onDoubleClick={() => startEditing(row.id, col)}
                                                >
                                                    {isEditing ? renderEditor(row, col, editing.initial) : renderCellContent(row, index, col)}
                                                </div>
                                            );
                                        })}
                                    </div>
                                    {/* Timeline cell */}
                                    <div
                                        className={cn('relative shrink-0 border-b border-border/60', isSelected && 'bg-primary/[0.035]')}
                                        style={{ width: scale.width }}
                                        onPointerDown={(e) => onTimelinePointerDown(e, row)}
                                        onContextMenu={(e) => { if (!(e.target as HTMLElement).closest('[data-bar-id]')) openRowMenu(e, row); }}
                                    >
                                        {renderBars(row, index)}
                                    </div>
                                </div>
                            );
                        })}

                        {/* Add row */}
                        {!readOnly && (
                            <div className="absolute left-0 flex" style={{ top: totalHeight, height: ROW_H, width: gridW + scale.width }}>
                                <button
                                    type="button"
                                    style={{ width: gridW }}
                                    className={cn('z-20 flex shrink-0 items-center gap-2 border-b border-r border-dashed bg-background px-3 text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-foreground', !printMode && 'sticky left-0')}
                                    onClick={() => insertRowAt(rows.length, rows[rows.length - 1]?.level ?? 0)}
                                >
                                    <Plus className="size-4" />Add row
                                    {rows.length === 0 && <span className="text-xs">— or paste rows from Excel (Ctrl+V)</span>}
                                </button>
                            </div>
                        )}

                        {/* Dependencies */}
                        <svg className="pointer-events-none absolute top-0 z-[15] overflow-visible" style={{ left: gridW, width: scale.width, height: Math.max(totalHeight, 1) }}>
                            <defs>
                                <marker id={`${uid}-arrow`} viewBox="0 0 8 8" refX="7" refY="4" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                                    <path d="M0,0 L8,4 L0,8 z" className="fill-foreground/60" />
                                </marker>
                                <marker id={`${uid}-arrow-active`} viewBox="0 0 8 8" refX="7" refY="4" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                                    <path d="M0,0 L8,4 L0,8 z" className="fill-primary" />
                                </marker>
                            </defs>
                            {data.dependencies.map((dep) => {
                                const from = barPositions.get(dep.from);
                                const to = barPositions.get(dep.to);
                                if (!from || !to) return null;
                                const active = selectedDep === dep.id || hoverDep === dep.id || selectedBar === dep.from || selectedBar === dep.to;
                                const d = dependencyPath(from, to);
                                return (
                                    <g key={dep.id}>
                                        <path d={d} fill="none" strokeWidth={active ? 2 : 1.4} className={active ? 'stroke-primary' : 'stroke-foreground/45'} markerEnd={`url(#${uid}-arrow${active ? '-active' : ''})`} />
                                        {!readOnly && (
                                            <path
                                                d={d}
                                                fill="none"
                                                stroke="transparent"
                                                strokeWidth={10}
                                                className="pointer-events-auto cursor-pointer"
                                                onPointerEnter={() => setHoverDep(dep.id)}
                                                onPointerLeave={() => setHoverDep(null)}
                                                onPointerDown={(e) => { e.stopPropagation(); focusRoot(); setSelectedDep(dep.id); setSelectedBar(null); }}
                                                onContextMenu={(e) => {
                                                    e.preventDefault();
                                                    setSelectedDep(dep.id);
                                                    setMenu({ x: e.clientX, y: e.clientY, entries: [{ label: 'Remove link', icon: <Unlink />, destructive: true, onSelect: () => mutate((dd) => removeDependencies(dd, [dep.id])) }] });
                                                }}
                                            >
                                                <title>Click to select · Delete removes the link</title>
                                            </path>
                                        )}
                                    </g>
                                );
                            })}
                            {linkDraft && <line x1={linkDraft.x1} y1={linkDraft.y1} x2={linkDraft.x2} y2={linkDraft.y2} className="stroke-primary" strokeWidth={2} strokeDasharray="4 3" />}
                        </svg>

                        {dropIndex !== null && (
                            <div className="pointer-events-none absolute left-0 z-40 h-0.5 bg-primary" style={{ top: dropIndex * ROW_H - 1, width: gridW + scale.width }} />
                        )}

                        {effectiveShown.length === 0 && readOnly && (
                            <div className="absolute inset-x-0 top-6 text-center text-sm text-muted-foreground">{filtering ? 'No rows match the filter.' : 'This plan is empty.'}</div>
                        )}
                        {effectiveShown.length === 0 && !readOnly && filtering && (
                            <div className="absolute top-12 z-20 text-sm text-muted-foreground" style={{ left: 16 }}>No rows match the filter.</div>
                        )}
                    </div>
                </div>
            </div>

            {menu && <ContextMenu x={menu.x} y={menu.y} entries={menu.entries} onClose={() => setMenu(null)} />}
            <BarDialog
                data={data}
                barId={barDialog}
                readOnly={readOnly}
                onClose={() => { setBarDialog(null); focusRoot(); }}
                onSave={(bar) => {
                    const hit = findBar(data, bar.id);
                    if (hit) mutate((d) => setBar(d, hit.row.id, bar));
                    setLastColor(bar.color);
                }}
                onDelete={(id) => { mutate((d) => removeBars(d, [id])); setSelectedBar(null); }}
                onRemoveDependency={(id) => mutate((d) => removeDependencies(d, [id]))}
            />
        </div>
    );
}

function ToolButton({ label, onClick, disabled, children }: { label: string; onClick: () => void; disabled?: boolean; children: ReactNode }) {
    return (
        <Button variant="ghost" size="icon" className="size-8 text-muted-foreground [&_svg]:size-4" title={label} aria-label={label} onClick={onClick} disabled={disabled}>
            {children}
        </Button>
    );
}

function ToolbarDivider() {
    return <div className="mx-1 h-5 w-px bg-border" aria-hidden />;
}

function LinkHandle({ selected }: { selected: boolean }) {
    return (
        <div
            data-handle="link"
            title="Drag to another bar to link"
            className={cn(
                'absolute -right-[13px] top-1/2 z-10 size-[10px] -translate-y-1/2 cursor-crosshair rounded-full border-2 border-background bg-primary shadow transition-opacity',
                selected ? 'opacity-100' : 'opacity-0 group-hover/bar:opacity-100',
            )}
        />
    );
}

function BarLabel({ left, children }: { left: number; children: ReactNode }) {
    return (
        <span className="pointer-events-none absolute top-1/2 max-w-[260px] -translate-y-1/2 truncate whitespace-nowrap text-xs font-medium text-foreground/80" style={{ left }}>
            {children}
        </span>
    );
}

/**
 * In-cell editor. Enter commits and moves down, Tab right, Escape cancels, blur commits.
 */
function CellEditor({ col, initial, suggestions, listId, onDone }: {
    col: CellKey;
    initial: string;
    suggestions?: string[];
    listId: string;
    onDone: (value: string | null, move: 'down' | 'right' | 'left' | null) => void;
}) {
    const [value, setValue] = useState(initial);
    const done = useRef(false);
    const ref = useRef<HTMLInputElement & HTMLSelectElement>(null);

    useEffect(() => {
        const el = ref.current as HTMLElement | null;
        if (!el) return;
        el.focus();
        if (el instanceof HTMLInputElement && el.type === 'text') {
            const end = el.value.length;
            el.setSelectionRange(end, end);
        }
    }, []);

    const finish = (commit: boolean, move: 'down' | 'right' | 'left' | null) => {
        if (done.current) return;
        done.current = true;
        onDone(commit ? value : null, move);
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement | HTMLSelectElement>) => {
        event.stopPropagation();
        if (event.key === 'Enter') { event.preventDefault(); finish(true, 'down'); }
        else if (event.key === 'Escape') { event.preventDefault(); finish(false, null); }
        else if (event.key === 'Tab') { event.preventDefault(); finish(true, event.shiftKey ? 'left' : 'right'); }
    };

    const className = 'absolute inset-0 z-20 w-full rounded-none border-0 bg-background px-2 text-sm outline outline-2 -outline-offset-2 outline-primary';

    if (col === 'status') {
        return (
            <select
                ref={ref}
                value={value}
                className={className}
                onChange={(e) => { setValue(e.target.value); done.current = true; onDone(e.target.value, null); }}
                onBlur={() => finish(true, null)}
                onKeyDown={onKeyDown}
            >
                <option value="">No status</option>
                {(Object.keys(STATUS_META) as PlanStatus[]).map((s) => <option key={s} value={s}>{STATUS_META[s].label}</option>)}
            </select>
        );
    }

    const type = col === 'start' || col === 'end' ? 'date' : col === 'duration' || col === 'progress' ? 'number' : 'text';
    return (
        <input
            ref={ref}
            type={type}
            value={value}
            min={col === 'duration' ? 1 : col === 'progress' ? 0 : undefined}
            max={col === 'progress' ? 100 : undefined}
            list={suggestions ? listId : undefined}
            maxLength={col === 'name' ? 500 : col === 'notes' ? 5000 : 255}
            className={className}
            onChange={(e) => setValue(e.target.value)}
            onBlur={() => finish(true, null)}
            onKeyDown={onKeyDown}
            aria-label={col}
        />
    );
}
