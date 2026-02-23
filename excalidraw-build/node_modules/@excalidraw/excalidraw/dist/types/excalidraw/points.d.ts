import { type GlobalPoint, type LocalPoint } from "@excalidraw/math";
export declare const getSizeFromPoints: (points: readonly (GlobalPoint | LocalPoint)[]) => {
    width: number;
    height: number;
};
/** @arg dimension, 0 for rescaling only x, 1 for y */
export declare const rescalePoints: <Point extends GlobalPoint | LocalPoint>(dimension: 0 | 1, newSize: number, points: readonly Point[], normalize: boolean) => Point[];
