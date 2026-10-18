import { useEffect, useRef, useState } from 'react';
import { PageDraftState, PagePresenceUser } from '@/types';

const HEARTBEAT_MS = 20_000;

/**
 * "Who else is on this page" via a heartbeat (no websockets needed). Each beat also
 * returns the page's current edit revision so editors notice concurrent saves early.
 */
export function usePagePresence(url: string, editing: boolean, onState?: (state: PageDraftState) => void) {
    const [users, setUsers] = useState<PagePresenceUser[]>([]);
    const editingRef = useRef(editing);
    const onStateRef = useRef(onState);
    onStateRef.current = onState;

    useEffect(() => {
        let cancelled = false;

        const beat = () => {
            window.axios
                .post(url, { editing: editingRef.current })
                .then(({ data }: { data: PageDraftState & { users: PagePresenceUser[] } }) => {
                    if (cancelled) return;
                    setUsers(data.users);
                    onStateRef.current?.(data);
                })
                .catch(() => { /* presence is best-effort */ });
        };

        beat();
        const timer = window.setInterval(beat, HEARTBEAT_MS);

        const leave = () => {
            const token = decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? '');
            fetch(url, {
                method: 'POST',
                keepalive: true,
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ leave: true }),
            }).catch(() => {});
        };
        window.addEventListener('pagehide', leave);

        return () => {
            cancelled = true;
            window.clearInterval(timer);
            window.removeEventListener('pagehide', leave);
            leave();
        };
    }, [url]);

    // Tell the others right away when we start or stop editing
    useEffect(() => {
        if (editingRef.current === editing) return;
        editingRef.current = editing;
        window.axios.post(url, { editing }).then(({ data }: { data: { users: PagePresenceUser[] } }) => setUsers(data.users)).catch(() => {});
    }, [url, editing]);

    return users;
}
