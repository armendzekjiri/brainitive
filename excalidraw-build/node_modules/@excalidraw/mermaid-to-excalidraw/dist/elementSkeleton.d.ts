import { ExcalidrawTextElement } from "@excalidraw/excalidraw/types/element/types.js";
import { ValidLinearElement } from "@excalidraw/excalidraw/types/data/transform.js";
export type Arrow = Omit<Line, "type" | "strokeStyle"> & {
    type: "arrow";
    label?: {
        text: string | null;
        fontSize?: number;
    };
    strokeStyle?: ValidLinearElement["strokeStyle"] | null;
    strokeWidth?: ValidLinearElement["strokeWidth"];
    points?: number[][];
    sequenceNumber?: Container;
    startArrowhead?: ValidLinearElement["startArrowhead"];
    endArrowhead?: ValidLinearElement["endArrowhead"];
    start?: ValidLinearElement["start"];
    end?: ValidLinearElement["end"];
};
export type Line = {
    type: "line";
    startX: number;
    startY: number;
    endX: number;
    endY: number;
    id?: string;
    strokeColor?: string | null;
    strokeWidth?: number | null;
    strokeStyle?: ValidLinearElement["strokeStyle"] | null;
    groupId?: string;
    metadata?: {
        [key: string]: any;
    };
};
export type Text = {
    type: "text";
    text: string;
    x: number;
    y: number;
    id?: string;
    width?: number;
    height?: number;
    fontSize: number;
    groupId?: string;
    metadata?: {
        [key: string]: any;
    };
};
export type Container = {
    type: "rectangle" | "ellipse";
    x: number;
    y: number;
    id?: string;
    label?: {
        text: string | null;
        fontSize: number;
        color?: string;
        verticalAlign?: ExcalidrawTextElement["verticalAlign"];
    };
    width?: number;
    height?: number;
    strokeStyle?: "dashed" | "solid";
    strokeWidth?: number;
    strokeColor?: string;
    bgColor?: string;
    subtype?: "actor" | "activation" | "highlight" | "note" | "sequence";
    groupId?: string;
    metadata?: {
        [key: string]: any;
    };
};
export type Node = Container | Line | Arrow | Text;
export declare const createArrowSkeletonFromSVG: (arrowNode: SVGLineElement | SVGPathElement, opts?: {
    label?: string;
    strokeStyle?: ValidLinearElement["strokeStyle"];
    startArrowhead?: ValidLinearElement["startArrowhead"];
    endArrowhead?: ValidLinearElement["endArrowhead"];
}) => Arrow;
export declare const createArrowSkeletion: (startX: number, startY: number, endX: number, endY: number, opts?: {
    id?: string;
    label?: Arrow["label"];
    strokeColor?: Arrow["strokeColor"];
    strokeStyle?: Arrow["strokeStyle"];
    startArrowhead?: Arrow["startArrowhead"];
    endArrowhead?: Arrow["endArrowhead"];
    start?: Arrow["start"];
    end?: Arrow["end"];
    points?: Arrow["points"];
}) => Arrow;
export declare const createTextSkeleton: (x: number, y: number, text: string, opts?: {
    id?: string | undefined;
    width?: number | undefined;
    height?: number | undefined;
    fontSize?: number | undefined;
    groupId?: string | undefined;
    metadata?: {
        [key: string]: any;
    } | undefined;
} | undefined) => Text;
export declare const createTextSkeletonFromSVG: (textNode: SVGTextElement, text: string, opts?: {
    groupId?: string;
    id?: string;
}) => Text;
export declare const createContainerSkeletonFromSVG: (node: SVGSVGElement | SVGRectElement, type: Container["type"], opts?: {
    id?: string;
    label?: {
        text: string;
        verticalAlign?: ExcalidrawTextElement["verticalAlign"];
    };
    subtype?: Container["subtype"];
    groupId?: string;
}) => Container;
export declare const createLineSkeletonFromSVG: (lineNode: SVGLineElement, startX: number, startY: number, endX: number, endY: number, opts?: {
    groupId?: string;
    id?: string;
}) => Line;
