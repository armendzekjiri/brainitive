import type { ElementsMap, ExcalidrawElement } from "./element/types";
import type Scene from "./scene/Scene";
export interface Alignment {
    position: "start" | "center" | "end";
    axis: "x" | "y";
}
export declare const alignElements: (selectedElements: ExcalidrawElement[], elementsMap: ElementsMap, alignment: Alignment, scene: Scene) => ExcalidrawElement[];
