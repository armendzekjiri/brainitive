import type { GlobalPoint, Radians } from "@excalidraw/math";
import type { Bounds } from "../../element/bounds";
import type { ElementsMap, NonDeletedExcalidrawElement } from "../../element/types";
import type { AppState, UIAppState } from "../../types";
export declare const EXTERNAL_LINK_IMG: HTMLImageElement;
export declare const ELEMENT_LINK_IMG: HTMLImageElement;
export declare const getLinkHandleFromCoords: ([x1, y1, x2, y2]: Bounds, angle: Radians, appState: Pick<UIAppState, "zoom">) => Bounds;
export declare const isPointHittingLinkIcon: (element: NonDeletedExcalidrawElement, elementsMap: ElementsMap, appState: AppState, [x, y]: GlobalPoint) => boolean;
export declare const isPointHittingLink: (element: NonDeletedExcalidrawElement, elementsMap: ElementsMap, appState: AppState, [x, y]: GlobalPoint, isMobile: boolean) => boolean;
