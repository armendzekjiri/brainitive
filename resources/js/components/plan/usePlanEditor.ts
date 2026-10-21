import { useCallback, useEffect, useRef, useState } from 'react';
import { PlanDocument } from './types';

export type SaveState = 'saved' | 'dirty' | 'saving' | 'error' | 'conflict';

export interface PlanConflict {
    edit_revision: number;
    updated_by: { id: number; name: string } | null;
    updated_at: string | null;
}

interface Options {
    initial: PlanDocument;
    revision: number;
    saveUrl: string;
    documentUrl: string;
    canEdit: boolean;
}

const AUTOSAVE_DELAY = 1500;
const RETRY_DELAY = 8000;
const HISTORY_LIMIT = 100;

/**
 * Document state for the planner: undo / redo, gestures (a drag is one undo step) and
 * autosave guarded by the revision this editor last saw (409 → conflict).
 */
export function usePlanEditor({ initial, revision, saveUrl, documentUrl, canEdit }: Options) {
    const [doc, setDoc] = useState<PlanDocument>(initial);
    // Undo / redo stacks live in refs; `historyTick` re-renders when they change
    const past = useRef<PlanDocument[]>([]);
    const future = useRef<PlanDocument[]>([]);
    const [, setHistoryTick] = useState(0);
    const [saveState, setSaveState] = useState<SaveState>('saved');
    const [conflict, setConflict] = useState<PlanConflict | null>(null);
    const [lastSavedAt, setLastSavedAt] = useState<Date | null>(null);

    const docRef = useRef(doc);
    const revisionRef = useRef(revision);
    const savedRef = useRef(initial);
    const gestureBase = useRef<PlanDocument | null>(null);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const inFlight = useRef(false);
    const conflictRef = useRef(false);

    docRef.current = doc;

    const pushPast = (entry: PlanDocument) => {
        past.current = [...past.current.slice(-(HISTORY_LIMIT - 1)), entry];
        future.current = [];
        setHistoryTick((t) => t + 1);
    };

    const persist = useCallback(async (options: { force?: boolean; checkpoint?: boolean } = {}) => {
        if (!canEdit || inFlight.current) return;
        if (conflictRef.current && !options.force) return;
        if (timer.current) clearTimeout(timer.current);
        const sending = docRef.current;
        if (sending === savedRef.current && !options.checkpoint && !options.force) {
            setSaveState('saved');
            return;
        }
        inFlight.current = true;
        setSaveState('saving');
        try {
            const { data } = await window.axios.put(saveUrl, {
                data: sending.data,
                settings: sending.settings,
                base_revision: revisionRef.current,
                force: options.force ?? false,
                checkpoint: options.checkpoint ?? false,
            });
            revisionRef.current = data.edit_revision;
            savedRef.current = sending;
            conflictRef.current = false;
            setConflict(null);
            setLastSavedAt(new Date());
            setSaveState(docRef.current === sending ? 'saved' : 'dirty');
        } catch (error: unknown) {
            const response = (error as { response?: { status: number; data?: { conflict?: PlanConflict } } }).response;
            if (response?.status === 409) {
                conflictRef.current = true;
                setConflict(response.data?.conflict ?? null);
                setSaveState('conflict');
            } else {
                setSaveState('error');
                timer.current = setTimeout(() => persist(), RETRY_DELAY);
            }
        } finally {
            inFlight.current = false;
            if (!conflictRef.current && docRef.current !== savedRef.current && gestureBase.current === null) {
                schedule();
            }
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [canEdit, saveUrl]);

    const schedule = useCallback(() => {
        if (!canEdit || conflictRef.current) return;
        if (timer.current) clearTimeout(timer.current);
        setSaveState('dirty');
        timer.current = setTimeout(() => persist(), AUTOSAVE_DELAY);
    }, [canEdit, persist]);

    /**
     * Change the document as one undo step (or silently inside a gesture). View changes
     * (zoom, columns) pass `history: false`: saved, but not undoable.
     */
    const apply = useCallback((updater: (doc: PlanDocument) => PlanDocument, options: { history?: boolean } = {}) => {
        const current = docRef.current;
        const next = updater(current);
        if (next === current) return;
        docRef.current = next;
        setDoc(next);
        if (gestureBase.current === null) {
            if (options.history !== false) pushPast(current);
            schedule();
        }
    }, [schedule]);

    const beginGesture = useCallback(() => {
        gestureBase.current = docRef.current;
    }, []);

    const endGesture = useCallback(() => {
        const base = gestureBase.current;
        gestureBase.current = null;
        if (base && base !== docRef.current) {
            pushPast(base);
            schedule();
        }
    }, [schedule]);

    const cancelGesture = useCallback(() => {
        const base = gestureBase.current;
        gestureBase.current = null;
        if (base) {
            docRef.current = base;
            setDoc(base);
        }
    }, []);

    const undo = useCallback(() => {
        const previous = past.current[past.current.length - 1];
        if (!previous) return;
        past.current = past.current.slice(0, -1);
        future.current = [docRef.current, ...future.current];
        docRef.current = previous;
        setDoc(previous);
        setHistoryTick((t) => t + 1);
        schedule();
    }, [schedule]);

    const redo = useCallback(() => {
        const next = future.current[0];
        if (!next) return;
        future.current = future.current.slice(1);
        past.current = [...past.current, docRef.current];
        docRef.current = next;
        setDoc(next);
        setHistoryTick((t) => t + 1);
        schedule();
    }, [schedule]);

    /** Swap in a document from the server (load latest, restore); keeps undo so nothing is lost. */
    const replace = useCallback((next: PlanDocument, nextRevision: number) => {
        if (timer.current) clearTimeout(timer.current);
        pushPast(docRef.current);
        docRef.current = next;
        savedRef.current = next;
        revisionRef.current = nextRevision;
        conflictRef.current = false;
        setConflict(null);
        setDoc(next);
        setSaveState('saved');
    }, []);

    const loadLatest = useCallback(async () => {
        const { data } = await window.axios.get(documentUrl);
        replace({ data: data.data, settings: data.settings }, data.edit_revision);
    }, [documentUrl, replace]);

    // Warn before leaving with unsaved changes
    useEffect(() => {
        const onBeforeUnload = (event: BeforeUnloadEvent) => {
            if (docRef.current !== savedRef.current || inFlight.current) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        window.addEventListener('beforeunload', onBeforeUnload);
        return () => window.removeEventListener('beforeunload', onBeforeUnload);
    }, []);

    useEffect(() => () => {
        if (timer.current) clearTimeout(timer.current);
    }, []);

    return {
        doc,
        apply,
        beginGesture,
        endGesture,
        cancelGesture,
        undo,
        redo,
        canUndo: past.current.length > 0,
        canRedo: future.current.length > 0,
        saveState,
        conflict,
        lastSavedAt,
        saveNow: () => persist({ checkpoint: true }),
        overwrite: () => persist({ force: true }),
        loadLatest,
        replace,
        revision: () => revisionRef.current,
    };
}

export type PlanEditor = ReturnType<typeof usePlanEditor>;
