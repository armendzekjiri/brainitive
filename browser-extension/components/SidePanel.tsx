import { api } from '@/lib/client';
import { useSession } from '@/lib/hooks';
import type { SearchHit, WorkItem } from '@/lib/types';
import { Loader2, Plus, RefreshCw, Search } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { browser } from 'wxt/browser';
import SignIn from './SignIn';

/** Side panel (Chrome / Edge) or sidebar (Firefox): My Work and search, next to the page. */
export default function SidePanel() {
    const { session, loading } = useSession();
    if (loading) return null;
    if (!session) return <SignIn />;
    return <Panel key={session.server} />;
}

function Panel() {
    const [query, setQuery] = useState('');
    const [hits, setHits] = useState<SearchHit[] | null>(null);
    const [work, setWork] = useState<WorkItem[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [nonce, setNonce] = useState(0);

    useEffect(() => {
        setError(null);
        api.myWork()
            .then(({ items }) => setWork(items))
            .catch((e: Error) => setError(e.message));
    }, [nonce]);

    // Refresh My Work when the panel comes back into view.
    useEffect(() => {
        const onVisible = () => document.visibilityState === 'visible' && setNonce((n) => n + 1);
        document.addEventListener('visibilitychange', onVisible);
        return () => document.removeEventListener('visibilitychange', onVisible);
    }, []);

    useEffect(() => {
        const q = query.trim();
        if (q.length < 2) {
            setHits(null);
            return;
        }
        const timer = setTimeout(() => {
            api.search(q)
                .then((r) => setHits([...r.items, ...r.pages, ...r.projects, ...r.bookmarks, ...r.snippets, ...r.workspaces]))
                .catch((e: Error) => setError(e.message));
        }, 250);
        return () => clearTimeout(timer);
    }, [query]);

    const today = new Date().toISOString().slice(0, 10);
    const groups = useMemo(() => {
        if (!work) return [];
        const overdue = work.filter((w) => w.due_date && w.due_date < today);
        const dated = work.filter((w) => w.due_date && w.due_date >= today);
        const undated = work.filter((w) => !w.due_date);
        return [
            { title: 'Overdue', items: overdue },
            { title: 'Coming up', items: dated },
            { title: 'No due date', items: undated },
        ].filter((g) => g.items.length > 0);
    }, [work, today]);

    const open = (url: string) => (event: React.MouseEvent) => {
        event.preventDefault();
        void browser.tabs.create({ url });
    };

    return (
        <>
            <header className="header">
                <div className="brand">
                    <img src="/icon/32.png" alt="" />
                    <span>My Work</span>
                </div>
                <button className="icon-button" onClick={() => setNonce((n) => n + 1)} title="Refresh" aria-label="Refresh">
                    <RefreshCw size={15} />
                </button>
                <button className="icon-button" onClick={() => browser.action.openPopup().catch(() => undefined)} title="Capture this page" aria-label="Capture this page">
                    <Plus size={16} />
                </button>
            </header>

            <div className="body" style={{ paddingBottom: 0 }}>
                <label style={{ position: 'relative' }}>
                    <Search size={14} className="muted" style={{ position: 'absolute', left: 9, top: 9 }} />
                    <input type="text" value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search Brainitive" style={{ paddingLeft: 28 }} aria-label="Search Brainitive" />
                </label>
                {error && <div className="banner">{error}</div>}
            </div>

            {hits ? (
                <div className="list" style={{ marginTop: 8 }}>
                    {hits.length === 0 && <p className="hint" style={{ padding: '0 12px' }}>Nothing found.</p>}
                    {hits.map((hit) => (
                        <a key={`${hit.type}-${hit.id}`} href={hit.url} onClick={open(hit.url)}>
                            <strong>{hit.title}</strong>
                            <span className="meta">
                                <span>{hit.type}</span>
                                {hit.subtitle && <span>{hit.subtitle}</span>}
                            </span>
                        </a>
                    ))}
                </div>
            ) : !work ? (
                <div className="center">
                    <Loader2 className="spin muted" size={20} />
                </div>
            ) : groups.length === 0 ? (
                <div className="center">
                    <p className="muted">Nothing assigned to you is open. 🎉</p>
                </div>
            ) : (
                groups.map((group) => (
                    <section key={group.title}>
                        <div className="section-title">{group.title}</div>
                        <div className="list">
                            {group.items.map((item) => (
                                <a key={`${item.type}-${item.id}`} href={item.url} onClick={open(item.url)}>
                                    <strong>{item.title}</strong>
                                    <span className="meta">
                                        {item.key && <span>{item.key}</span>}
                                        {item.status && <span>{item.status}</span>}
                                        <span>{item.project}</span>
                                        {item.due_date && <span className={item.due_date < today ? 'overdue' : ''}>Due {item.due_date}</span>}
                                    </span>
                                </a>
                            ))}
                        </div>
                    </section>
                ))
            )}
        </>
    );
}
