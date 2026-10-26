import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { Head } from '@inertiajs/react';
import { Brain, CloudOff, Lightbulb, ListChecks, Sun, Wifi } from 'lucide-react';
import { Toaster } from 'sonner';
import OfflineCaptureView from '@/components/offline/OfflineCaptureView';
import OfflineIndicator from '@/components/offline/OfflineIndicator';
import OfflineIssueView from '@/components/offline/OfflineIssueView';
import OfflineMyDayView from '@/components/offline/OfflineMyDayView';
import OfflineTaskListView from '@/components/offline/OfflineTaskListView';
import { useSnapshot, useSnapshotList } from '@/components/offline/useSnapshot';
import { bootUrl } from '@/lib/offline/bootLocation';
import { activateOffline } from '@/lib/offline/session';
import { activeOfflineUser, isOfflineEnabled } from '@/lib/offline/settings';
import { useOfflineState } from '@/lib/offline/store';
import { cn } from '@/lib/utils';
import type { OfflineIssueSnapshot, OfflineTaskListSnapshot } from '@/types';

type OfflineRoute =
    | { view: 'myday' }
    | { view: 'capture' }
    | { view: 'list'; path: string }
    | { view: 'issue'; id: number }
    | { view: 'home'; missing: boolean };

/** Which offline view answers the address the user opened (pure, exported for reuse). */
export function offlineRoute(url: string): OfflineRoute {
    const parsed = new URL(url, 'http://offline.invalid');
    const path = parsed.pathname.replace(/\/+$/, '') || '/';
    if (path === '/my-work') return { view: 'myday' };
    if (path === '/captures') return { view: 'capture' };
    if (/^\/workspaces\/[^/]+\/projects\/[^/]+\/task-lists\/[^/]+$/.test(path)) return { view: 'list', path };
    const issue = /^\/workspaces\/[^/]+\/projects\/[^/]+\/boards\/[^/]+\/items\/(\d+)$/.exec(path);
    if (issue) return { view: 'issue', id: Number(issue[1]) };
    // a board with an issue open in its popup (#item-12)
    const popup = /^#item-(\d+)$/.exec(parsed.hash);
    if (popup && /^\/workspaces\/[^/]+\/projects\/[^/]+\/boards\/[^/]+$/.test(path)) return { view: 'issue', id: Number(popup[1]) };
    return { view: 'home', missing: !['/', '/offline', '/dashboard', '/workspaces'].includes(path) };
}

/**
 * The offline app (BRAI-82). The service worker serves this page for any address that can't load
 * without internet; it shows the matching offline view from IndexedDB, or what is available.
 * Rendered without a session: the account is the one that turned offline mode on here.
 */
export default function OfflineShell() {
    const route = useMemo(() => offlineRoute(bootUrl), []);
    const [userId] = useState(() => activeOfflineUser());
    const enabled = isOfflineEnabled(userId);
    const state = useOfflineState();

    useEffect(() => {
        // Inertia rewrote the address to /offline: show the one the user opened.
        if (window.location.pathname + window.location.search + window.location.hash !== bootUrl) {
            window.history.replaceState(window.history.state, '', bootUrl);
        }
        if (enabled && userId) void activateOffline(userId);
    }, [enabled, userId]);

    return (
        <div className="flex min-h-screen flex-col bg-muted/30 text-foreground">
            <Head title="Offline" />
            <header className="sticky top-0 z-20 flex h-12 shrink-0 items-center gap-2 border-b bg-background/95 px-3 backdrop-blur sm:gap-3 sm:px-4">
                <a href="/offline" className="flex items-center gap-1.5 font-semibold" aria-label="Offline home">
                    <Brain className="h-5 w-5 text-primary" /> <span className="hidden sm:inline">Brainitive</span>
                </a>
                {enabled && (
                    <nav className="flex min-w-0 items-center gap-1 text-sm" aria-label="Offline">
                        <NavLink href="/my-work" active={route.view === 'myday'} icon={<Sun className="h-4 w-4" />}>My Day</NavLink>
                        <NavLink href="/captures" active={route.view === 'capture'} icon={<Lightbulb className="h-4 w-4" />}>Capture</NavLink>
                    </nav>
                )}
                <div className="flex-1" />
                <OfflineIndicator />
            </header>

            {state.online && (
                <div className="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 border-b bg-primary/5 px-4 py-2 text-sm" role="status">
                    <Wifi className="h-4 w-4 text-primary" aria-hidden />
                    <span>You’re back online.{state.outbox.length > 0 ? ' Sending your changes…' : ''}</span>
                    <a href={route.view === 'home' ? '/my-work' : bootUrl} className="font-medium text-primary hover:underline">Open the live page</a>
                </div>
            )}

            <main className="flex-1">
                {!enabled || !userId ? <NotEnabled /> : <View route={route} />}
            </main>
            <Toaster richColors position="bottom-right" closeButton />
        </div>
    );
}

