import { api } from '@/lib/client';
import { useDestinations, useSession } from '@/lib/hooks';
import { send } from '@/lib/messages';
import { isRestrictedUrl, readSelection } from '@/lib/page';
import { getLastMode, setLastMode, takePendingCapture } from '@/lib/storage';
import type { Created, Mode } from '@/lib/types';
import { Bookmark, CheckCircle2, Code2, ExternalLink, FileText, ListTodo, Loader2, LogOut, PanelRight, RefreshCw, SquareKanban } from 'lucide-react';
import { useEffect, useState } from 'react';
import { browser } from 'wxt/browser';
import { BookmarkForm, DocForm, IssueForm, type PageContext, SnippetForm, TaskForm } from './forms';
import SignIn from './SignIn';

const MODES: { mode: Mode; label: string; icon: typeof SquareKanban }[] = [
    { mode: 'issue', label: 'Issue', icon: SquareKanban },
    { mode: 'bookmark', label: 'Bookmark', icon: Bookmark },
    { mode: 'doc', label: 'Doc', icon: FileText },
    { mode: 'task', label: 'Task', icon: ListTodo },
    { mode: 'snippet', label: 'Snippet', icon: Code2 },
];

/** Popup: the toolbar button, a context-menu hand-off, or the same page in a small window. */
export default function Popup() {
    const { session, loading } = useSession();

    if (loading) return null;
    if (!session) return <SignIn inPopup />;
    return <Capture server={session.server} />;
}

function Capture({ server }: { server: string }) {
    const [mode, setMode] = useState<Mode | null>(null);
    const [page, setPage] = useState<PageContext | null>(null);
    const [handOffError, setHandOffError] = useState<string | null>(null);
    const [created, setCreated] = useState<{ created: Created; label: string } | null>(null);
    const { destinations, error, reload } = useDestinations(api.destinations);

    useEffect(() => {
        (async () => {
            const pending = await takePendingCapture();
            const tab = pending?.tabId
                ? await browser.tabs.get(pending.tabId).catch(() => undefined)
                : (await browser.tabs.query({ active: true, lastFocusedWindow: true, windowType: 'normal' }))[0];

            const url = pending?.pageUrl ?? tab?.url ?? '';
            const restricted = isRestrictedUrl(url);
            // Read the selection from the page even after a right-click: the menu's
            // selectionText drops line breaks, which ruins code.
            const selection = tab?.id && !restricted ? await readSelection(tab.id) : null;
            const fromPage = selection?.text.trim() ? selection : null;

            setPage({
                tabId: tab?.id,
                windowId: tab?.windowId,
                url: /^https?:/.test(url) ? url : '',
                title: pending?.pageTitle ?? tab?.title ?? '',
                selectionText: fromPage?.text ?? pending?.selectionText ?? '',
                selectionHtml: fromPage?.html ?? '',
                codeLanguage: fromPage?.codeLanguage ?? '',
                inCode: fromPage?.inCode ?? false,
                linkUrl: pending?.linkUrl,
                restricted,
                screenshot: pending?.screenshot,
                clipWholePage: pending?.clipWholePage,
            });
            setHandOffError(pending?.error ?? null);
            setMode(pending?.mode ?? (await getLastMode()));
        })();
    }, []);

    const choose = (next: Mode) => {
        setMode(next);
        setCreated(null);
        void setLastMode(next);
    };

    const openInApp = (url: string) => {
        void browser.tabs.create({ url });
        window.close();
    };

    const openSidePanel = async () => {
        if (import.meta.env.FIREFOX) {
            await (browser as unknown as { sidebarAction: { open: () => Promise<void> } }).sidebarAction.open();
        } else if (page?.windowId) {
            await browser.sidePanel.open({ windowId: page.windowId });
        }
        window.close();
    };

    const signOut = async () => {
        await send({ type: 'sign-out' });
    };

    const host = new URL(server).host;

    return (
        <>
            <header className="header">
                <div className="brand">
                    <img src="/icon/32.png" alt="" />
                    <span title={server}>{host === 'www.brainitive.app' ? 'Brainitive' : host}</span>
                </div>
                <button className="icon-button" onClick={reload} title="Reload projects and boards" aria-label="Reload projects and boards">
                    <RefreshCw size={15} />
                </button>
                <button className="icon-button" onClick={openSidePanel} title="My Work in the side panel" aria-label="Open the side panel">
                    <PanelRight size={15} />
                </button>
                <button className="icon-button" onClick={() => openInApp(server)} title="Open Brainitive" aria-label="Open Brainitive">
                    <ExternalLink size={15} />
                </button>
                <button className="icon-button" onClick={signOut} title="Sign out" aria-label="Sign out">
                    <LogOut size={15} />
                </button>
            </header>

            <nav className="tabs" role="tablist">
                {MODES.map(({ mode: m, label, icon: Icon }) => (
                    <button key={m} role="tab" className="tab" aria-selected={mode === m} onClick={() => choose(m)}>
                        <Icon size={17} />
                        {label}
                    </button>
                ))}
            </nav>

            {handOffError && (
                <div className="body" style={{ paddingBottom: 0 }}>
                    <div className="banner">{handOffError}</div>
                </div>
            )}

            {created ? (
                <div className="success">
                    <div className="tick">
                        <CheckCircle2 size={24} />
                    </div>
                    <h2>{created.label}</h2>
                    <p className="muted" style={{ margin: 0 }}>
                        {created.created.location.breadcrumb}
                    </p>
                    <div className="row" style={{ width: '100%' }}>
                        <button className="button" onClick={() => setCreated(null)}>
                            Add another
                        </button>
                        <button className="button primary" onClick={() => openInApp(created.created.location.url)}>
                            Open <ExternalLink size={14} />
                        </button>
                    </div>
                </div>
            ) : !page || !mode || !destinations ? (
                <div className="center">
                    {error ? <div className="banner">{error}</div> : <Loader2 className="spin muted" size={20} />}
                </div>
            ) : (
                (() => {
                    const props = { page, destinations, onCreated: (c: Created, label: string) => setCreated({ created: c, label }) };
                    switch (mode) {
                        case 'issue':
                            return <IssueForm key="issue" {...props} />;
                        case 'bookmark':
                            return <BookmarkForm key="bookmark" {...props} />;
                        case 'doc':
                            return <DocForm key="doc" {...props} />;
                        case 'task':
                            return <TaskForm key="task" {...props} />;
                        case 'snippet':
                            return <SnippetForm key="snippet" {...props} />;
                    }
                })()
            )}
            {error && destinations && (
                <p className="hint" style={{ padding: '0 12px 10px', margin: 0 }}>
                    Couldn’t refresh the list ({error}). Showing the last one.
                </p>
            )}
        </>
    );
}
