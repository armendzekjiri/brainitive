import type { ExcalidrawBindableElement, ExcalidrawElement, NonDeleted, ExcalidrawLinearElement, NonDeletedExcalidrawElement, ElementsMap, NonDeletedSceneElementsMap, OrderedExcalidrawElement, ExcalidrawElbowArrowElement, FixedPoint, SceneElementsMap } from "./types";
import type { Bounds } from "./bounds";
import type { AppState } from "../types";
import type { ElementUpdate } from "./mutateElement";
import type Scene from "../scene/Scene";
import { type Heading } from "./heading";
import type { LocalPoint } from "@excalidraw/math";
import { type GlobalPoint } from "@excalidraw/math";
export type SuggestedBinding = NonDeleted<ExcalidrawBindableElement> | SuggestedPointBinding;
export type SuggestedPointBinding = [
    NonDeleted<ExcalidrawLinearElement>,
    "start" | "end" | "both",
    NonDeleted<ExcalidrawBindableElement>
];
export declare const shouldEnableBindingForPointerEvent: (event: React.PointerEvent<HTMLElement>) => boolean;
export declare const isBindingEnabled: (appState: AppState) => boolean;
export declare const FIXED_BINDING_DISTANCE = 5;
export declare const BINDING_HIGHLIGHT_THICKNESS = 10;
export declare const BINDING_HIGHLIGHT_OFFSET = 4;
export declare const bindOrUnbindLinearElement: (linearElement: NonDeleted<ExcalidrawLinearElement>, startBindingElement: ExcalidrawBindableElement | null | "keep", endBindingElement: ExcalidrawBindableElement | null | "keep", elementsMap: NonDeletedSceneElementsMap, scene: Scene) => void;
export declare const bindOrUnbindLinearElements: (selectedElements: NonDeleted<ExcalidrawLinearElement>[], elementsMap: NonDeletedSceneElementsMap, elements: readonly NonDeletedExcalidrawElement[], scene: Scene, isBindingEnabled: boolean, draggingPoints: readonly number[] | null, zoom?: AppState["zoom"]) => void;
export declare const getSuggestedBindingsForArrows: (selectedElements: NonDeleted<ExcalidrawElement>[], elementsMap: NonDeletedSceneElementsMap, zoom: AppState["zoom"]) => SuggestedBinding[];
export declare const maybeBindLinearElement: (linearElement: NonDeleted<ExcalidrawLinearElement>, appState: AppState, pointerCoords: {
    x: number;
    y: number;
}, elementsMap: NonDeletedSceneElementsMap, elements: readonly NonDeletedExcalidrawElement[]) => void;
export declare const bindLinearElement: (linearElement: NonDeleted<ExcalidrawLinearElement>, hoveredElement: ExcalidrawBindableElement, startOrEnd: "start" | "end", elementsMap: NonDeletedSceneElementsMap) => void;
export declare const isLinearElementSimpleAndAlreadyBound: (linearElement: NonDeleted<ExcalidrawLinearElement>, alreadyBoundToId: ExcalidrawBindableElement["id"] | undefined, bindableElement: ExcalidrawBindableElement) => boolean;
export declare const getHoveredElementForBinding: (pointerCoords: {
    x: number;
    y: number;
}, elements: readonly NonDeletedExcalidrawElement[], elementsMap: NonDeletedSceneElementsMap, zoom?: AppState["zoom"], fullShape?: boolean, considerAllElements?: boolean) => NonDeleted<ExcalidrawBindableElement> | null;
export declare const updateBoundElements: (changedElement: NonDeletedExcalidrawElement, elementsMap: NonDeletedSceneElementsMap | SceneElementsMap, options?: {
    simultaneouslyUpdated?: readonly ExcalidrawElement[];
    newSize?: {
        width: number;
        height: number;
    };
    changedElements?: Map<string, OrderedExcalidrawElement>;
}) => void;
export declare const getHeadingForElbowArrowSnap: (p: Readonly<GlobalPoint>, otherPoint: Readonly<GlobalPoint>, bindableElement: ExcalidrawBindableElement | undefined | null, aabb: Bounds | undefined | null, elementsMap: ElementsMap, origPoint: GlobalPoint, zoom?: AppState["zoom"]) => Heading;
export declare const bindPointToSnapToElementOutline: (arrow: ExcalidrawElbowArrowElement, bindableElement: ExcalidrawBindableElement | undefined, startOrEnd: "start" | "end") => GlobalPoint;
export declare const avoidRectangularCorner: (element: ExcalidrawBindableElement, p: GlobalPoint) => GlobalPoint;
export declare const snapToMid: (element: ExcalidrawBindableElement, p: GlobalPoint, tolerance?: number) => GlobalPoint;
export declare const calculateFixedPointForElbowArrowBinding: (linearElement: NonDeleted<ExcalidrawElbowArrowElement>, hoveredElement: ExcalidrawBindableElement, startOrEnd: "start" | "end", elementsMap: ElementsMap) => {
    fixedPoint: FixedPoint;
};
export declare const fixBindingsAfterDuplication: (sceneElements: readonly ExcalidrawElement[], oldElements: readonly ExcalidrawElement[], oldIdToDuplicatedId: Map<ExcalidrawElement["id"], ExcalidrawElement["id"]>, duplicatesServeAsOld?: "duplicatesServeAsOld" | undefined) => void;
export declare const fixBindingsAfterDeletion: (sceneElements: readonly ExcalidrawElement[], deletedElements: readonly ExcalidrawElement[]) => void;
export declare const bindingBorderTest: (element: NonDeleted<ExcalidrawBindableElement>, { x, y }: {
    x: number;
    y: number;
}, elementsMap: NonDeletedSceneElementsMap, zoom?: AppState["zoom"], fullShape?: boolean) => boolean;
export declare const maxBindingGap: (element: ExcalidrawElement, elementWidth: number, elementHeight: number, zoom?: AppState["zoom"]) => number;
export declare const bindingProperties: Set<BindableProp | BindingProp>;
export type BindableProp = "boundElements";
export type BindingProp = "frameId" | "containerId" | "startBinding" | "endBinding";
/**
 * Bound element containing bindings to `frameId`, `containerId`, `startBinding` or `endBinding`.
 */
