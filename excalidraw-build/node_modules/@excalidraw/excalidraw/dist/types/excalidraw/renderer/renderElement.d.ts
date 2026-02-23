import type { ExcalidrawElement, ExcalidrawTextElement, NonDeletedExcalidrawElement, ExcalidrawFreeDrawElement, ExcalidrawImageElement, ExcalidrawFrameLikeElement, NonDeletedSceneElementsMap } from "../element/types";
import type { RoughCanvas } from "roughjs/bin/canvas";
import type { StaticCanvasRenderConfig, RenderableElementsMap, InteractiveCanvasRenderConfig } from "../scene/types";
import type { AppState, StaticCanvasAppState, InteractiveCanvasAppState, ElementsPendingErasure, PendingExcalidrawElements } from "../types";
export declare const IMAGE_INVERT_FILTER = "invert(100%) hue-rotate(180deg) saturate(1.25)";
export declare const getRenderOpacity: (element: ExcalidrawElement, containingFrame: ExcalidrawFrameLikeElement | null, elementsPendingErasure: ElementsPendingErasure, pendingNodes: Readonly<PendingExcalidrawElements> | null, globalAlpha?: number) => number;
export interface ExcalidrawElementWithCanvas {
    element: ExcalidrawElement | ExcalidrawTextElement;
    canvas: HTMLCanvasElement;
    theme: AppState["theme"];
    scale: number;
    angle: number;
    zoomValue: AppState["zoom"]["value"];
    canvasOffsetX: number;
    canvasOffsetY: number;
    boundTextElementVersion: number | null;
    imageCrop: ExcalidrawImageElement["crop"] | null;
    containingFrameOpacity: number;
    boundTextCanvas: HTMLCanvasElement;
}
export declare const DEFAULT_LINK_SIZE = 14;
export declare const elementWithCanvasCache: WeakMap<ExcalidrawElement, ExcalidrawElementWithCanvas>;
export declare const renderSelectionElement: (element: NonDeletedExcalidrawElement, context: CanvasRenderingContext2D, appState: InteractiveCanvasAppState, selectionColor: InteractiveCanvasRenderConfig["selectionColor"]) => void;
export declare const renderElement: (element: NonDeletedExcalidrawElement, elementsMap: RenderableElementsMap, allElementsMap: NonDeletedSceneElementsMap, rc: RoughCanvas, context: CanvasRenderingContext2D, renderConfig: StaticCanvasRenderConfig, appState: StaticCanvasAppState) => void;
export declare const pathsCache: WeakMap<ExcalidrawFreeDrawElement, Path2D>;
export declare function generateFreeDrawShape(element: ExcalidrawFreeDrawElement): Path2D;
export declare function getFreeDrawPath2D(element: ExcalidrawFreeDrawElement): Path2D | undefined;
export declare function getFreeDrawSvgPath(element: ExcalidrawFreeDrawElement): string;
