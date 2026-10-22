import { api } from '@/lib/client';
import { useSession } from '@/lib/hooks';
import { send } from '@/lib/messages';
import type { Me } from '@/lib/types';
import { useEffect, useState } from 'react';
import { browser } from 'wxt/browser';
import SignIn from './SignIn';

/** Options page: account, server, shortcuts. Also where Firefox finishes a self-hosted sign-in. */
export default function Options() {
    const { session, loading } = useSession();
    const [me, setMe] = useState<Me | null>(null);
    const [error, setError] = useState<string | null>(null);
    const initialServer = new URLSearchParams(location.search).get('server') ?? undefined;

    useEffect(() => {
        if (!session) return;
        api.me().then(setMe).catch((e: Error) => setError(e.message));
    }, [session]);

    if (loading) return null;

    return (
        <main style={{ maxWidth: 520, margin: '32px auto', padding: '0 16px' }}>
            <h1 style={{ fontSize: 20 }}>Brainitive extension</h1>
            {!session ? (
                <div style={{ border: '1px solid var(--border)', borderRadius: 12 }}>
                    <SignIn initialServer={initialServer} />
                </div>
            ) : (
                <section className="body" style={{ border: '1px solid var(--border)', borderRadius: 12 }}>
                    {error && <div className="banner">{error}</div>}
                    <div>
                        <div className="hint">Signed in to</div>
                        <strong>{me?.instance.name ?? session.server}</strong> <span className="muted">({session.server})</span>
                    </div>
                    {me && (
                        <div>
                            <div className="hint">As</div>
                            <strong>{me.user.name}</strong> <span className="muted">{me.user.email}</span>
                        </div>
                    )}
                    <div className="actions" style={{ justifyContent: 'flex-start' }}>
                        <button className="button" onClick={() => send({ type: 'sign-out' })}>
                            Sign out
                        </button>
                    </div>
                </section>
            )}

            <h2 style={{ fontSize: 15, marginTop: 28 }}>Shortcuts</h2>
            <ul className="muted" style={{ paddingLeft: 18, lineHeight: 1.9 }}>
                <li>
                    <kbd>Alt</kbd> + <kbd>Shift</kbd> + <kbd>B</kbd> — open the popup
                </li>
                <li>
                    <kbd>Alt</kbd> + <kbd>Shift</kbd> + <kbd>S</kbd> — screenshot an area into a new issue
                </li>
                <li>
                    Type <kbd>bt</kbd> and a space in the address bar to search Brainitive
                </li>
                <li>Right-click a page, a link or selected text for more</li>
            </ul>
            {!import.meta.env.FIREFOX && (
                <button className="button small" onClick={() => browser.tabs.create({ url: 'chrome://extensions/shortcuts' })}>
                    Change shortcuts
                </button>
            )}

            <h2 style={{ fontSize: 15, marginTop: 28 }}>Privacy</h2>
            <p className="muted">
                The extension only reads a page when you use it there (toolbar button, right-click menu or shortcut), and sends what you
                capture only to your Brainitive server. Sign-in tokens are stored on this device and can only save things and search — they
                can’t read your documents. Signing out removes them.
            </p>
        </main>
    );
}