export declare class BoundElement {
    /**
     * Unbind the affected non deleted bindable elements (removing element from `boundElements`).
     * - iterates non deleted bindable elements (`containerId` | `startBinding.elementId` | `endBinding.elementId`) of the current element
     * - prepares updates to unbind each bindable element's `boundElements` from the current element
     */
    static unbindAffected(elements: ElementsMap, boundElement: ExcalidrawElement | undefined, updateElementWith: (affected: ExcalidrawElement, updates: ElementUpdate<ExcalidrawElement>) => void): void;
    /**
     * Rebind the next affected non deleted bindable elements (adding element to `boundElements`).
     * - iterates non deleted bindable elements (`containerId` | `startBinding.elementId` | `endBinding.elementId`) of the current element
     * - prepares updates to rebind each bindable element's `boundElements` to the current element
     *
     * NOTE: rebind expects that affected elements were previously unbound with `BoundElement.unbindAffected`
     */
    static rebindAffected: (elements: ElementsMap, boundElement: ExcalidrawElement | undefined, updateElementWith: (affected: ExcalidrawElement, updates: ElementUpdate<ExcalidrawElement>) => void) => void;
}
/**
 * Bindable element containing bindings to `boundElements`.
 */
export declare class BindableElement {
    /**
     * Unbind the affected non deleted bound elements (resetting `containerId`, `startBinding`, `endBinding` to `null`).
     * - iterates through non deleted `boundElements` of the current element
     * - prepares updates to unbind each bound element from the current element
     */
    static unbindAffected(elements: ElementsMap, bindableElement: ExcalidrawElement | undefined, updateElementWith: (affected: ExcalidrawElement, updates: ElementUpdate<ExcalidrawElement>) => void): void;
    /**
     * Rebind the affected non deleted bound elements (for now setting only `containerId`, as we cannot rebind arrows atm).
     * - iterates through non deleted `boundElements` of the current element
     * - prepares updates to rebind each bound element to the current element or unbind it from `boundElements` in case of conflicts
     *
     * NOTE: rebind expects that affected elements were previously unbound with `BindaleElement.unbindAffected`
     */
    static rebindAffected: (elements: ElementsMap, bindableElement: ExcalidrawElement | undefined, updateElementWith: (affected: ExcalidrawElement, updates: ElementUpdate<ExcalidrawElement>) => void) => void;
}
export declare const getGlobalFixedPointForBindableElement: (fixedPointRatio: [number, number], element: ExcalidrawBindableElement) => GlobalPoint;
export declare const getGlobalFixedPoints: (arrow: ExcalidrawElbowArrowElement, elementsMap: ElementsMap) => [GlobalPoint, GlobalPoint];
export declare const getArrowLocalFixedPoints: (arrow: ExcalidrawElbowArrowElement, elementsMap: ElementsMap) => LocalPoint[];
export declare const normalizeFixedPoint: <T extends FixedPoint | null>(fixedPoint: T) => T extends null ? null : FixedPoint;
