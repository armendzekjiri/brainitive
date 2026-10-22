import { normalizeServer } from '@/lib/auth';
import { send } from '@/lib/messages';
import { getLastServer } from '@/lib/storage';
import { Loader2, LogIn } from 'lucide-react';
import { type FormEvent, useEffect, useState } from 'react';
import { browser } from 'wxt/browser';
import { CLOUD_ORIGIN } from '@/lib/config';

/**
 * Brainitive Cloud or a self-hosted server. A self-hosted origin needs its host permission
 * first — requested here, inside the click, because browsers only grant it on a user gesture.
 */
export default function SignIn({ inPopup = false, initialServer }: { inPopup?: boolean; initialServer?: string }) {
    const [where, setWhere] = useState<'cloud' | 'self'>(initialServer ? 'self' : 'cloud');
    const [server, setServer] = useState(initialServer ?? '');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        getLastServer().then((last) => {
            if (!initialServer && last && last !== CLOUD_ORIGIN) {
                setWhere('self');
                setServer(last);
            }
        });
    }, []);

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setError(null);

        let origin = CLOUD_ORIGIN;
        if (where === 'self') {
            try {
                origin = normalizeServer(server);
            } catch {
                setError('Enter the address of your Brainitive server, e.g. brainitive.example.com');
                return;
            }
            if (origin.startsWith('http://') && !/^http:\/\/(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/.test(origin)) {
                setError('Use https:// — sign-in tokens must not travel unencrypted.');
                return;
            }
            if (import.meta.env.FIREFOX && inPopup) {
                // Firefox closes the popup when it shows the permission prompt: continue in a tab.
                await browser.tabs.create({ url: browser.runtime.getURL(`/options.html?server=${encodeURIComponent(origin)}`) });
                window.close();
                return;
            }
            const granted = await browser.permissions.request({ origins: [`${origin}/*`] });
            if (!granted) {
                setError(`The extension needs access to ${origin} to talk to your server.`);
                return;
            }
        }

        setBusy(true);
        try {
            // The popup usually closes while the sign-in window is open; the background
            // reopens it when sign-in finishes.
            await send({ type: 'sign-in', server: origin });
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    };

    return (
        <form className="center" onSubmit={submit}>
            <img className="logo" src="/icon/128.png" alt="" />
            <h1>Save anything to Brainitive</h1>
            <p className="muted" style={{ margin: 0 }}>
                Issues with screenshots, bookmarks, clipped docs and tasks — from any page.
            </p>

            <div className="segmented" style={{ width: '100%' }}>
                <button type="button" aria-pressed={where === 'cloud'} onClick={() => setWhere('cloud')}>
                    Brainitive Cloud
                </button>
                <button type="button" aria-pressed={where === 'self'} onClick={() => setWhere('self')}>
                    Self-hosted
                </button>
            </div>

            {where === 'self' && (
                <label className="field" style={{ width: '100%', textAlign: 'left' }}>
                    <span>Server address</span>
                    <input
                        type="text"
                        value={server}
                        onChange={(e) => setServer(e.target.value)}
                        placeholder="brainitive.example.com"
                        autoFocus
                        required
                    />
                </label>
            )}

            {error && (
                <div className="banner" style={{ width: '100%', textAlign: 'left' }}>
                    {error}
                </div>
            )}

            <button type="submit" className="button primary" style={{ width: '100%' }} disabled={busy}>
                {busy ? <Loader2 size={16} className="spin" /> : <LogIn size={16} />}
                {busy ? 'Waiting for sign-in…' : 'Sign in'}
            </button>
            <p className="hint" style={{ margin: 0 }}>
                You sign in on {where === 'cloud' ? 'brainitive.app' : 'your server'}; the extension never sees your password.
            </p>
        </form>
    );
}
