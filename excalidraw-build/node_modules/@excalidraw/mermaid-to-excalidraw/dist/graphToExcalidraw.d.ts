import { ExcalidrawConfig } from "./index.js";
import { GraphImage, MermaidToExcalidrawResult } from "./interfaces.js";
import { Sequence } from "./parser/sequence.js";
import { Flowchart } from "./parser/flowchart.js";
import { Class } from "./parser/class.js";
export declare const graphToExcalidraw: (graph: Flowchart | GraphImage | Sequence | Class, options?: ExcalidrawConfig) => MermaidToExcalidrawResult;
