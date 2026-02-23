import type { Trail } from "./animated-trail";
import { AnimatedTrail } from "./animated-trail";
import type { AnimationFrameHandler } from "./animation-frame-handler";
import type App from "./components/App";
export declare class LaserTrails implements Trail {
    private animationFrameHandler;
    private app;
    localTrail: AnimatedTrail;
    private collabTrails;
    private container?;
    constructor(animationFrameHandler: AnimationFrameHandler, app: App);
    private getTrailOptions;
    startPath(x: number, y: number): void;
    addPointToPath(x: number, y: number): void;
    endPath(): void;
    start(container: SVGSVGElement): void;
    stop(): void;
    onFrame(): void;
    private updateCollabTrails;
}
