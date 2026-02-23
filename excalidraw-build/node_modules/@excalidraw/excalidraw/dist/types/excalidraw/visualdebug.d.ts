import type { Curve } from "@excalidraw/math";
import { type GlobalPoint, type LocalPoint } from "@excalidraw/math";
import type { LineSegment } from "@excalidraw/utils";
import type { Bounds } from "./element/bounds";
declare global {
    interface Window {
        visualDebug?: {
            data: DebugElement[][];
            currentFrame?: number;
        };
    }
}
export type DebugElement = {
    color: string;
    data: LineSegment<GlobalPoint> | Curve<GlobalPoint>;
    permanent: boolean;
};
export declare const debugDrawCubicBezier: (c: Curve<GlobalPoint>, opts?: {
    color?: string;
    permanent?: boolean;
}) => void;
export declare const debugDrawLine: (segment: LineSegment<GlobalPoint> | LineSegment<GlobalPoint>[], opts?: {
    color?: string;
    permanent?: boolean;
}) => void;
export declare const debugDrawPoint: (p: GlobalPoint, opts?: {
    color?: string;
    permanent?: boolean;
    fuzzy?: boolean;
}) => void;
export declare const debugDrawBounds: (box: Bounds | Bounds[], opts?: {
    color?: string;
    permanent?: boolean;
}) => void;
export declare const debugDrawPoints: ({ x, y, points, }: {
    x: number;
    y: number;
    points: LocalPoint[];
}, options?: any) => void;
export declare const debugCloseFrame: () => void;
export declare const debugClear: () => void;
