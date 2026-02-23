export type AnimationCallback = (timestamp: number) => void | boolean;
export type AnimationTarget = {
    callback: AnimationCallback;
    stopped: boolean;
};
export declare class AnimationFrameHandler {
    private targets;
    private rafIds;
    register(key: object, callback: AnimationCallback): void;
    start(key: object): void;
    stop(key: object): void;
    private constructFrame;
    private scheduleFrame;
    private cancelFrame;
    private onFrame;
}
