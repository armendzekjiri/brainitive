import type { Curve, GlobalPoint, LineSegment, LocalPoint } from "./types";
/**
 *
 * @param a
 * @param b
 * @param c
 * @param d
 * @returns
 */
export declare function curve<Point extends GlobalPoint | LocalPoint>(a: Point, b: Point, c: Point, d: Point): Curve<Point>;
/**
 * Computes the intersection between a cubic spline and a line segment.
 */
export declare function curveIntersectLineSegment<Point extends GlobalPoint | LocalPoint>(c: Curve<Point>, l: LineSegment<Point>): Point[];
/**
 * Finds the closest point on the Bezier curve from another point
 *
 * @param x
 * @param y
 * @param P0
 * @param P1
 * @param P2
 * @param P3
 * @param tolerance
 * @param maxLevel
 * @returns
 */
export declare function curveClosestPoint<Point extends GlobalPoint | LocalPoint>(c: Curve<Point>, p: Point, tolerance?: number): Point | null;
/**
 * Determines the distance between a point and the closest point on the
 * Bezier curve.
 *
 * @param c The curve to test
 * @param p The point to measure from
 */
export declare function curvePointDistance<Point extends GlobalPoint | LocalPoint>(c: Curve<Point>, p: Point): number;
/**
 * Determines if the parameter is a Curve
 */
export declare function isCurve<P extends GlobalPoint | LocalPoint>(v: unknown): v is Curve<P>;
