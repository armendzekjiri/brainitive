import { useEffect, useState } from 'react';
import type { OfflineSnapshotRecord } from '@/types';
import { getSnapshot, listSnapshots, onSnapshotChange, useOfflineState } from '@/lib/offline/store';

/** One stored snapshot, re-read whenever sync or an action changed it (BRAI-82). */
export function useSnapshot<T>(key: string | null): { data: T | null; loading: boolean } {
    const { userId } = useOfflineState();
    const [state, setState] = useState<{ data: T | null; loading: boolean }>({ data: null, loading: true });

    useEffect(() => {
        if (!key || !userId) {
            setState({ data: null, loading: !userId });
            return;
        }
        let active = true;
        setState({ data: null, loading: true });
        const load = () => void getSnapshot<T>(key).then((data) => active && setState({ data, loading: false }));
        load();
        const off = onSnapshotChange((changed) => {
            if (changed === key) load();
        });
        return () => {
            active = false;
            off();
        };
    }, [key, userId]);

    return state;
}

/** All snapshots of a kind, most recently opened first. */
export function useSnapshotList<T>(kind: OfflineSnapshotRecord['kind']): { records: OfflineSnapshotRecord<T>[]; loading: boolean } {
    const { userId } = useOfflineState();
    const [state, setState] = useState<{ records: OfflineSnapshotRecord<T>[]; loading: boolean }>({ records: [], loading: true });

    useEffect(() => {
        if (!userId) {
            setState({ records: [], loading: false });
            return;
        }
        let active = true;
        const load = () => void listSnapshots<T>(kind).then((records) => active && setState({ records, loading: false }));
        load();
        const off = onSnapshotChange((key) => {
            if (key.startsWith(`${kind}:`)) load();
        });
        return () => {
            active = false;
            off();
        };
    }, [kind, userId]);

    return state;
}
