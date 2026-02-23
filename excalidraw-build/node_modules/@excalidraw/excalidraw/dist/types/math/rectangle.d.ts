import type { GlobalPoint, LineSegment, LocalPoint, Rectangle } from "./types";
export declare function rectangle<P extends GlobalPoint | LocalPoint>(topLeft: P, bottomRight: P): Rectangle<P>;
export declare function rectangleIntersectLineSegment<Point extends LocalPoint | GlobalPoint>(r: Rectangle<Point>, l: LineSegment<Point>): Point[];
