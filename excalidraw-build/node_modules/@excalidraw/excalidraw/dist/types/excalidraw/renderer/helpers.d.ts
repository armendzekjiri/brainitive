import type { StaticCanvasAppState, AppState } from "../types";
import type { StaticCanvasRenderConfig } from "../scene/types";
export declare const fillCircle: (context: CanvasRenderingContext2D, cx: number, cy: number, radius: number, stroke?: boolean) => void;
export declare const getNormalizedCanvasDimensions: (canvas: HTMLCanvasElement, scale: number) => [number, number];
export declare const bootstrapCanvas: ({ canvas, scale, normalizedWidth, normalizedHeight, theme, isExporting, viewBackgroundColor, }: {
    canvas: HTMLCanvasElement;
    scale: number;
    normalizedWidth: number;
    normalizedHeight: number;
    theme?: import("../element/types").Theme | undefined;
    isExporting?: boolean | undefined;
    viewBackgroundColor?: string | null | undefined;
}) => CanvasRenderingContext2D;
