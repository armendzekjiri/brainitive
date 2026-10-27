/**
 * The whiteboard preview on a page watches the live whiteboard (BRAI-153): everyone's drawing and
 * cursors appear as they happen, read-only (a `view=1` connection, even for editors — drawing happens
 * in the editor). Plan: docs/ai/tasks/feat-live-whiteboards.md.
 */
import { useEffect, useState } from 'react';
import type { WhiteboardCollabProps } from '@/types';
import { startLiveWhiteboard, type ExcalidrawStatics, type WhiteboardHandle, type WhiteboardLiveState } from '@/lib/whiteboard/live';

interface Options {
    canvas: { handle: WhiteboardHandle; statics: ExcalidrawStatics } | null;
    collab: WhiteboardCollabProps | null;
    routeParams: [string, string, string];
    user: { id: number; name: string };
}

export function useLiveWhiteboardView({ canvas, collab, routeParams, user }: Options): WhiteboardLiveState | null {
    const [state, setState] = useState<WhiteboardLiveState | null>(null);
    const [workspace, project, pageUuid] = routeParams;

    useEffect(() => {
        setState(null);
        if (!canvas || !collab) return;
        const live = startLiveWhiteboard(canvas.handle, canvas.statics, {
            url: collab.url,
            epoch: collab.epoch,
            pageUuid,
            user,
            sessionUrl: route('pages.whiteboard.collab.session', [workspace, project, pageUuid]),
            view: true,
        }, setState);
        return () => live.destroy();
        // eslint-disable-next-line react-hooks/exhaustive-deps -- one connection per canvas / epoch
    }, [canvas, collab?.url, collab?.epoch, workspace, project, pageUuid, user.id]);

    return state;
}
