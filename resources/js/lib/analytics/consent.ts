/**
 * Consent for the operator's own statistics (self-hosted Umami, BRAI-156). Analytics runs only after
 * the visitor actively clicks "Allow" (§ 25 (1) TDDDG, Art. 6 (1) (a) GDPR). The choice is kept in
 * this browser only — localStorage `brainitive:analytics-consent` (strictly necessary to remember the
 * choice; listed on /cookies). A Global Privacy Control or Do Not Track signal counts as "no" and is
 * never overridden. Umami's old opt-out key `umami.disabled` (before consent existed) counts as "no".
 */

export const CONSENT_KEY = 'brainitive:analytics-consent';
/** Bump when what we ask for changes materially: everybody is asked again. */
export const CONSENT_VERSION = 1;
const LEGACY_OPT_OUT = 'umami.disabled';

export type ConsentChoice = 'granted' | 'denied';
/** `signal` = the browser sends GPC / DNT; `unset` = not asked yet (nothing runs). */
export type ConsentState = ConsentChoice | 'signal' | 'unset';

export interface AnalyticsConfig {
    script: string;
    websiteId: string;
    excluded: string[];
}

declare global {
    interface Window {
        /** Rendered by app.blade.php only where analytics may run (App\Support\Legal\Analytics). */
        brainitiveAnalytics?: AnalyticsConfig;
        brainitiveAnalyticsFilter?: (type: string, payload: Record<string, unknown>) => Record<string, unknown> | false;
        umami?: { track: (payload?: unknown, data?: unknown) => unknown };
        doNotTrack?: string;
    }
    interface Navigator {
        globalPrivacyControl?: boolean;
        msDoNotTrack?: string;
    }
}

/** null = no analytics on this page load (self-hosted, dev, customer pages, secret links …). */
export function analyticsConfig(): AnalyticsConfig | null {
    return typeof window !== 'undefined' && window.brainitiveAnalytics ? window.brainitiveAnalytics : null;
}

export function isExcludedPath(path: string, excluded: string[]): boolean {
    return excluded.some((prefix) => path === prefix || path.startsWith(`${prefix}/`));
}

/** Global Privacy Control or Do Not Track: treated as "no" without asking. */
export function privacySignal(): boolean {
    if (typeof navigator === 'undefined') return false;
    return (
        navigator.globalPrivacyControl === true ||
        navigator.doNotTrack === '1' ||
        navigator.doNotTrack === 'yes' ||
        navigator.msDoNotTrack === '1' ||
        window.doNotTrack === '1'
    );
}

/** Used when storage is blocked: the choice holds for this page load, then we ask again. */
let memoryChoice: ConsentChoice | null = null;

function storedChoice(): ConsentChoice | null {
    try {
        const raw = localStorage.getItem(CONSENT_KEY);
        if (raw) {
            const parsed = JSON.parse(raw) as { choice?: unknown; version?: unknown };
            if ((parsed.choice === 'granted' || parsed.choice === 'denied') && parsed.version === CONSENT_VERSION) {
                return parsed.choice;
            }
        }
        if (localStorage.getItem(LEGACY_OPT_OUT) !== null) return 'denied';
    } catch {
        // storage blocked or a broken value: fall through
    }
    return memoryChoice;
}

export function consentState(): ConsentState {
    if (privacySignal()) return 'signal';
    return storedChoice() ?? 'unset';
}

const listeners = new Set<() => void>();

function notify(): void {
    listeners.forEach((listener) => listener());
}

export function setConsent(choice: ConsentChoice): void {
    memoryChoice = choice;
    try {
        localStorage.setItem(CONSENT_KEY, JSON.stringify({ choice, version: CONSENT_VERSION, at: new Date().toISOString() }));
        // the old opt-out would make Umami itself drop everything after an "Allow"
        localStorage.removeItem(LEGACY_OPT_OUT);
    } catch {
        // storage blocked: the choice holds until the next full page load
    }
    notify();
}

/** Calls `listener` when the choice changes — here or in another tab. */
export function subscribeConsent(listener: () => void): () => void {
    listeners.add(listener);
    const onStorage = (event: StorageEvent) => {
        if (event.key === CONSENT_KEY || event.key === LEGACY_OPT_OUT || event.key === null) listener();
    };
    window.addEventListener('storage', onStorage);
    return () => {
        listeners.delete(listener);
        window.removeEventListener('storage', onStorage);
    };
}
