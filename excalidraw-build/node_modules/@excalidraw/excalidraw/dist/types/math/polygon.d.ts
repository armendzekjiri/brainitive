import type { GlobalPoint, LocalPoint, Polygon } from "./types";
export declare function polygon<Point extends GlobalPoint | LocalPoint>(...points: Point[]): Polygon<Point>;
export declare function polygonFromPoints<Point extends GlobalPoint | LocalPoint>(points: Point[]): Polygon<Point>;
export declare const polygonIncludesPoint: <Point extends GlobalPoint | LocalPoint>(point: Point, polygon: Polygon<Point>) => boolean;
export declare const pointOnPolygon: <Point extends GlobalPoint | LocalPoint>(p: Point, poly: Polygon<Point>, threshold?: number) => boolean;
