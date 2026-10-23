import type { CaptureDestinationWorkspace, CaptureType } from '@/types';

/** Quick capture (BRAI-70): labels and the keyboard-shortcut guard. */

export const CAPTURE_SHORTCUT = 'c';

export const CAPTURE_TYPES: { value: CaptureType; label: string; noun: string }[] = [
    { value: 'issue', label: 'Board card', noun: 'issue' },
    { value: 'task', label: 'Task', noun: 'task' },
    { value: 'page', label: 'Doc', noun: 'doc' },
    { value: 'bookmark', label: 'Bookmark', noun: 'bookmark' },
    { value: 'snippet', label: 'Snippet', noun: 'snippet' },
];

export const captureTypeLabel = (type: CaptureType | null | undefined): string =>
    CAPTURE_TYPES.find((t) => t.value === type)?.label ?? 'Not sure yet';

/**
 * True when the key press belongs to something the user is typing into: inputs, textareas,
 * selects, rich-text editors (contenteditable), ARIA textboxes, the whiteboard, or anything
 * inside an open dialog. The capture shortcut must never steal those keys.
 */
export function isTypingTarget(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) return false;
    if (target.isContentEditable) return true;
    if (target.closest('input, textarea, select, [contenteditable]:not([contenteditable="false"]), [role="textbox"], [role="combobox"], .excalidraw')) {
        return true;
    }
    return target.closest('[role="dialog"], [role="alertdialog"], [role="menu"], [role="listbox"]') !== null;
}

/** The capture shortcut: a plain "c", nothing else held, not while typing or composing. */
export function isCaptureShortcut(e: KeyboardEvent): boolean {
    if (e.defaultPrevented || e.isComposing || e.repeat) return false;
    if (e.metaKey || e.ctrlKey || e.altKey || e.shiftKey) return false;
    if (e.key.toLowerCase() !== CAPTURE_SHORTCUT) return false;
    return !isTypingTarget(e.target) && !isTypingTarget(document.activeElement);
}

/** One option of the convert dialog's destination picker. */
export interface DestinationOption {
    id: number;
    label: string;
    group: string;
    columns?: { id: number; name: string }[];
}

/** Flatten the writable destinations for one type ("Workspace / Project / Board"). */
export function destinationOptions(workspaces: CaptureDestinationWorkspace[], type: CaptureType): DestinationOption[] {
    const options: DestinationOption[] = [];
    for (const ws of workspaces) {
        if (type === 'bookmark') {
            ws.bookmarks.forEach((b) => options.push({ id: b.id, label: b.name, group: ws.name }));
        } else if (type === 'snippet') {
            ws.snippet_collections.forEach((c) => options.push({ id: c.id, label: c.name, group: ws.name }));
        } else {
            for (const project of ws.projects) {
                const group = `${ws.name} / ${project.name}`;
                if (type === 'page' && project.can_create_pages) options.push({ id: project.id, label: project.name, group: ws.name });
                if (type === 'issue') project.boards.forEach((b) => options.push({ id: b.id, label: b.name, group, columns: b.columns }));
                if (type === 'task') project.task_lists.forEach((l) => options.push({ id: l.id, label: l.name, group }));
            }
        }
    }
    return options;
}

/** "5 min ago" style label for when something was captured. */
export function capturedAgo(iso: string | null): string {
    if (!iso) return '';
    const date = new Date(iso);
    const seconds = Math.round((Date.now() - date.getTime()) / 1000);
    if (seconds < 60) return 'just now';
    const minutes = Math.round(seconds / 60);
    if (minutes < 60) return `${minutes} min ago`;
    const hours = Math.round(minutes / 60);
    if (hours < 24) return `${hours} h ago`;
    return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: date.getFullYear() !== new Date().getFullYear() ? 'numeric' : undefined });
}
