import { type GlobalPoint, type LocalPoint } from "@excalidraw/math";
import type { Bounds } from "@excalidraw/excalidraw/element/bounds";
export type LineSegment<P extends LocalPoint | GlobalPoint> = [P, P];
export declare function getBBox<P extends LocalPoint | GlobalPoint>(line: LineSegment<P>): Bounds;
export declare function doBBoxesIntersect(a: Bounds, b: Bounds): boolean;
export declare function isPointOnLine<P extends GlobalPoint | LocalPoint>(l: LineSegment<P>, p: P): boolean;
export declare function isPointRightOfLine<P extends GlobalPoint | LocalPoint>(l: LineSegment<P>, p: P): boolean;
export declare function isLineSegmentTouchingOrCrossingLine<P extends GlobalPoint | LocalPoint>(a: LineSegment<P>, b: LineSegment<P>): boolean;
export declare function doLineSegmentsIntersect<P extends GlobalPoint | LocalPoint>(a: LineSegment<P>, b: LineSegment<P>): boolean;
