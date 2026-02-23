import { Arrowhead, ExcalidrawTextElement } from "@excalidraw/excalidraw/types/element/types.js";
import { SubGraph, Vertex } from "../interfaces.js";
import { ExcalidrawVertexElement } from "../types.js";
import { Mutable } from "@excalidraw/excalidraw/types/utility-types.js";
import { Edge } from "../parser/flowchart.js";
/**
 * Compute groupIds for each element
 */
export interface ArrowType {
    startArrowhead?: Arrowhead | null;
    endArrowhead?: Arrowhead | null;
}
export declare const computeExcalidrawArrowType: (mermaidArrowType: string) => ArrowType;
export declare const getText: (element: Vertex | Edge | SubGraph) => string;
/**
 * Compute style for vertex
 */
export declare const computeExcalidrawVertexStyle: (style: Vertex["containerStyle"]) => Partial<Mutable<ExcalidrawVertexElement>>;
/**
 * Compute style for label
 */
export declare const computeExcalidrawVertexLabelStyle: (style: Vertex["labelStyle"]) => Partial<Mutable<ExcalidrawTextElement>>;
