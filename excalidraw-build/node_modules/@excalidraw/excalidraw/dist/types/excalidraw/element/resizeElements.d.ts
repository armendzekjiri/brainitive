import type { ExcalidrawLinearElement, ExcalidrawTextElement, NonDeletedExcalidrawElement, NonDeleted, ExcalidrawElement, ElementsMap, SceneElementsMap } from "./types";
import type { BoundingBox } from "./bounds";
import type { MaybeTransformHandleType, TransformHandleDirection } from "./transformHandles";
import type { PointerDownState } from "../types";
import type Scene from "../scene/Scene";
import { type LocalPoint } from "@excalidraw/math";
export declare const transformElements: (originalElements: PointerDownState["originalElements"], transformHandleType: MaybeTransformHandleType, selectedElements: readonly NonDeletedExcalidrawElement[], elementsMap: SceneElementsMap, scene: Scene, shouldRotateWithDiscreteAngle: boolean, shouldResizeFromCenter: boolean, shouldMaintainAspectRatio: boolean, pointerX: number, pointerY: number, centerX: number, centerY: number) => boolean;
export declare const rescalePointsInElement: (element: NonDeletedExcalidrawElement, width: number, height: number, normalizePoints: boolean) => {
    points: LocalPoint[];
} | {
    points?: undefined;
};
export declare const measureFontSizeFromWidth: (element: NonDeleted<ExcalidrawTextElement>, elementsMap: ElementsMap, nextWidth: number) => {
    size: number;
} | null;
export declare const getResizeOffsetXY: (transformHandleType: MaybeTransformHandleType, selectedElements: NonDeletedExcalidrawElement[], elementsMap: ElementsMap, x: number, y: number) => [number, number];
export declare const getResizeArrowDirection: (transformHandleType: MaybeTransformHandleType, element: NonDeleted<ExcalidrawLinearElement>) => "origin" | "end";
export declare const resizeSingleElement: (nextWidth: number, nextHeight: number, latestElement: ExcalidrawElement, origElement: ExcalidrawElement, elementsMap: ElementsMap, originalElementsMap: ElementsMap, handleDirection: TransformHandleDirection, { shouldInformMutation, shouldMaintainAspectRatio, shouldResizeFromCenter, }?: {
    shouldMaintainAspectRatio?: boolean | undefined;
    shouldResizeFromCenter?: boolean | undefined;
    shouldInformMutation?: boolean | undefined;
}) => void;
export declare const resizeMultipleElements: (selectedElements: readonly NonDeletedExcalidrawElement[], elementsMap: ElementsMap, handleDirection: TransformHandleDirection, scene: Scene, originalElementsMap: ElementsMap, { shouldMaintainAspectRatio, shouldResizeFromCenter, flipByX, flipByY, nextHeight, nextWidth, originalBoundingBox, }?: {
    nextWidth?: number | undefined;
    nextHeight?: number | undefined;
    shouldMaintainAspectRatio?: boolean | undefined;
    shouldResizeFromCenter?: boolean | undefined;
    flipByX?: boolean | undefined;
    flipByY?: boolean | undefined;
    originalBoundingBox?: BoundingBox | undefined;
}) => void;
