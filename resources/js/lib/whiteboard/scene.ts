/**
 * Live whiteboards (BRAI-153) — pure helpers shared by the binding: Excalidraw's element winner rule,
 * z-order, and the checks every value from another browser goes through before it reaches the canvas.
 * Plan: docs/ai/tasks/feat-live-whiteboards.md.
 */

/** What the binding needs from an Excalidraw element (the rest is carried along untouched). */
export interface SceneElement {
    id: string;
    type: string;
    version: number;
    versionNonce: number;
    isDeleted?: boolean;
    index?: string | null;
    fileId?: string | null;
    [key: string]: unknown;
}

export interface BinaryFile {
    id: string;
    dataURL: string;
    mimeType: string;
    created: number;
}

/** A live-added image in the Y doc: an uploaded copy on this site, or (small / SVG) inline. */
export interface SharedFile {
    mimeType: string;
    created: number;
    url?: string;
    dataURL?: string;
}

export interface PointerState {
    x: number;
    y: number;
    tool: 'pointer' | 'laser';
}

/** Excalidraw's rule (reconcile.ts): the higher version wins, a tie goes to the lower versionNonce. */
export const beats = (a: SceneElement, b: SceneElement) =>
    a.version > b.version || (a.version === b.version && a.versionNonce < b.versionNonce);

export const sameRevision = (a: SceneElement, b: SceneElement) => a.version === b.version && a.versionNonce === b.versionNonce;

/** z-order: fractional index (plain string order, like Excalidraw), then id. */
export function byIndex(a: SceneElement, b: SceneElement): number {
    const ai = a.index ?? '';
    const bi = b.index ?? '';
    if (ai !== bi) return ai < bi ? -1 : 1;
    return a.id < b.id ? -1 : a.id > b.id ? 1 : 0;
}

/** Excalidraw mutates its elements in place: Y must hold copies. */
export const copy = <T>(value: T): T => JSON.parse(JSON.stringify(value)) as T;

export function isSceneElement(value: unknown): value is SceneElement {
    const element = value as Partial<SceneElement> | null;
    return !!element && typeof element === 'object' && typeof element.id === 'string' && typeof element.type === 'string'
        && Number.isFinite(element.version) && Number.isFinite(element.versionNonce);
}

const IMAGE_DATA_URL = /^data:image\/(png|jpeg|gif|webp|svg\+xml|bmp|x-icon|avif);base64,/;
/** Inline images in the Y doc are kept small (the realtime server and the stored state have limits). */
export const MAX_INLINE_IMAGE_CHARS = 1_500_000;

export const isImageDataUrl = (value: unknown): value is string =>
    typeof value === 'string' && value.length <= 15 * 1024 * 1024 && IMAGE_DATA_URL.test(value);

/**
 * Where to fetch a shared image: only a file on this site's public storage (what `upload.image`
 * returns) — never another host, so a shared whiteboard can't make browsers call out.
 */
export function sharedImagePath(url: unknown): string | null {
    if (typeof url !== 'string' || url.length > 2000) return null;
    try {
        const parsed = new URL(url, window.location.origin);
        return parsed.pathname.startsWith('/storage/') && !parsed.pathname.includes('..') ? parsed.pathname : null;
    } catch {
        return null;
    }
}

export function sanitizePointer(value: unknown): PointerState | undefined {
    const pointer = value as Partial<PointerState> | null;
    if (!pointer || typeof pointer !== 'object' || !Number.isFinite(pointer.x) || !Number.isFinite(pointer.y)) return undefined;
    return { x: pointer.x as number, y: pointer.y as number, tool: pointer.tool === 'laser' ? 'laser' : 'pointer' };
}

/** Selection outlines of another person: element ids only, a bounded number. */
export function sanitizeSelection(value: unknown): Record<string, true> {
    const selection: Record<string, true> = {};
    if (!value || typeof value !== 'object') return selection;
    let count = 0;
    for (const [id, selected] of Object.entries(value as Record<string, unknown>)) {
        if (selected !== true || id.length > 100) continue;
        selection[id] = true;
        if (++count >= 200) break;
    }
    return selection;
}

/**
 * The colour Excalidraw paints a collaborator's cursor in (`getClientColor`: a pastel hue from the
 * collaborator id — it ignores any colour we pass). Avatars use the same, so a name matches its cursor;
 * text on it must be dark.
 */
export function cursorColor(userId: number): string {
    const id = String(userId);
    let hash = 0;
    for (let i = 0; i < id.length; i++) hash = (hash << 5) - hash + id.charCodeAt(i);
    return `hsl(${(Math.abs(hash) % 37) * 10}, 100%, 83%)`;
}

/** Yjs state vector as `{client: clock}` (what the server's vector guard compares). */
export const covers = (stored: Record<string, number>, incoming: Record<string, number>) =>
    Object.entries(incoming).every(([client, clock]) => clock <= (stored[client] ?? 0));

/**
 * Sign-out: forget every live whiteboard copy kept on this device (IndexedDB `brainitive-whiteboards-*`
 * and the epoch markers), so the next person on a shared browser can't read them.
 */
export async function forgetLiveWhiteboardCopies(): Promise<void> {
    try {
        Object.keys(localStorage).filter((key) => key.startsWith('brainitive-whiteboards-epoch-')).forEach((key) => localStorage.removeItem(key));
    } catch {
        /* storage blocked */
    }
    try {
        const databases = typeof indexedDB.databases === 'function' ? await indexedDB.databases() : [];
        databases.forEach(({ name }) => {
            if (name?.startsWith('brainitive-whiteboards-')) indexedDB.deleteDatabase(name);
        });
    } catch {
        /* IndexedDB blocked: nothing kept */
    }
}
