import type { InclusiveRange } from "@excalidraw/math";
import { type GlobalPoint } from "@excalidraw/math";
import type { Bounds } from "./element/bounds";
import type { MaybeTransformHandleType } from "./element/transformHandles";
import type { ElementsMap, ExcalidrawElement, NonDeletedExcalidrawElement } from "./element/types";
import type { AppClassProperties, AppState, KeyboardModifiersObject, NullableGridSize } from "./types";
export declare const getSnapDistance: (zoomValue: number) => number;
type Vector2D = {
    x: number;
    y: number;
};
type PointPair = [GlobalPoint, GlobalPoint];
export type PointSnap = {
    type: "point";
    points: PointPair;
    offset: number;
};
export type Gap = {
    startBounds: Bounds;
    endBounds: Bounds;
    startSide: [GlobalPoint, GlobalPoint];
    endSide: [GlobalPoint, GlobalPoint];
    overlap: InclusiveRange;
    length: number;
};
export type GapSnap = {
    type: "gap";
    direction: "center_horizontal" | "center_vertical" | "side_left" | "side_right" | "side_top" | "side_bottom";
    gap: Gap;
    offset: number;
};
export type GapSnaps = GapSnap[];
export type Snap = GapSnap | PointSnap;
export type Snaps = Snap[];
export type PointSnapLine = {
    type: "points";
    points: GlobalPoint[];
};
export type PointerSnapLine = {
    type: "pointer";
    points: PointPair;
    direction: "horizontal" | "vertical";
};
export type GapSnapLine = {
    type: "gap";
    direction: "horizontal" | "vertical";
    points: PointPair;
};
export type SnapLine = PointSnapLine | GapSnapLine | PointerSnapLine;
export declare class SnapCache {
    private static referenceSnapPoints;
    private static visibleGaps;
    static setReferenceSnapPoints: (snapPoints: GlobalPoint[] | null) => void;
    static getReferenceSnapPoints: () => GlobalPoint[] | null;
    static setVisibleGaps: (gaps: {
        verticalGaps: Gap[];
        horizontalGaps: Gap[];
    } | null) => void;
    static getVisibleGaps: () => {
        verticalGaps: Gap[];
        horizontalGaps: Gap[];
    } | null;
    static destroy: () => void;
}
export declare const isGridModeEnabled: (app: AppClassProperties) => boolean;
export declare const isSnappingEnabled: ({ event, app, selectedElements, }: {
    app: AppClassProperties;
    event: KeyboardModifiersObject;
    selectedElements: NonDeletedExcalidrawElement[];
}) => boolean;
export declare const areRoughlyEqual: (a: number, b: number, precision?: number) => boolean;
export declare const getElementsCorners: (elements: ExcalidrawElement[], elementsMap: ElementsMap, { omitCenter, boundingBoxCorners, dragOffset, }?: {
    omitCenter?: boolean | undefined;
    boundingBoxCorners?: boolean | undefined;
    dragOffset?: Vector2D | undefined;
}) => GlobalPoint[];
export declare const getVisibleGaps: (elements: readonly NonDeletedExcalidrawElement[], selectedElements: ExcalidrawElement[], appState: AppState, elementsMap: ElementsMap) => {
    horizontalGaps: Gap[];
    verticalGaps: Gap[];
};
export declare const getReferenceSnapPoints: (elements: readonly NonDeletedExcalidrawElement[], selectedElements: ExcalidrawElement[], appState: AppState, elementsMap: ElementsMap) => GlobalPoint[];
export declare const snapDraggedElements: (elements: ExcalidrawElement[], dragOffset: Vector2D, app: AppClassProperties, event: KeyboardModifiersObject, elementsMap: ElementsMap) => {
    snapOffset: {
        x: number;
        y: number;
    };
    snapLines: (PointSnapLine | GapSnapLine)[];
};
export declare const snapResizingElements: (selectedElements: ExcalidrawElement[], selectedOriginalElements: ExcalidrawElement[], app: AppClassProperties, event: KeyboardModifiersObject, dragOffset: Vector2D, transformHandle: MaybeTransformHandleType) => {
    snapOffset: {
        x: number;
        y: number;
    };
    snapLines: PointSnapLine[];
};
export declare const snapNewElement: (newElement: ExcalidrawElement, app: AppClassProperties, event: KeyboardModifiersObject, origin: Vector2D, dragOffset: Vector2D, elementsMap: ElementsMap) => {
    snapOffset: {
        x: number;
        y: number;
    };
    snapLines: PointSnapLine[];
};
export declare const getSnapLinesAtPointer: (elements: readonly ExcalidrawElement[], app: AppClassProperties, pointer: Vector2D, event: KeyboardModifiersObject, elementsMap: ElementsMap) => {
    originOffset: {
        x: number;
        y: number;
    };
    snapLines: PointerSnapLine[];
};
export declare const isActiveToolNonLinearSnappable: (activeToolType: AppState["activeTool"]["type"]) => boolean;
export declare const getGridPoint: (x: number, y: number, gridSize: NullableGridSize) => [number, number];
export {};
