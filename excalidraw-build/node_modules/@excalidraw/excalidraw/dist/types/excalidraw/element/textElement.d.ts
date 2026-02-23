import type { ElementsMap, ExcalidrawElement, ExcalidrawElementType, ExcalidrawTextContainer, ExcalidrawTextElement, ExcalidrawTextElementWithContainer, NonDeletedExcalidrawElement } from "./types";
import type { MaybeTransformHandleType } from "./transformHandles";
import type { AppState } from "../types";
import type { ExtractSetType } from "../utility-types";
export declare const redrawTextBoundingBox: (textElement: ExcalidrawTextElement, container: ExcalidrawElement | null, elementsMap: ElementsMap, informMutation?: boolean) => void;
export declare const bindTextToShapeAfterDuplication: (newElements: ExcalidrawElement[], oldElements: ExcalidrawElement[], oldIdToDuplicatedId: Map<ExcalidrawElement["id"], ExcalidrawElement["id"]>) => void;
export declare const handleBindTextResize: (container: NonDeletedExcalidrawElement, elementsMap: ElementsMap, transformHandleType: MaybeTransformHandleType, shouldMaintainAspectRatio?: boolean) => void;
export declare const computeBoundTextPosition: (container: ExcalidrawElement, boundTextElement: ExcalidrawTextElementWithContainer, elementsMap: ElementsMap) => {
    x: number;
    y: number;
};
export declare const getBoundTextElementId: (container: ExcalidrawElement | null) => string | null;
export declare const getBoundTextElement: (element: ExcalidrawElement | null, elementsMap: ElementsMap) => ExcalidrawTextElementWithContainer | null;
export declare const getContainerElement: (element: ExcalidrawTextElement | null, elementsMap: ElementsMap) => ExcalidrawTextContainer | null;
export declare const getContainerCenter: (container: ExcalidrawElement, appState: AppState, elementsMap: ElementsMap) => {
    x: number;
    y: number;
};
export declare const getContainerCoords: (container: NonDeletedExcalidrawElement) => {
    x: number;
    y: number;
};
export declare const getTextElementAngle: (textElement: ExcalidrawTextElement, container: ExcalidrawTextContainer | null) => import("@excalidraw/math").Radians;
export declare const getBoundTextElementPosition: (container: ExcalidrawElement, boundTextElement: ExcalidrawTextElementWithContainer, elementsMap: ElementsMap) => {
    x: number;
    y: number;
} | undefined;
export declare const shouldAllowVerticalAlign: (selectedElements: NonDeletedExcalidrawElement[], elementsMap: ElementsMap) => boolean;
export declare const suppportsHorizontalAlign: (selectedElements: NonDeletedExcalidrawElement[], elementsMap: ElementsMap) => boolean;
declare const VALID_CONTAINER_TYPES: Set<string>;
export declare const isValidTextContainer: (element: {
    type: ExcalidrawElementType;
}) => boolean;
export declare const computeContainerDimensionForBoundText: (dimension: number, containerType: ExtractSetType<typeof VALID_CONTAINER_TYPES>) => number;
export declare const getBoundTextMaxWidth: (container: ExcalidrawElement, boundTextElement: ExcalidrawTextElement | null) => number;
export declare const getBoundTextMaxHeight: (container: ExcalidrawElement, boundTextElement: ExcalidrawTextElementWithContainer) => number;
/** retrieves text from text elements and concatenates to a single string */
export declare const getTextFromElements: (elements: readonly ExcalidrawElement[], separator?: string) => string;
export {};
