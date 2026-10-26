/**
 * Offline mode (BRAI-82), imported first by app.tsx — before Inertia boots:
 * - remember the address the page was loaded with: when the service worker answered with the
 *   offline shell, Inertia rewrites it to the shell's own (`/offline`);
 * - on the shell, forget the history entry's Inertia state, so Back / Forward render the shell
 *   instead of an online page from history (whose code and data may not be on the device).
 */
export const bootUrl: string = typeof window === 'undefined' ? '/' : window.location.pathname + window.location.search + window.location.hash;

function servedShell(): boolean {
    try {
        const raw = document.querySelector('script[data-page]')?.textContent || document.getElementById('app')?.getAttribute('data-page') || '';
        return (JSON.parse(raw) as { component?: string }).component === 'Offline/Shell';
    } catch {
        return false;
    }
}

if (typeof window !== 'undefined' && window.history.state && servedShell()) {
    window.history.replaceState(null, '', window.location.href);
}
