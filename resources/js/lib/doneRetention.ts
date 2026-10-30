import type { Board, Item } from '@/types';

/**
 * Done column retention (BRAI-346), like Jira's "hide completed issues older than…": a Kanban
 * board shows done cards for N days; older ones leave the board (Kanban + swimlanes) but stay in
 * the list view, search and links. Mirrors Board::doneRetentionDays() / GetBoardTool in PHP.
 */
export const DONE_RETENTION_OPTIONS = [0, 7, 14, 30, 60, 90] as const;

const DAY_MS = 24 * 60 * 60 * 1000;

/** Days a done card stays on the board; 0 = all. Scrum boards show the running sprint instead. */
export function doneRetentionDays(board: Pick<Board, 'type' | 'settings'>): number {
    if (board.type === 'scrum') return 0;
    const days = Number(board.settings?.done_retention_days ?? 0);
    return (DONE_RETENTION_OPTIONS as readonly number[]).includes(days) ? days : 0;
}

/** Cards completed before this time (ms) leave the board; null = none do. */
export function doneCutoff(board: Pick<Board, 'type' | 'settings'>, now = Date.now()): number | null {
    const days = doneRetentionDays(board);
    return days > 0 ? now - days * DAY_MS : null;
}

/** An older done card (only ask for cards in a done column). No date = never hidden. */
export function isOlderDone(item: Pick<Item, 'completed_at'>, cutoff: number | null): boolean {
    if (cutoff === null || !item.completed_at) return false;
    const at = Date.parse(item.completed_at);
    return !Number.isNaN(at) && at < cutoff;
}

export function retentionLabel(days: number): string {
    return days === 0 ? 'Off (show all)' : `${days} days`;
}

/** "Show older done cards" is per board and per browser tab (sessionStorage), never saved. */
const storageKey = (boardId: number) => `board-${boardId}-show-older-done`;

export function readShowOlderDone(boardId: number): boolean {
    try {
        return sessionStorage.getItem(storageKey(boardId)) === '1';
    } catch {
        return false;
    }
}

export function writeShowOlderDone(boardId: number, show: boolean): void {
    try {
        if (show) sessionStorage.setItem(storageKey(boardId), '1');
        else sessionStorage.removeItem(storageKey(boardId));
    } catch {
        /* private mode: only this page view remembers it */
    }
}
