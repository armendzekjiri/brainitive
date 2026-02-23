import React from "react";
import type { RoughCanvas } from "roughjs/bin/canvas";
import type { StaticCanvasAppState } from "../../types";
import type { RenderableElementsMap, StaticCanvasRenderConfig } from "../../scene/types";
import type { NonDeletedExcalidrawElement, NonDeletedSceneElementsMap } from "../../element/types";
type StaticCanvasProps = {
    canvas: HTMLCanvasElement;
    rc: RoughCanvas;
    elementsMap: RenderableElementsMap;
    allElementsMap: NonDeletedSceneElementsMap;
    visibleElements: readonly NonDeletedExcalidrawElement[];
    sceneNonce: number | undefined;
    selectionNonce: number | undefined;
    scale: number;
    appState: StaticCanvasAppState;
    renderConfig: StaticCanvasRenderConfig;
};
declare const _default: React.MemoExoticComponent<(props: StaticCanvasProps) => import("react/jsx-runtime").JSX.Element>;
export default _default;
