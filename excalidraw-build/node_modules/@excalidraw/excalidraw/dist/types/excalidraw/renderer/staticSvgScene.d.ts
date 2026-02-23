import type { RoughSVG } from "roughjs/bin/svg";
import type { NonDeletedExcalidrawElement } from "../element/types";
import type { RenderableElementsMap, SVGRenderConfig } from "../scene/types";
import type { BinaryFiles } from "../types";
export declare const renderSceneToSvg: (elements: readonly NonDeletedExcalidrawElement[], elementsMap: RenderableElementsMap, rsvg: RoughSVG, svgRoot: SVGElement, files: BinaryFiles, renderConfig: SVGRenderConfig) => void;
