/**
 * Web ↔ desktop bridge (BRAI-352). Brainitive Desktop (Tauri 2, repo armendzekjiri/brainitive-desktop)
 * loads this web app from the user's server in its webview and exposes a few native commands to
 * that origin only. Everything here is feature-detected: in a normal browser `isDesktop()` is false,
 * `notify` / `setBadge` do nothing and `openExternal` opens a new tab — so the web app keeps one code
 * path and never imports Tauri (no @tauri-apps/api dependency).
 *
 * Contract with the shell (keep in sync with the desktop repo):
 * - IPC via `window.__TAURI_INTERNALS__.invoke(cmd, args)`, these commands only:
 *   `brainitive_notify {title, body, url?}`, `brainitive_set_badge {count}`,
 *   `brainitive_open_external {url}`, `brainitive_sign_out {}` (after the web sign-out).
 * - The shell appends ` BrainitiveDesktop/<version>` to the user agent.
 * - The server shares `desktopMinVersion` (Inertia prop, config app-api.min_versions.desktop).
 */

type TauriInvoke = (cmd: string, args?: Record<string, unknown>) => Promise<unknown>;

declare global {
    interface Window {
        /** Set by the Tauri runtime inside Brainitive Desktop only. */
        __TAURI_INTERNALS__?: { invoke?: unknown };
    }
}

const USER_AGENT_MARK = /\bBrainitiveDesktop\/(\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?)/;

function invoker(): TauriInvoke | null {
    if (typeof window === 'undefined') return null;
    const invoke = window.__TAURI_INTERNALS__?.invoke;
    return typeof invoke === 'function' ? (invoke as TauriInvoke) : null;
}

async function call(cmd: string, args: Record<string, unknown>): Promise<boolean> {
    const invoke = invoker();
    if (!invoke) return false;
    try {
        await invoke(cmd, args);
        return true;
    } catch {
        // an older shell without this command, or the command refused: the web app carries on
        return false;
    }
}

/** The Brainitive Desktop version from the user agent, e.g. "0.3.1"; null in a browser. */
export function desktopVersion(): string | null {
    if (typeof navigator === 'undefined') return null;
    return USER_AGENT_MARK.exec(navigator.userAgent)?.[1] ?? null;
}

/** Running inside Brainitive Desktop (user-agent mark or the Tauri bridge). */
export function isDesktop(): boolean {
    return desktopVersion() !== null || invoker() !== null;
}

/** -1 / 0 / 1 for "1.2.3"-style versions (pre-release suffixes are ignored). */
export function compareVersions(a: string, b: string): number {
    const parts = (v: string) => v.split(/[-+]/)[0].split('.').map((n) => parseInt(n, 10) || 0);
    const [x, y] = [parts(a), parts(b)];
    for (let i = 0; i < Math.max(x.length, y.length); i++) {
        const diff = (x[i] ?? 0) - (y[i] ?? 0);
        if (diff !== 0) return diff > 0 ? 1 : -1;
    }
    return 0;
}

/** True inside a desktop app older than `minVersion` (the shared `desktopMinVersion` prop). */
export function isOutdatedDesktop(minVersion: string | null | undefined): boolean {
    const version = desktopVersion();
    return !!version && !!minVersion && compareVersions(version, minVersion) < 0;
}

/** Same-origin URLs only: a notification must never send the desktop window somewhere else. */
function sameOriginUrl(url: string | undefined): string | undefined {
    if (!url) return undefined;
    try {
        const resolved = new URL(url, window.location.origin);
        return resolved.origin === window.location.origin ? resolved.href : undefined;
    } catch {
        return undefined;
    }
}

let lastBadge: number | null = null;

export const desktop = {
    /** A native notification; clicking it opens `url` (same origin) in the app. No-op in a browser. */
    notify({ title, body, url }: { title: string; body: string; url?: string }): Promise<boolean> {
        const target = sameOriginUrl(url);
        return call('brainitive_notify', { title, body, ...(target ? { url: target } : {}) });
    },

    /** The dock / taskbar badge (0 clears it). No-op in a browser. */
    async setBadge(count: number): Promise<boolean> {
        const value = Number.isFinite(count) ? Math.max(0, Math.floor(count)) : 0;
        if (!invoker() || value === lastBadge) return false;
        const ok = await call('brainitive_set_badge', { count: value });
        if (ok) lastBadge = value;
        return ok;
    },

    /**
     * After the web "Sign out": sign the desktop app out too (revokes its device, clears the keychain,
     * shows the shell's sign-in screen) — otherwise its stored token would sign the window straight
     * back in. No-op in a browser.
     */
    signOut(): Promise<boolean> {
        return call('brainitive_sign_out', {});
    },

    /** Open a link in the system browser (desktop) or a new tab (browser). http(s) and mailto only. */
    async openExternal(url: string): Promise<boolean> {
        let parsed: URL;
        try {
            parsed = new URL(url, window.location.origin);
        } catch {
            return false;
        }
        if (!['http:', 'https:', 'mailto:'].includes(parsed.protocol)) return false;

        if (invoker()) return call('brainitive_open_external', { url: parsed.href });

        // with noopener, window.open returns null even when the tab opened
        window.open(parsed.href, '_blank', 'noopener,noreferrer');
        return true;
    },
};
