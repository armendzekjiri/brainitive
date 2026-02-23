import type { AppState, NormalizedZoomValue } from "../types";
export declare const getStateForZoom: ({ viewportX, viewportY, nextZoom, }: {
    viewportX: number;
    viewportY: number;
    nextZoom: NormalizedZoomValue;
}, appState: AppState) => {
    scrollX: number;
    scrollY: number;
    zoom: {
        value: NormalizedZoomValue;
    };
};
