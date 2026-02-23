import type { StaticSceneRenderConfig } from "../scene/types";
/** throttled to animation framerate */
export declare const renderStaticSceneThrottled: {
    (config: StaticSceneRenderConfig): void;
    flush(): void;
    cancel(): void;
};
/**
 * Static scene is the non-ui canvas where we render elements.
 */
export declare const renderStaticScene: (renderConfig: StaticSceneRenderConfig, throttle?: boolean) => void;
