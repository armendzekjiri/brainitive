import type { InteractiveCanvasAppState } from "../types";
import type { ScrollBars } from "./types";
import type { ExcalidrawElement } from "../element/types";
export declare const SCROLLBAR_MARGIN = 4;
export declare const SCROLLBAR_WIDTH = 6;
export declare const SCROLLBAR_COLOR = "rgba(0,0,0,0.3)";
export declare const getScrollBars: (elements: readonly ExcalidrawElement[], viewportWidth: number, viewportHeight: number, appState: InteractiveCanvasAppState) => ScrollBars;
export declare const isOverScrollBars: (scrollBars: ScrollBars, x: number, y: number) => {
    isOverEither: boolean;
    isOverHorizontal: boolean;
    isOverVertical: boolean;
};
