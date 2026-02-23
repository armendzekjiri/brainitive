import type { ExcalidrawElement, PointerType, NonDeletedExcalidrawElement, ElementsMap } from "./types";
import type { MaybeTransformHandleType } from "./transformHandles";
import type { AppState, Device, Zoom } from "../types";
import type { Bounds } from "./bounds";
import type { GlobalPoint, LocalPoint } from "@excalidraw/math";
export declare const resizeTest: <Point extends GlobalPoint | LocalPoint>(element: NonDeletedExcalidrawElement, elementsMap: ElementsMap, appState: AppState, x: number, y: number, zoom: Zoom, pointerType: PointerType, device: Device) => MaybeTransformHandleType;
export declare const getElementWithTransformHandleType: (elements: readonly NonDeletedExcalidrawElement[], appState: AppState, scenePointerX: number, scenePointerY: number, zoom: Zoom, pointerType: PointerType, elementsMap: ElementsMap, device: Device) => {
    element: NonDeletedExcalidrawElement;
    transformHandleType: MaybeTransformHandleType;
} | null;
export declare const getTransformHandleTypeFromCoords: <Point extends GlobalPoint | LocalPoint>([x1, y1, x2, y2]: Bounds, scenePointerX: number, scenePointerY: number, zoom: Zoom, pointerType: PointerType, device: Device) => MaybeTransformHandleType;
export declare const getCursorForResizingElement: (resizingElement: {
    element?: ExcalidrawElement;
    transformHandleType: MaybeTransformHandleType;
}) => string;
