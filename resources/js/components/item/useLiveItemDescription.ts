/**
 * Live issue descriptions (BRAI-151): while someone edits an issue's description, everyone editing it
 * shares one Yjs document (same realtime server, Lexical binding and save protocol as documents —
 * useLiveDocument with an item target). The HTML is saved through the normal item update (history,
 * mentions, smart links, webhooks). Plan: docs/ai/tasks/feat-live-basics.md.
 *
 * Availability is asked once per issue when editing starts (`items.collab.session`): 404 / 403 → the
 * single-user editor as before, without a notice (live editing isn't configured or not for this user).
 * Any other failure → editing alone with a notice that says why, asked again in the background with
 * backoff (BRAI-345); `reconnect()` ("Try again") asks again right away.
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { PageCollabProps, PageCollabSession } from '@/types';
import { useLiveDocument, type LiveDocumentTarget, type LiveFailure } from '@/components/editor/collab/useLiveDocument';
import { describeLiveFailure, retryWithBackoff, sessionFailure } from '@/components/editor/collab/shared';

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
/** No answer to "may I edit live?" within this time → editing alone (asked again in the background). */
const PROBE_TIMEOUT_MS = 6_000;

export function useLiveItemDescription({ itemId, routeParams, editing, canEdit, user, title }: Options) {
    /** undefined = not asked yet, null = not available for this issue */
    const [collab, setCollab] = useState<PageCollabProps | null | undefined>(undefined);
    /** the question failed (offline, server error): editing alone, with the reason */
    const [unreachable, setUnreachable] = useState<LiveFailure | null>(null);
    /** a background question was answered: live editing can start (reconnect()) */
    const [probeRecovered, setProbeRecovered] = useState(false);
    /** bumped by reconnect(): the question is asked again (the effect's other deps don't change) */
    const [probeGeneration, setProbeGeneration] = useState(0);
    const askedFor = useRef<number | null>(null);
    const [workspaceSlug, projectSlug, boardSlug] = routeParams;
    const params = useMemo(() => [workspaceSlug, projectSlug, boardSlug, itemId ?? 0], [workspaceSlug, projectSlug, boardSlug, itemId]);

    const resetProbe = useCallback(() => {
        setUnreachable(null);
        setProbeRecovered(false);
        setCollab(undefined);
        askedFor.current = null;
        setProbeGeneration((value) => value + 1);
    }, []);

    useEffect(() => {
        setCollab(undefined);
        setUnreachable(null);
        setProbeRecovered(false);
        askedFor.current = null;
    }, [itemId]);

    // the editor closed after an unreachable answer: ask again when it opens next time
    useEffect(() => {
        if (editing || !unreachable) return;
        resetProbe();
    }, [editing, unreachable, resetProbe]);

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
                if (status !== 403 && status !== 404) {
                    const failure = sessionFailure(error);
                    console.warn('[live editing] unavailable — editing alone:', describeLiveFailure(failure), failure);
                    setUnreachable(failure);
                }
                setCollab(null);
            });
        return () => {
            alive = false;
            if (!answered) askedFor.current = null; // the next run asks again
        };
    }, [editing, canEdit, itemId, params, probeGeneration]);

    // editing alone after a failed question: keep asking in the background. It never switches by
    // itself — the host does (reconnect()), when nothing written alone can be lost.
    const failedQuestion = !!unreachable;
    useEffect(() => {
        if (!editing || !failedQuestion || probeRecovered) return;
        return retryWithBackoff(async () => {
            try {
                await window.axios.post(route('items.collab.session', params), {}, { timeout: PROBE_TIMEOUT_MS });
            } catch (error) {
                const status = (error as { response?: { status?: number } })?.response?.status;
                if (status === 403 || status === 404) return true; // not for this user any more: stay alone
                setUnreachable(sessionFailure(error));
                return false;
            }
            setProbeRecovered(true);
            return true;
        });
    }, [editing, failedQuestion, probeRecovered, params]);

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
    const liveRetry = live.retry;
    /** "Try again" / switch to live editing: ask again, or rebuild the connection. */
    const reconnect = useCallback(() => (unreachable ? resetProbe() : liveRetry()), [unreachable, resetProbe, liveRetry]);

    return {
        ...live,
        /** still asking whether live editing is available */
        probing: editing && canEdit && collab === undefined,
        /** live editing is configured but couldn't be reached: editing alone, with a notice */
        unreachable: !!unreachable,
        /** why editing alone (the question or the connection failed) */
        failure: unreachable ?? live.failure,
        /** live editing works again: switch with reconnect() */
        recovered: unreachable ? probeRecovered : live.recovered,
        reconnect,
        finish,
    };
}
