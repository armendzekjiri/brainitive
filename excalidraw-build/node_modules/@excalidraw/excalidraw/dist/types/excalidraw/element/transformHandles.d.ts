import type { ElementsMap, ExcalidrawElement, NonDeletedExcalidrawElement, PointerType } from "./types";
import type { Bounds } from "./bounds";
import type { Device, InteractiveCanvasAppState, Zoom } from "../types";
import type { Radians } from "@excalidraw/math";
export type TransformHandleDirection = "n" | "s" | "w" | "e" | "nw" | "ne" | "sw" | "se";
export type TransformHandleType = TransformHandleDirection | "rotation";
export type TransformHandle = Bounds;
export type TransformHandles = Partial<{
    [T in TransformHandleType]: TransformHandle;
}>;
export type MaybeTransformHandleType = TransformHandleType | false;
export declare const DEFAULT_OMIT_SIDES: {
    e: boolean;
    s: boolean;
    n: boolean;
    w: boolean;
};
export declare const OMIT_SIDES_FOR_MULTIPLE_ELEMENTS: {
    e: boolean;
    s: boolean;
    n: boolean;
    w: boolean;
};
export declare const OMIT_SIDES_FOR_FRAME: {
    e: boolean;
    s: boolean;
    n: boolean;
    w: boolean;
    rotation: boolean;
};
export declare const canResizeFromSides: (device: Device) => boolean;
export declare const getOmitSidesForDevice: (device: Device) => {};
export declare const getTransformHandlesFromCoords: ([x1, y1, x2, y2, cx, cy]: [number, number, number, number, number, number], angle: Radians, zoom: Zoom, pointerType: PointerType, omitSides?: {
    s?: boolean | undefined;
    e?: boolean | undefined;
    w?: boolean | undefined;
    n?: boolean | undefined;
    nw?: boolean | undefined;
    ne?: boolean | undefined;
    sw?: boolean | undefined;
    se?: boolean | undefined;
    rotation?: boolean | undefined;
}, margin?: number, spacing?: number) => TransformHandles;
export declare const getTransformHandles: (element: ExcalidrawElement, zoom: Zoom, elementsMap: ElementsMap, pointerType?: PointerType, omitSides?: {
    s?: boolean | undefined;
    e?: boolean | undefined;
    w?: boolean | undefined;
    n?: boolean | undefined;
    nw?: boolean | undefined;
    ne?: boolean | undefined;
    sw?: boolean | undefined;
    se?: boolean | undefined;
    rotation?: boolean | undefined;
}) => TransformHandles;
export declare const shouldShowBoundingBox: (elements: readonly NonDeletedExcalidrawElement[], appState: InteractiveCanvasAppState) => boolean;
