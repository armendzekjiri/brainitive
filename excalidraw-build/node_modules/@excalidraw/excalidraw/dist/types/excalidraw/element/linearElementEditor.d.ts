/// <reference types="react" />
import type { NonDeleted, ExcalidrawLinearElement, ExcalidrawElement, PointBinding, ExcalidrawBindableElement, ExcalidrawTextElementWithContainer, ElementsMap, NonDeletedSceneElementsMap, SceneElementsMap, ExcalidrawElbowArrowElement } from "./types";
import type { Bounds } from "./bounds";
import type { AppState, PointerCoords, InteractiveCanvasAppState, AppClassProperties, NullableGridSize, Zoom } from "../types";
import type { Store } from "../store";
import type Scene from "../scene/Scene";
import { type GlobalPoint, type LocalPoint } from "@excalidraw/math";
declare const editorMidPointsCache: {
    version: number | null;
    points: (GlobalPoint | null)[];
    zoom: number | null;
};
export declare class LinearElementEditor {
    readonly elementId: ExcalidrawElement["id"] & {
        _brand: "excalidrawLinearElementId";
    };
    /** indices */
    readonly selectedPointsIndices: readonly number[] | null;
    readonly pointerDownState: Readonly<{
        prevSelectedPointsIndices: readonly number[] | null;
        /** index */
        lastClickedPoint: number;
        lastClickedIsEndPoint: boolean;
        origin: Readonly<{
            x: number;
            y: number;
        }> | null;
        segmentMidpoint: {
            value: GlobalPoint | null;
            index: number | null;
            added: boolean;
        };
    }>;
    /** whether you're dragging a point */
    readonly isDragging: boolean;
    readonly lastUncommittedPoint: LocalPoint | null;
    readonly pointerOffset: Readonly<{
        x: number;
        y: number;
    }>;
    readonly startBindingElement: ExcalidrawBindableElement | null | "keep";
    readonly endBindingElement: ExcalidrawBindableElement | null | "keep";
    readonly hoverPointIndex: number;
    readonly segmentMidPointHoveredCoords: GlobalPoint | null;
    readonly elbowed: boolean;
    constructor(element: NonDeleted<ExcalidrawLinearElement>);
    static POINT_HANDLE_SIZE: number;
    /**
     * @param id the `elementId` from the instance of this class (so that we can
     *  statically guarantee this method returns an ExcalidrawLinearElement)
     */
    static getElement<T extends ExcalidrawLinearElement>(id: InstanceType<typeof LinearElementEditor>["elementId"], elementsMap: ElementsMap): T | null;
    static handleBoxSelection(event: PointerEvent, appState: AppState, setState: React.Component<any, AppState>["setState"], elementsMap: NonDeletedSceneElementsMap): false | undefined;
    /**
     * @returns whether point was dragged
     */
    static handlePointDragging(event: PointerEvent, app: AppClassProperties, scenePointerX: number, scenePointerY: number, maybeSuggestBinding: (element: NonDeleted<ExcalidrawLinearElement>, pointSceneCoords: {
        x: number;
        y: number;
    }[]) => void, linearElementEditor: LinearElementEditor, scene: Scene): boolean;
    static handlePointerUp(event: PointerEvent, editingLinearElement: LinearElementEditor, appState: AppState, scene: Scene): LinearElementEditor;
    static getEditorMidPoints: (element: NonDeleted<ExcalidrawLinearElement>, elementsMap: ElementsMap, appState: InteractiveCanvasAppState) => (typeof editorMidPointsCache)["points"];
    static updateEditorMidPointsCache: (element: NonDeleted<ExcalidrawLinearElement>, elementsMap: ElementsMap, appState: InteractiveCanvasAppState) => void;
    static getSegmentMidpointHitCoords: (linearElementEditor: LinearElementEditor, scenePointer: {
        x: number;
        y: number;
    }, appState: AppState, elementsMap: ElementsMap) => GlobalPoint | null;
    static isSegmentTooShort<P extends GlobalPoint | LocalPoint>(element: NonDeleted<ExcalidrawLinearElement>, startPoint: P, endPoint: P, index: number, zoom: Zoom): boolean;
    static getSegmentMidPoint(element: NonDeleted<ExcalidrawLinearElement>, startPoint: GlobalPoint, endPoint: GlobalPoint, endPointIndex: number, elementsMap: ElementsMap): GlobalPoint;
    static getSegmentMidPointIndex(linearElementEditor: LinearElementEditor, appState: AppState, midPoint: GlobalPoint, elementsMap: ElementsMap): number;
    static handlePointerDown(event: React.PointerEvent<HTMLElement>, app: AppClassProperties, store: Store, scenePointer: {
        x: number;
        y: number;
    }, linearElementEditor: LinearElementEditor, scene: Scene): {
        didAddPoint: boolean;
        hitElement: NonDeleted<ExcalidrawElement> | null;
        linearElementEditor: LinearElementEditor | null;
    };
    static arePointsEqual<Point extends LocalPoint | GlobalPoint>(point1: Point | null, point2: Point | null): boolean;
    static handlePointerMove(event: React.PointerEvent<HTMLCanvasElement>, scenePointerX: number, scenePointerY: number, app: AppClassProperties, elementsMap: NonDeletedSceneElementsMap | SceneElementsMap): LinearElementEditor | null;
    /** scene coords */
    static getPointGlobalCoordinates(element: NonDeleted<ExcalidrawLinearElement>, p: LocalPoint, elementsMap: ElementsMap): GlobalPoint;
    /** scene coords */
    static getPointsGlobalCoordinates(element: NonDeleted<ExcalidrawLinearElement>, elementsMap: ElementsMap): GlobalPoint[];
    static getPointAtIndexGlobalCoordinates(element: NonDeleted<ExcalidrawLinearElement>, indexMaybeFromEnd: number, // -1 for last element
    elementsMap: ElementsMap): GlobalPoint;
    static pointFromAbsoluteCoords(element: NonDeleted<ExcalidrawLinearElement>, absoluteCoords: GlobalPoint, elementsMap: ElementsMap): LocalPoint;
    static getPointIndexUnderCursor(element: NonDeleted<ExcalidrawLinearElement>, elementsMap: ElementsMap, zoom: AppState["zoom"], x: number, y: number): number;
    static createPointAt(element: NonDeleted<ExcalidrawLinearElement>, elementsMap: ElementsMap, scenePointerX: number, scenePointerY: number, gridSize: NullableGridSize): LocalPoint;
    /**
     * Normalizes line points so that the start point is at [0,0]. This is
     * expected in various parts of the codebase. Also returns new x/y to account
     * for the potential normalization.
     */
    static getNormalizedPoints(element: ExcalidrawLinearElement): {
        points: LocalPoint[];
        x: number;
        y: number;
    };
    static normalizePoints(element: NonDeleted<ExcalidrawLinearElement>): void;
    static duplicateSelectedPoints(appState: AppState, elementsMap: NonDeletedSceneElementsMap | SceneElementsMap): AppState;
    static deletePoints(element: NonDeleted<ExcalidrawLinearElement>, pointIndices: readonly number[]): void;
    static addPoints(element: NonDeleted<ExcalidrawLinearElement>, targetPoints: {
        point: LocalPoint;
    }[]): void;
    static movePoints(element: NonDeleted<ExcalidrawLinearElement>, targetPoints: {
        index: number;
        point: LocalPoint;
        isDragging?: boolean;
    }[], otherUpdates?: {
        startBinding?: PointBinding | null;
        endBinding?: PointBinding | null;
    }): void;
    static shouldAddMidpoint(linearElementEditor: LinearElementEditor, pointerCoords: PointerCoords, appState: AppState, elementsMap: ElementsMap): boolean;
    static addMidpoint(linearElementEditor: LinearElementEditor, pointerCoords: PointerCoords, app: AppClassProperties, snapToGrid: boolean, elementsMap: ElementsMap): {
        pointerDownState: LinearElementEditor["pointerDownState"];
        selectedPointsIndices: LinearElementEditor["selectedPointsIndices"];
    } | undefined;
    private static _updatePoints;
    private static _getShiftLockedDelta;
    static getBoundTextElementPosition: (element: ExcalidrawLinearElement, boundTextElement: ExcalidrawTextElementWithContainer, elementsMap: ElementsMap) => {
        x: number;
        y: number;
    };
    static getMinMaxXYWithBoundText: (element: ExcalidrawLinearElement, elementsMap: ElementsMap, elementBounds: Bounds, boundTextElement: ExcalidrawTextElementWithContainer) => [number, number, number, number, number, number];
    static getElementAbsoluteCoords: (element: ExcalidrawLinearElement, elementsMap: ElementsMap, includeBoundText?: boolean) => [number, number, number, number, number, number];
    static moveFixedSegment(linearElement: LinearElementEditor, index: number, x: number, y: number, elementsMap: ElementsMap): LinearElementEditor;
    static deleteFixedSegment(element: ExcalidrawElbowArrowElement, index: number): void;
}
export {};
