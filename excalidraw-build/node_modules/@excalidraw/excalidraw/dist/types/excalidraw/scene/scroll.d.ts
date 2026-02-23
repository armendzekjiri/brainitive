import type { AppState, Offsets, PointerCoords, Zoom } from "../types";
import type { ExcalidrawElement } from "../element/types";
export declare const centerScrollOn: ({ scenePoint, viewportDimensions, zoom, offsets, }: {
    scenePoint: PointerCoords;
    viewportDimensions: {
        height: number;
        width: number;
    };
    zoom: Zoom;
    offsets?: Partial<{
        top: number;
        right: number;
        bottom: number;
        left: number;
    }> | undefined;
}) => {
    scrollX: number;
    scrollY: number;
};
export declare const calculateScrollCenter: (elements: readonly ExcalidrawElement[], appState: AppState) => {
    scrollX: number;
    scrollY: number;
};
