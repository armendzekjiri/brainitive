import type { LaserPointerOptions } from "@excalidraw/laser-pointer";
import type { AnimationFrameHandler } from "./animation-frame-handler";
import type App from "./components/App";
export interface Trail {
    start(container: SVGSVGElement): void;
    stop(): void;
    startPath(x: number, y: number): void;
    addPointToPath(x: number, y: number): void;
    endPath(): void;
}
export interface AnimatedTrailOptions {
    fill: (trail: AnimatedTrail) => string;
}
export declare class AnimatedTrail implements Trail {
    private animationFrameHandler;
    private app;
    private options;
    private currentTrail?;
    private pastTrails;
    private container?;
    private trailElement;
    constructor(animationFrameHandler: AnimationFrameHandler, app: App, options: Partial<LaserPointerOptions> & Partial<AnimatedTrailOptions>);
    get hasCurrentTrail(): boolean;
    hasLastPoint(x: number, y: number): boolean;
    start(container?: SVGSVGElement): void;
    stop(): void;
    startPath(x: number, y: number): void;
    addPointToPath(x: number, y: number): void;
    endPath(): void;
    private update;
    private onFrame;
    private drawTrail;
}
