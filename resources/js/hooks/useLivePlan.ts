/**
 * Live plans (BRAI-152): listen to `plan.{id}`.
 * - `plan.updated` {revision, fields} → `onSaved(revision)` (the editor follows it when idle, or lets
 *   its revision guard raise the conflict) and, for a rename / share change, `onDetails()`;
 * - `issues.changed` {ids} (a linked issue changed on its board) → `onIssues()`, debounced;
 * - `plan.deleted` → `onDeleted()`; `onResync` after a reconnect (events of the gap are lost).
 * This tab's own echoes are ignored (`client_id`).
 */
import { useEffect, useRef } from 'react';
import { useLiveChannel } from '@/lib/live';
import { installLiveClientId, isOwnEcho, useMyUserIdRef } from '@/lib/liveClientId';

interface LivePlanHandlers {
    onSaved: (revision: number) => void;
    onIssues: () => void;
    onDetails: () => void;
    onDeleted: () => void;
    onResync: () => void;
}

const DETAIL_FIELDS = ['name', 'description', 'share_token'];
const ISSUES_DEBOUNCE_MS = 400;

export function useLivePlan(planId: number, handlers: LivePlanHandlers) {
    useEffect(installLiveClientId, []);
    const me = useMyUserIdRef();
    const latest = useRef(handlers);
    latest.current = handlers;
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => () => {
        if (timer.current !== null) clearTimeout(timer.current);
        timer.current = null;
    }, []);

    return useLiveChannel(`plan.${planId}`, {
        onEvent: (event) => {
            const payload = event.payload ?? {};
            if (isOwnEcho(payload, me.current)) return;
            if (event.event === 'plan.updated') {
                if (typeof payload.revision === 'number') latest.current.onSaved(payload.revision);
                const fields = Array.isArray(payload.fields) ? payload.fields : [];
                if (fields.some((field) => DETAIL_FIELDS.includes(String(field)))) latest.current.onDetails();
            } else if (event.event === 'issues.changed') {
                if (timer.current === null) {
                    timer.current = setTimeout(() => {
                        timer.current = null;
                        latest.current.onIssues();
                    }, ISSUES_DEBOUNCE_MS);
                }
            } else if (event.event === 'plan.deleted') {
                latest.current.onDeleted();
            }
        },
        onResync: () => latest.current.onResync(),
    });
}
