import { Cookie } from 'lucide-react';
import { useEffect, useState } from 'react';
import { LegalLayout, LegalSection, LegalTable } from '@/components/legal/LegalLayout';
import type { LegalPageData } from '@/types';

/*
 * Cookies & device storage (BRAI-156): every cookie, localStorage / sessionStorage key and IndexedDB
 * database the app actually uses (docs/legal/data-inventory.md § device storage). All are strictly
 * necessary for a function the user asked for (§ 25 (2) no. 2 TDDDG), so there is no consent banner.
 * A new key in resources/js → add it here. DRAFT, to be confirmed by a lawyer (docs/legal/README.md).
 */

/** Umami's own opt-out switch: the tracker sends nothing while this key is set. */
const UMAMI_OPT_OUT = 'umami.disabled';

function AnalyticsOptOut() {
    const [optedOut, setOptedOut] = useState<boolean | null>(null);

    useEffect(() => {
        try {
            setOptedOut(localStorage.getItem(UMAMI_OPT_OUT) !== null);
        } catch {
            setOptedOut(false);
        }
    }, []);

    const toggle = () => {
        try {
            if (optedOut) localStorage.removeItem(UMAMI_OPT_OUT);
            else localStorage.setItem(UMAMI_OPT_OUT, '1');
            setOptedOut(!optedOut);
        } catch {
            // storage blocked: nothing to switch (and nothing is measured with storage blocked either)
        }
    };

    if (optedOut === null) return null;

    return (
        <div className="flex flex-col gap-3 rounded-xl border bg-background p-4 sm:flex-row sm:items-center sm:justify-between" data-testid="analytics-opt-out">
            <p className="text-sm">
                Reach measurement in this browser: <strong data-testid="analytics-state">{optedOut ? 'off' : 'on'}</strong>
            </p>
            <button
                type="button"
                onClick={toggle}
                className="rounded-lg border px-3 py-1.5 text-sm font-medium text-foreground transition-colors hover:bg-muted"
            >
                {optedOut ? 'Allow measurement again' : 'Switch measurement off'}
            </button>
        </div>
    );
}

export default function Cookies({ legal }: { legal: LegalPageData }) {
    const cookies = [
        [legal.session_cookie, 'Keeps you signed in and holds the state of your visit (encrypted).', 'Until 2 hours of inactivity'],
        ['XSRF-TOKEN', 'Protects forms and requests against cross-site request forgery.', '2 hours'],
        ['remember_web_…', 'Only if you tick “Remember me” or sign in with Google, Microsoft or GitHub: keeps you signed in.', 'About 400 days, or until you sign out'],
        ['sidebar_state', 'Remembers whether the sidebar is open.', '7 days'],
    ];

    const storage = [
        ['brainitive-theme', 'Light, dark or system theme.', 'Until you change it'],
        ['sidebar-width-px, sidebar-expanded-…, sidebar-active-project-…', 'Sidebar width, open sections and last project.', 'Until you change it'],
        ['board-…-list-hidden-fields, board-…-collapsed-lanes, board-…-lane-mode, helpdesk-view-…', 'Your view settings of boards and helpdesks.', 'Until you change it'],
        ['page-formatting-toolbar, page-full-width-…', 'Editor toolbar and page width.', 'Until you change it'],
        ['brainitive.palette.recent.…', 'Recently opened items in the command palette.', 'Until you sign out or clear it'],
        ['brainitive:health-draft:…', 'Unsaved draft of a project health report.', 'Until saved or discarded'],
        ['push-prompt-dismissed', 'You dismissed the offer to enable push notifications.', 'Until cleared'],
        ['brainitive-embeds-allowed', 'Services whose embedded content you chose to “Always load”.', 'Until cleared'],
        ['brainitive-offline:…', 'Offline mode switched on, and for which account.', 'Until you switch it off or sign out'],
        ['brainitive-docs-epoch-…, brainitive-whiteboards-epoch-…', 'Version marker of the local copy of a document / whiteboard you co-edit.', 'Cleared on sign-out'],
        ['tz-sync-failed:… (session storage)', 'Avoids retrying a failed time-zone update.', 'Until the tab is closed'],
        ...(legal.analytics ? [[UMAMI_OPT_OUT, 'Only if you switch reach measurement off below.', 'Until you switch it on again']] : []),
    ];

    const databases = [
        ['brainitive-offline-… (IndexedDB)', 'Offline mode: copies of the projects you chose, and changes made while offline.', 'Deleted when you sign out'],
        ['brainitive-docs-…, brainitive-whiteboards-… (IndexedDB)', 'Local copy of a document or whiteboard while you co-edit it, so edits are not lost when the connection drops.', 'Deleted when you sign out'],
        ['brainitive-offline-shell-v1 (Cache Storage)', 'The offline page of the app (no account data).', 'Until the app updates it'],
    ];

    return (
        <LegalLayout
            title="Cookies and storage"
            icon={Cookie}
            legal={legal}
            intro={
                <p>
                    {legal.app_name} only stores what is strictly necessary for the functions you use (§ 25 (2) no. 2 TDDDG): signing in,
                    security and your own preferences. We use no advertising, tracking or third-party cookies, so we do not ask for consent with a
                    cookie banner. This page lists everything we store on your device.
                </p>
            }
        >
            <LegalSection id="cookies" num={1} title="Cookies">
                <LegalTable head={['Name', 'Purpose', 'Lifetime']} rows={cookies} />
                <p>All cookies are set by this website itself (first party) and are only sent back to it.</p>
            </LegalSection>

            <LegalSection id="storage" num={2} title="Local and session storage">
                <p>The app remembers some settings in your browser’s storage. They never leave your device unless noted.</p>
                <LegalTable head={['Key', 'Purpose', 'Kept']} rows={storage} />
            </LegalSection>

            <LegalSection id="databases" num={3} title="Databases in your browser">
                <LegalTable head={['Name', 'Purpose', 'Kept']} rows={databases} />
            </LegalSection>

            {legal.analytics && (
                <LegalSection id="analytics" num={4} title="Reach measurement">
                    <p>
                        On www.brainitive.app we count page views with Umami, which we run on our own server. It sets no cookies and stores nothing
                        on your device, and it respects your browser’s <em>Do Not Track</em> setting. Details are in our{' '}
                        <a href={legal.privacy}>Privacy Policy</a>. You can switch it off for this browser here:
                    </p>
                    <AnalyticsOptOut />
                </LegalSection>
            )}

            <LegalSection id="embeds" num={legal.analytics ? 5 : 4} title="Embedded content">
                <p>
                    Videos (YouTube), Figma files and posts from X that someone embeds in a document load only after you click{' '}
                    <em>Load content</em>. From then on that service receives your IP address and may set its own cookies under its own privacy
                    policy. “Always load” remembers your choice for that service in this browser (<code>brainitive-embeds-allowed</code>); clear
                    your browser storage to undo it.
                </p>
            </LegalSection>

            <LegalSection id="control" num={legal.analytics ? 6 : 5} title="Your control">
                <p>
                    You can delete cookies and site data in your browser settings at any time. Without the session and security cookies you cannot
                    sign in; without the other entries the app forgets your preferences.
                </p>
            </LegalSection>
        </LegalLayout>
    );
}
