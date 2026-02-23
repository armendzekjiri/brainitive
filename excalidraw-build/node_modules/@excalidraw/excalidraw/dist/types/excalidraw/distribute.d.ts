import type { ElementsMap, ExcalidrawElement } from "./element/types";
export interface Distribution {
    space: "between";
    axis: "x" | "y";
}
export declare const distributeElements: (selectedElements: ExcalidrawElement[], elementsMap: ElementsMap, distribution: Distribution) => ExcalidrawElement[];
