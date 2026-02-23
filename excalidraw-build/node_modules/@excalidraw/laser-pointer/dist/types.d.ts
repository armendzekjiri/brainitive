export type Point = [x: number, y: number, r: number];
export type SizeMappingDetails = {
    pressure: number;
    runningLength: number;
    currentIndex: number;
    totalLength: number;
};
export type LaserPointerOptions = {
    size: number;
    streamline: number;
    simplify: number;
    simplifyPhase: 'tail' | 'output' | 'input';
    keepHead: boolean;
    sizeMapping: (details: SizeMappingDetails) => number;
};
export class LaserPointer {
    static defaults: LaserPointerOptions;
    static constants: {
        cornerDetectionMaxAngle: number;
        cornerDetectionVariance: (s: number) => 1 | 0.5;
        maxTailLength: number;
    };
    options: LaserPointerOptions;
    constructor(options: Partial<LaserPointerOptions>);
    originalPoints: Point[];
    private get lastPoint();
    addPoint(point: Point): void;
    close(): void;
    stabilizeTail(): void;
    getStrokeOutline(sizeOverride?: number | undefined): Point[];
}

//# sourceMappingURL=types.d.ts.map
