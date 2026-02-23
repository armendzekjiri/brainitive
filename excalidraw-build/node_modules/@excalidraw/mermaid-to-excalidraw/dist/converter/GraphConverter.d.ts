import { ExcalidrawConfig } from "../index.js";
import { MermaidToExcalidrawResult } from "../interfaces.js";
import { Flowchart } from "../parser/flowchart.js";
import { Sequence } from "../parser/sequence.js";
export declare class GraphConverter<T = Flowchart | Sequence> {
    private converter;
    constructor({ converter, }: {
        converter: (graph: T, config: Required<ExcalidrawConfig>) => MermaidToExcalidrawResult;
    });
    convert: (graph: T, config: ExcalidrawConfig) => MermaidToExcalidrawResult;
}
