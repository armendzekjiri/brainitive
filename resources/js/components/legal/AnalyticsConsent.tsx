import { router } from '@inertiajs/react';
import { BarChart3 } from 'lucide-react';
import { useEffect, useState, useSyncExternalStore } from 'react';
import { useAnalyticsConsent } from '@/hooks/useAnalyticsConsent';
import { analyticsConfig, isExcludedPath, setConsent } from '@/lib/analytics/consent';
import { cn } from '@/lib/utils';

/*
 * Consent for our own statistics (self-hosted Umami, BRAI-156). Honest by design — the old cookie banner
 * (removed in BRAI-156) had a big blue "Accept all" next to grey alternatives: here both answers are
 * the same button, nothing is pre-selected, nothing is "accepted" by scrolling or closing, and the
 * notice never blocks the page. Until "Allow" nothing loads (lib/analytics/tracker.ts).
 */

/** Both answers look exactly alike: same size, colour and weight. */
const CHOICE_BUTTON =
    'inline-flex h-10 min-w-[7rem] flex-1 items-center justify-center rounded-lg border border-border bg-background px-4 text-sm font-semibold text-foreground transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background';

const PRIVACY_ANALYTICS = '/privacy#analytics';

function subscribePath(listener: () => void): () => void {
    return router.on('navigate', listener);
}

function currentPath(): string {
    return window.location.pathname;
}

/**
 * The bottom notice, mounted once at the app root (app.tsx). Shown only where the server allows
 * analytics (window.brainitiveAnalytics) and on paths that are not excluded, until a choice is made.
 */
export function AnalyticsConsentNotice() {
    const config = analyticsConfig();
    const state = useAnalyticsConsent();
    const path = useSyncExternalStore(subscribePath, currentPath, () => '/');
    // the text is inserted after the live region exists, so screen readers announce it
    const [ready, setReady] = useState(false);
    useEffect(() => setReady(true), []);

    if (!config) return null;
    const show = ready && state === 'unset' && !isExcludedPath(path, config.excluded);

    return (
        <div aria-live="polite" className="pointer-events-none fixed inset-x-0 bottom-0 z-[90] flex justify-center p-3 sm:p-4">
            {show && (
                <section
                    aria-label="Visit statistics"
                    data-testid="analytics-consent"
                    className="pointer-events-auto w-full max-w-lg rounded-xl border border-border bg-card p-4 text-card-foreground shadow-lg"
                >
                    <div className="flex items-start gap-3">
                        <BarChart3 className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                        <p className="text-sm leading-relaxed">
                            May we count your visit with our own privacy-friendly statistics? No cookies, no ads, nothing shared with anyone.{' '}
                            <a href={PRIVACY_ANALYTICS} className="whitespace-nowrap font-medium text-primary underline underline-offset-2">
                                What we count
                            </a>
                        </p>
                    </div>
                    <div className="mt-3 flex flex-wrap gap-2">
                        <button type="button" className={CHOICE_BUTTON} onClick={() => setConsent('granted')} data-testid="analytics-consent-allow">
                            Allow
                        </button>
                        <button type="button" className={CHOICE_BUTTON} onClick={() => setConsent('denied')} data-testid="analytics-consent-deny">
                            No thanks
                        </button>
                    </div>
                </section>
            )}
        </div>
    );
}

const STATE_TEXT = {
    granted: 'allowed',
    denied: 'not allowed',
    unset: 'not allowed (you have not decided yet)',
} as const;

/** The choice on /cookies: see it, change it or withdraw it at any time. */
export function AnalyticsConsentSetting() {
    const state = useAnalyticsConsent();
    const [mounted, setMounted] = useState(false);
    useEffect(() => setMounted(true), []);
    if (!mounted) return null;

    if (state === 'signal') {
        return (
            <div className="rounded-xl border bg-background p-4 text-sm" data-testid="analytics-consent-setting">
                <p data-testid="analytics-state">
                    Your browser sends a privacy signal (Global Privacy Control or Do Not Track), so we do not count your visits and do not ask.
                </p>
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-3 rounded-xl border bg-background p-4 sm:flex-row sm:items-center sm:justify-between" data-testid="analytics-consent-setting">
            <p className="text-sm">
                Statistics in this browser: <strong data-testid="analytics-state">{STATE_TEXT[state]}</strong>
            </p>
            <div role="group" aria-label="Visit statistics" className="flex gap-2">
                {(
                    [
                        ['granted', 'Allow'],
                        ['denied', 'Don’t allow'],
                    ] as const
                ).map(([choice, label]) => (
                    <button
                        key={choice}
                        type="button"
                        aria-pressed={state === choice}
                        onClick={() => setConsent(choice)}
                        className={cn(CHOICE_BUTTON, 'h-9 min-w-[6rem]', state === choice && 'border-primary bg-primary/10 text-primary hover:bg-primary/10')}
                    >
                        {label}
                    </button>
                ))}
            </div>
        </div>
    );
}
