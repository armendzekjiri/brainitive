import { browser } from 'wxt/browser';

/**
 * Reading from the current tab. Everything runs through `scripting.executeScript` with
 * the `activeTab` grant (the user clicked the toolbar button, a menu entry or a shortcut),
 * so the extension never needs access to all sites. Browser pages (chrome://, the web
 * store, PDF viewer) refuse injection — callers fall back to the tab's title + URL.
 */

export interface PageSelection {
    text: string;
    html: string;
}

export interface PageDocument {
    html: string;
    url: string;
    title: string;
}

export async function readSelection(tabId: number): Promise<PageSelection | null> {
    try {
        const [result] = await browser.scripting.executeScript({
            target: { tabId },
            func: () => {
                const selection = window.getSelection();
                if (!selection || selection.isCollapsed) return { text: '', html: '' };
                const container = document.createElement('div');
                for (let i = 0; i < selection.rangeCount; i++) {
                    container.appendChild(selection.getRangeAt(i).cloneContents());
                }
                return { text: selection.toString(), html: container.innerHTML };
            },
        });
        return (result?.result as PageSelection | undefined) ?? null;
    } catch {
        return null;
    }
}

/** Full HTML for article extraction (capped at 5 MB). */
export async function readDocument(tabId: number): Promise<PageDocument | null> {
    try {
        const [result] = await browser.scripting.executeScript({
            target: { tabId },
            func: () => ({
                html: document.documentElement.outerHTML.slice(0, 5_000_000),
                url: location.href,
                title: document.title,
            }),
        });
        return (result?.result as PageDocument | undefined) ?? null;
    } catch {
        return null;
    }
}

export interface AreaRect {
    x: number;
    y: number;
    width: number;
    height: number;
    viewportWidth: number;
}

/**
 * Overlay that lets the user drag a rectangle. Resolves with the rectangle in CSS pixels,
 * or null on Escape / a click without dragging. The overlay is gone (two frames) before
 * it resolves, so it never shows up in the screenshot.
 */
export async function selectArea(tabId: number): Promise<AreaRect | null> {
    const [result] = await browser.scripting.executeScript({
        target: { tabId },
        func: () =>
            new Promise<AreaRect | null>((resolve) => {
                const existing = document.getElementById('__brainitive-area');
                existing?.remove();

                const root = document.createElement('div');
                root.id = '__brainitive-area';
                root.style.cssText =
                    'position:fixed;inset:0;z-index:2147483647;cursor:crosshair;background:rgba(15,23,42,.35);user-select:none;';
                const box = document.createElement('div');
                box.style.cssText =
                    'position:fixed;border:2px solid #0E83DC;background:rgba(14,131,220,.08);box-shadow:0 0 0 9999px rgba(15,23,42,.35);display:none;';
                const hint = document.createElement('div');
                hint.textContent = 'Drag to select an area · Esc to cancel';
                hint.style.cssText =
                    'position:fixed;top:16px;left:50%;transform:translateX(-50%);padding:8px 14px;border-radius:999px;background:#0f172a;color:#fff;font:500 13px system-ui,sans-serif;pointer-events:none;';
                root.append(box, hint);
                document.documentElement.appendChild(root);

                let start: { x: number; y: number } | null = null;
                let rect: AreaRect | null = null;

                const finish = (value: AreaRect | null) => {
                    window.removeEventListener('keydown', onKey, true);
                    root.remove();
                    requestAnimationFrame(() => requestAnimationFrame(() => resolve(value)));
                };
                const onKey = (event: KeyboardEvent) => {
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        finish(null);
                    }
                };
                window.addEventListener('keydown', onKey, true);

                root.addEventListener('mousedown', (event) => {
                    event.preventDefault();
                    start = { x: event.clientX, y: event.clientY };
                    root.style.background = 'transparent';
                    hint.style.display = 'none';
                    box.style.display = 'block';
                });
                root.addEventListener('mousemove', (event) => {
                    if (!start) return;
                    const x = Math.min(start.x, event.clientX);
                    const y = Math.min(start.y, event.clientY);
                    const width = Math.abs(event.clientX - start.x);
                    const height = Math.abs(event.clientY - start.y);
                    Object.assign(box.style, { left: `${x}px`, top: `${y}px`, width: `${width}px`, height: `${height}px` });
                    rect = { x, y, width, height, viewportWidth: window.innerWidth };
                });
                root.addEventListener('mouseup', () => {
                    finish(rect && rect.width > 4 && rect.height > 4 ? rect : null);
                });
            }),
    });
    return (result?.result as AreaRect | null | undefined) ?? null;
}

/** Crop a `captureVisibleTab` PNG to a CSS-pixel rectangle. Works in the service worker. */
export async function cropScreenshot(dataUrl: string, rect: AreaRect): Promise<string> {
    const bitmap = await createImageBitmap(await (await fetch(dataUrl)).blob());
    const scale = bitmap.width / rect.viewportWidth;
    const width = Math.round(rect.width * scale);
    const height = Math.round(rect.height * scale);
    const canvas = new OffscreenCanvas(width, height);
    canvas.getContext('2d')!.drawImage(bitmap, Math.round(rect.x * scale), Math.round(rect.y * scale), width, height, 0, 0, width, height);
    const blob = await canvas.convertToBlob({ type: 'image/png' });
    return blobToDataUrl(blob);
}

export async function blobToDataUrl(blob: Blob): Promise<string> {
    const bytes = new Uint8Array(await blob.arrayBuffer());
    let binary = '';
    for (let i = 0; i < bytes.length; i += 0x8000) {
        binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
    }
    return `data:${blob.type};base64,${btoa(binary)}`;
}

export async function dataUrlToBlob(dataUrl: string): Promise<Blob> {
    return (await fetch(dataUrl)).blob();
}

/** Pages the browser won't let extensions script or capture. */
export function isRestrictedUrl(url: string | undefined): boolean {
    return !url || !/^https?:|^file:/.test(url) || /^https:\/\/(chrome\.google\.com\/webstore|chromewebstore\.google\.com|addons\.mozilla\.org|microsoftedge\.microsoft\.com\/addons)/.test(url);
}
