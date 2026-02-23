import type { ExcalidrawElement } from "./element/types";
import type { AppState } from "./types";
export declare const moveOneLeft: (allElements: readonly ExcalidrawElement[], appState: AppState) => readonly ExcalidrawElement[];
export declare const moveOneRight: (allElements: readonly ExcalidrawElement[], appState: AppState) => readonly ExcalidrawElement[];
export declare const moveAllLeft: (allElements: readonly ExcalidrawElement[], appState: AppState) => readonly ExcalidrawElement[] | ExcalidrawElement[];
export declare const moveAllRight: (allElements: readonly ExcalidrawElement[], appState: AppState) => readonly ExcalidrawElement[] | ExcalidrawElement[];
