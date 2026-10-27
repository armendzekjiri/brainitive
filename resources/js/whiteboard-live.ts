/**
 * Entry of the whiteboard editor's live drawing (BRAI-153), loaded by excalidraw-editor.blade.php next
 * to the Excalidraw bundle when the realtime server is configured. Plan: docs/ai/tasks/feat-live-whiteboards.md.
 */
import { startLiveWhiteboard } from '@/lib/whiteboard/live';

declare global {
    interface Window {
        BrainitiveWhiteboardLive?: { start: typeof startLiveWhiteboard };
    }
}

window.BrainitiveWhiteboardLive = { start: startLiveWhiteboard };