function NavLink({ href, active, icon, children }: { href: string; active: boolean; icon: ReactNode; children: ReactNode }) {
    return (
        <a
            href={href}
            aria-current={active ? 'page' : undefined}
            className={cn('flex items-center gap-1.5 rounded-lg px-2 py-1.5 text-muted-foreground hover:bg-muted hover:text-foreground', active && 'bg-muted font-medium text-foreground')}
        >
            {icon}
            <span className="hidden sm:inline">{children}</span>
        </a>
    );
}

function View({ route }: { route: OfflineRoute }) {
    if (route.view === 'myday') return <OfflineMyDayView />;
    if (route.view === 'capture') return <OfflineCaptureView />;
    if (route.view === 'list') return <ListByPath path={route.path} />;
    if (route.view === 'issue') return <IssueById id={route.id} />;
    return <OfflineHome missing={route.missing} />;
}

function ListByPath({ path }: { path: string }) {
    const { records, loading } = useSnapshotList<OfflineTaskListSnapshot>('list');
    if (loading) return null;
    const list = records.find((r) => r.data.url === path)?.data;
    return list ? <OfflineTaskListView list={list} /> : <OfflineHome missing />;
}

function IssueById({ id }: { id: number }) {
    const { data, loading } = useSnapshot<OfflineIssueSnapshot>(`issue:${id}`);
    if (loading) return null;
    return data ? <OfflineIssueView issue={data} /> : <OfflineHome missing />;
}

/** What works offline, with links — also the answer for every page that isn't available. */
function OfflineHome({ missing }: { missing: boolean }) {
    const { records: lists } = useSnapshotList<OfflineTaskListSnapshot>('list');
    const { records: issues } = useSnapshotList<OfflineIssueSnapshot>('issue');

    return (
        <div className="mx-auto max-w-3xl px-4 py-8 sm:px-6">
            <div className="flex items-start gap-3">
                <CloudOff className="mt-1 h-6 w-6 shrink-0 text-muted-foreground" aria-hidden />
                <div>
                    <h1 className="text-2xl font-bold tracking-tight">{missing ? 'This page isn’t available offline' : 'You’re offline'}</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {missing ? 'It wasn’t saved on this device. ' : ''}
                        These work without internet; your changes sync when you’re back online.
                    </p>
                </div>
            </div>

            <div className="mt-6 grid gap-3 sm:grid-cols-2">
                <a href="/my-work" className="flex items-center gap-3 rounded-2xl border bg-card p-4 hover:border-primary/40">
                    <Sun className="h-5 w-5 text-amber-500" /> <span className="font-medium">My Day</span>
                </a>
                <a href="/captures" className="flex items-center gap-3 rounded-2xl border bg-card p-4 hover:border-primary/40">
                    <Lightbulb className="h-5 w-5 text-amber-500" /> <span className="font-medium">Quick capture</span>
                </a>
            </div>

            <section className="mt-8" aria-label="Task lists on this device">
                <h2 className="text-sm font-semibold">Task lists you opened recently</h2>
                {lists.length === 0 ? (
                    <p className="mt-2 text-sm text-muted-foreground">None yet — lists you open while online are kept here.</p>
                ) : (
                    <ul className="mt-2 divide-y rounded-2xl border bg-card">
                        {lists.map(({ data }) => (
                            <li key={data.id}>
                                <a href={data.url} className="flex items-center gap-2 px-4 py-2.5 text-sm hover:bg-muted/50">
                                    <ListChecks className="h-4 w-4 shrink-0 text-primary" />
                                    <span className="min-w-0 truncate font-medium">{data.name}</span>
                                    <span className="ml-auto shrink-0 truncate text-xs text-muted-foreground">{data.project.name}</span>
                                </a>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <section className="mt-8" aria-label="Issues on this device">
                <h2 className="text-sm font-semibold">Issues you opened recently</h2>
                {issues.length === 0 ? (
                    <p className="mt-2 text-sm text-muted-foreground">None yet — issues you open while online are kept here.</p>
                ) : (
                    <ul className="mt-2 divide-y rounded-2xl border bg-card">
                        {issues.map(({ data }) => (
                            <li key={data.id}>
                                <a href={data.url} className="flex items-center gap-2 px-4 py-2.5 text-sm hover:bg-muted/50">
                                    <span className="shrink-0 font-mono text-xs text-muted-foreground">{data.key}</span>
                                    <span className="min-w-0 truncate font-medium">{data.title}</span>
                                </a>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </div>
    );
}

function NotEnabled() {
    return (
        <div className="mx-auto max-w-md px-4 py-16 text-center">
            <CloudOff className="mx-auto h-10 w-10 text-muted-foreground" aria-hidden />
            <h1 className="mt-4 text-2xl font-bold tracking-tight">You’re offline</h1>
            <p className="mt-2 text-sm text-muted-foreground">
                Brainitive needs a connection for this page. Turn on “Work offline on this device” in your profile to keep using My Day,
                task lists, issues and quick capture without internet.
            </p>
            <a href={bootUrl} className="mt-6 inline-block rounded-xl bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90">
                Try again
            </a>
        </div>
    );
}
