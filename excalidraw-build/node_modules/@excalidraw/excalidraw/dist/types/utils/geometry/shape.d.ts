/**
 * this file defines pure geometric shapes
 *
 * for instance, a cubic bezier curve is specified by its four control points and
 * an ellipse is defined by its center, angle, semi major axis and semi minor axis
 * (but in semi-width and semi-height so it's more relevant to Excalidraw)
 *
 * the idea with pure shapes is so that we can provide collision and other geoemtric methods not depending on
 * the specifics of roughjs or elements in Excalidraw; instead, we can focus on the pure shapes themselves
 *
 * also included in this file are methods for converting an Excalidraw element or a Drawable from roughjs
 * to pure shapes
 */
import type { Curve, LineSegment, Polygon, Radians } from "@excalidraw/math";
import { type GlobalPoint, type LocalPoint } from "@excalidraw/math";
import type { ElementsMap, ExcalidrawBindableElement, ExcalidrawDiamondElement, ExcalidrawElement, ExcalidrawEllipseElement, ExcalidrawEmbeddableElement, ExcalidrawFrameLikeElement, ExcalidrawFreeDrawElement, ExcalidrawIframeElement, ExcalidrawImageElement, ExcalidrawLinearElement, ExcalidrawRectangleElement, ExcalidrawSelectionElement, ExcalidrawTextElement } from "@excalidraw/excalidraw/element/types";
import type { Drawable, Op } from "roughjs/bin/core";
export type Polyline<Point extends GlobalPoint | LocalPoint> = LineSegment<Point>[];
export type Polycurve<Point extends GlobalPoint | LocalPoint> = Curve<Point>[];
export type Ellipse<Point extends GlobalPoint | LocalPoint> = {
    center: Point;
    angle: Radians;
    halfWidth: number;
    halfHeight: number;
};
export type GeometricShape<Point extends GlobalPoint | LocalPoint> = {
    type: "line";
    data: LineSegment<Point>;
} | {
    type: "polygon";
    data: Polygon<Point>;
} | {
    type: "curve";
    data: Curve<Point>;
} | {
    type: "ellipse";
    data: Ellipse<Point>;
} | {
    type: "polyline";
    data: Polyline<Point>;
} | {
    type: "polycurve";
    data: Polycurve<Point>;
};
type RectangularElement = ExcalidrawRectangleElement | ExcalidrawDiamondElement | ExcalidrawFrameLikeElement | ExcalidrawEmbeddableElement | ExcalidrawImageElement | ExcalidrawIframeElement | ExcalidrawTextElement | ExcalidrawSelectionElement;
export declare const getPolygonShape: <Point extends GlobalPoint | LocalPoint>(element: RectangularElement) => GeometricShape<Point>;
export declare const getSelectionBoxShape: <Point extends GlobalPoint | LocalPoint>(element: ExcalidrawElement, elementsMap: ElementsMap, padding?: number) => GeometricShape<Point>;
export declare const getEllipseShape: <Point extends GlobalPoint | LocalPoint>(element: ExcalidrawEllipseElement) => GeometricShape<Point>;
export declare const getCurvePathOps: (shape: Drawable) => Op[];
export declare const getCurveShape: <Point extends GlobalPoint | LocalPoint>(roughShape: Drawable, startingPoint: Point | undefined, angleInRadian: Radians, center: Point) => GeometricShape<Point>;
export declare const getFreedrawShape: <Point extends GlobalPoint | LocalPoint>(element: ExcalidrawFreeDrawElement, center: Point, isClosed?: boolean) => GeometricShape<Point>;
export declare const getClosedCurveShape: <Point extends GlobalPoint | LocalPoint>(element: ExcalidrawLinearElement, roughShape: Drawable, startingPoint: Point | undefined, angleInRadian: Radians, center: Point) => GeometricShape<Point>;
/**
 * Determine intersection of a rectangular shaped element and a
 * line segment.
 *
 * @param element The rectangular element to test against
 * @param segment The segment intersecting the element
 * @param gap Optional value to inflate the shape before testing
 * @returns An array of intersections
 */
export declare const segmentIntersectRectangleElement: <Point extends GlobalPoint | LocalPoint>(element: ExcalidrawBindableElement, segment: LineSegment<Point>, gap?: number) => Point[];
export declare const pointOnEllipse: <Point extends GlobalPoint | LocalPoint>(point: Point, ellipse: Ellipse<Point>, threshold?: number) => boolean;
export declare const pointInEllipse: <Point extends GlobalPoint | LocalPoint>(p: Point, ellipse: Ellipse<Point>) => boolean;
export declare const ellipseAxes: <Point extends GlobalPoint | LocalPoint>(ellipse: Ellipse<Point>) => {
    majorAxis: number;
    minorAxis: number;
};
export declare const ellipseFocusToCenter: <Point extends GlobalPoint | LocalPoint>(ellipse: Ellipse<Point>) => number;
export declare const ellipseExtremes: <Point extends GlobalPoint | LocalPoint>(ellipse: Ellipse<Point>) => import("@excalidraw/math").Vector[];
export {};
