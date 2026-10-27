import { router } from '@inertiajs/react';
import { analyticsConfig, consentState, isExcludedPath, subscribeConsent, type AnalyticsConfig } from './consent';

/**
 * Loads the operator's Umami only while the visitor has consented (BRAI-156) and counts page views
 * itself: Umami's auto-track only sees full page loads and would keep running after a withdrawal,
 * so it is off (`data-auto-track="false"`) and every Inertia navigation is sent here — path only (no
 * query string, no hash, no page title: titles can hold the names of private documents), never on
 * excluded paths (customer pages, secret links). Withdrawing removes the script at once.
 */

const SCRIPT_ID = 'brainitive-analytics';

let lastTracked: string | null = null;

function referrerOf(url: string): string | undefined {
    if (!url) return undefined;
    try {
        const parsed = new URL(url);
        return parsed.origin === window.location.origin ? parsed.pathname : `${parsed.origin}${parsed.pathname}`;
    } catch {
        return undefined;
    }
}

function trackPage(config: AnalyticsConfig): void {
    if (consentState() !== 'granted') return;
    const path = window.location.pathname || '/';
    if (isExcludedPath(path, config.excluded) || path === lastTracked) return;
    const umami = window.umami;
    if (!umami?.track) return; // not loaded yet: the script's onload counts this page

    const referrer = lastTracked ?? referrerOf(document.referrer);
    lastTracked = path;
    umami.track((props: Record<string, unknown>) => ({ ...props, url: path, referrer, title: undefined }));
}

function load(config: AnalyticsConfig): void {
    if (document.getElementById(SCRIPT_ID)) return;

    // second line of defence inside Umami: nothing from an excluded path leaves the browser
    window.brainitiveAnalyticsFilter = (_type, payload) => {
        const url = typeof payload?.url === 'string' ? payload.url : window.location.pathname;
        return isExcludedPath(url, config.excluded) ? false : payload;
    };

    const script = document.createElement('script');
    script.id = SCRIPT_ID;
    script.defer = true;
    script.src = config.script;
    script.dataset.websiteId = config.websiteId;
    script.dataset.autoTrack = 'false';
    script.dataset.doNotTrack = 'true';
    script.dataset.excludeSearch = 'true';
    script.dataset.excludeHash = 'true';
    script.dataset.beforeSend = 'brainitiveAnalyticsFilter';
    script.onload = () => trackPage(config);
    document.head.appendChild(script);
}

function unload(): void {
    document.getElementById(SCRIPT_ID)?.remove();
    delete window.umami;
    delete window.brainitiveAnalyticsFilter;
    lastTracked = null;
}

/** Called once from app.tsx. Does nothing where the server sent no analytics config. */
export function initAnalytics(): void {
    const config = analyticsConfig();
    if (!config) return;

    const sync = () => {
        if (consentState() === 'granted') {
            load(config);
            trackPage(config);
        } else {
            unload();
        }
    };

    subscribeConsent(sync);
    // after Inertia has swapped the page (also fires on the first load: deduplicated by path)
    router.on('navigate', () => window.setTimeout(() => trackPage(config), 0));
    sync();
}
