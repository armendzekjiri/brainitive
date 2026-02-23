import { Arrow, Line, Node, Text } from "../elementSkeleton.js";
export declare const normalizeText: (text: string) => string;
export declare const transformToExcalidrawLineSkeleton: (line: Line) => ({
    type: "line";
    x: number;
    y: number;
} & Partial<import("@excalidraw/excalidraw/types/element/types.js").ExcalidrawLinearElement>) | import("@excalidraw/excalidraw/types/data/transform.js").ValidLinearElement;
export declare const transformToExcalidrawTextSkeleton: (element: Text) => {
    type: "text";
    text: string;
    x: number;
    y: number;
    id?: string | undefined;
} & Partial<import("@excalidraw/excalidraw/types/element/types.js").ExcalidrawTextElement>;
export declare const transformToExcalidrawContainerSkeleton: (element: Exclude<Node, Line | Arrow | Text>) => import("@excalidraw/excalidraw/types/data/transform.js").ValidContainer;
export declare const transformToExcalidrawArrowSkeleton: (arrow: Arrow) => import("@excalidraw/excalidraw/types/data/transform.js").ValidLinearElement;
