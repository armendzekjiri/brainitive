/**
 * Live issue descriptions (BRAI-151): while someone edits an issue's description, everyone editing it
 * shares one Yjs document (same realtime server, Lexical binding and save protocol as documents —
 * useLiveDocument with an item target). The HTML is saved through the normal item update (history,
 * mentions, smart links, webhooks). Plan: docs/ai/tasks/feat-live-basics.md.
 *
 * Availability is asked once per issue when editing starts (`items.collab.session`): 404 / 403 → the
 * single-user editor as before, without a notice (live editing isn't configured or not for this user).
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { PageCollabProps, PageCollabSession } from '@/types';
import { useLiveDocument, type LiveDocumentTarget } from '@/components/editor/collab/useLiveDocument';

interface Options {
    itemId: number | null;
    routeParams: [string, string, string];
    /** The description editor is open. */
    editing: boolean;
    canEdit: boolean;
    user: { id: number; name: string };
    title: string;
}

/** Saves of a description are history entries and webhooks: a little calmer than documents. */
const LOCAL_SAVE_MS = 2_000;
const REMOTE_SAVE_MS = 4_000;
/** No answer to "may I edit live?" within this time → editing alone (asked again next time). */
const PROBE_TIMEOUT_MS = 6_000;

export function useLiveItemDescription({ itemId, routeParams, editing, canEdit, user, title }: Options) {
    /** undefined = not asked yet, null = not available for this issue */
    const [collab, setCollab] = useState<PageCollabProps | null | undefined>(undefined);
    /** the question failed (offline, server error): editing alone this time, asked again next time */
    const [unreachable, setUnreachable] = useState(false);
    const askedFor = useRef<number | null>(null);
    const [workspaceSlug, projectSlug, boardSlug] = routeParams;
    const params = useMemo(() => [workspaceSlug, projectSlug, boardSlug, itemId ?? 0], [workspaceSlug, projectSlug, boardSlug, itemId]);

    useEffect(() => {
        setCollab(undefined);
        setUnreachable(false);
        askedFor.current = null;
    }, [itemId]);

    // the editor closed after an unreachable answer: ask again when it opens next time
    useEffect(() => {
        if (editing || !unreachable) return;
        setUnreachable(false);
        setCollab(undefined);
        askedFor.current = null;
    }, [editing, unreachable]);

    useEffect(() => {
        if (!editing || !canEdit || itemId === null || askedFor.current === itemId) return;
        askedFor.current = itemId;
        let alive = true;
        let answered = false;
        window.axios.post<PageCollabSession>(route('items.collab.session', params), {}, { timeout: PROBE_TIMEOUT_MS })
            .then(({ data }) => {
                answered = true;
                if (alive) setCollab(data.edit ? { epoch: data.epoch, url: data.url } : null);
            })
            .catch((error: { response?: { status?: number } }) => {
                answered = true;
                if (!alive) return;
                const status = error?.response?.status;
                // 404 = live editing off, 403 = not for this user: the single-user editor, no notice
                if (status !== 403 && status !== 404) setUnreachable(true);
                setCollab(null);
            });
        return () => {
            alive = false;
            if (!answered) askedFor.current = null; // the next run asks again
        };
    }, [editing, canEdit, itemId, params]);

    const retryProbe = useCallback(() => {
        setUnreachable(false);
        setCollab(undefined);
        askedFor.current = null;
    }, []);

    const target = useMemo<LiveDocumentTarget | undefined>(() => (itemId === null ? undefined : {
        key: `item-${itemId}`,
        documentName: (epoch) => `item.${itemId}.${epoch}`,
        url: (action) => route(`items.collab.${action}`, params),
        localSaveMs: LOCAL_SAVE_MS,
        remoteSaveMs: REMOTE_SAVE_MS,
        lostEditNotice: 'You can no longer edit this issue. Reload to see the latest version.',
        replacedNotice: 'The description was changed elsewhere. You now see the latest version.',
    }), [itemId, params]);

    const live = useLiveDocument({
        enabled: editing && canEdit && !!collab,
        collab: collab ?? null,
        routeParams,
        user,
        fallback: { name: title, icon: null },
        canUpdate: canEdit,
        onDraftState: () => { /* the issue view refreshes on the item.updated live event */ },
        target,
    });

    const flush = live.flush;
    /** Store the HTML now (Done / closing the editor). */
    const finish = useCallback(async () => (live.mode === 'live' ? flush() : true), [live.mode, flush]);

    return {
        ...live,
        /** still asking whether live editing is available */
        probing: editing && canEdit && collab === undefined,
        /** live editing is configured but couldn't be reached: editing alone, with a notice */
        unreachable,
        retryProbe,
        finish,
    };
}
