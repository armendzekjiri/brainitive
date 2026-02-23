import { type GlobalPoint, type LocalPoint } from "@excalidraw/math";
import { type GeometricShape } from "@excalidraw/utils/geometry/shape";
import type { Bounds } from "./element/bounds";
import type { ElementsMap, ExcalidrawElement, ExcalidrawLinearElement, NonDeleted } from "./element/types";
import type { Zoom } from "./types";
export declare const SHAPES: readonly [{
    readonly icon: import("react/jsx-runtime").JSX.Element;
    readonly value: "selection";
    readonly key: "v";
    readonly numericKey: "1";
    readonly fillable: true;
}, {
    readonly icon: import("react/jsx-runtime").JSX.Element;
    readonly value: "rectangle";
    readonly key: "r";
    readonly numericKey: "2";
    readonly fillable: true;
}, {
    readonly icon: import("react/jsx-runtime").JSX.Element;
    readonly value: "diamond";
    readonly key: "d";
    readonly numericKey: "3";
    readonly fillable: true;
}, {
    readonly icon: import("react/jsx-runtime").JSX.Element;
    readonly value: "ellipse";
    readonly key: "o";
    readonly numericKey: "4";
    readonly fillable: true;
}, {
    readonly icon: import("react/jsx-runtime").JSX.Element;
    readonly value: "arrow";
    readonly key: "a";
    readonly numericKey: "5";
    readonly fillable: true;
}, {
    readonly icon: import("react/jsx-runtime").JSX.Element;
    readonly value: "line";
    readonly key: "l";
    readonly numericKey: "6";
    readonly fillable: true;
}, {
    readonly icon: import("react/jsx-runtime").JSX.Element;
    readonly value: "freedraw";
    readonly key: readonly ["p", "x"];
    readonly numericKey: "7";
    readonly fillable: false;
}, {
    readonly icon: import("react/jsx-runtime").JSX.Element;
    readonly value: "text";
    readonly key: "t";
    readonly numericKey: "8";
    readonly fillable: false;
}, {
    readonly icon: import("react/jsx-runtime").JSX.Element;
    readonly value: "image";
    readonly key: null;
    readonly numericKey: "9";
    readonly fillable: false;
}, {
    readonly icon: import("react/jsx-runtime").JSX.Element;
    readonly value: "eraser";
    readonly key: "e";
    readonly numericKey: "0";
    readonly fillable: false;
}];
export declare const findShapeByKey: (key: string) => "text" | "line" | "arrow" | "image" | "selection" | "rectangle" | "diamond" | "ellipse" | "freedraw" | "eraser" | null;
/**
 * get the pure geometric shape of an excalidraw element
 * which is then used for hit detection
 */
export declare const getElementShape: <Point extends GlobalPoint | LocalPoint>(element: ExcalidrawElement, elementsMap: ElementsMap) => GeometricShape<Point>;
export declare const getBoundTextShape: <Point extends GlobalPoint | LocalPoint>(element: ExcalidrawElement, elementsMap: ElementsMap) => GeometricShape<Point> | null;
export declare const getControlPointsForBezierCurve: <P extends GlobalPoint | LocalPoint>(element: NonDeleted<ExcalidrawLinearElement>, endPoint: P) => P[] | null;
export declare const getBezierXY: <P extends GlobalPoint | LocalPoint>(p0: P, p1: P, p2: P, p3: P, t: number) => P;
export declare const getBezierCurveLength: <P extends GlobalPoint | LocalPoint>(element: NonDeleted<ExcalidrawLinearElement>, endPoint: P) => number;
export declare const mapIntervalToBezierT: <P extends GlobalPoint | LocalPoint>(element: NonDeleted<ExcalidrawLinearElement>, endPoint: P, interval: number) => number;
/**
 * Get the axis-aligned bounding box for a given element
 */
export declare const aabbForElement: (element: Readonly<ExcalidrawElement>, offset?: [number, number, number, number]) => Bounds;
export declare const pointInsideBounds: <P extends GlobalPoint | LocalPoint>(p: P, bounds: Bounds) => boolean;
export declare const aabbsOverlapping: (a: Bounds, b: Bounds) => boolean;
export declare const getCornerRadius: (x: number, element: ExcalidrawElement) => number;
export declare const isPathALoop: (points: ExcalidrawLinearElement["points"], zoomValue?: Zoom["value"]) => boolean;
