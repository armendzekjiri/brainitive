import type { ExcalidrawElement, NonDeletedExcalidrawElement } from "../element/types";
import type { AppState } from "../types";
import type Scene from "./Scene";
export declare class Renderer {
    private scene;
    constructor(scene: Scene);
    getRenderableElements: ((opts: {
        zoom: AppState["zoom"];
        offsetLeft: AppState["offsetLeft"];
        offsetTop: AppState["offsetTop"];
        scrollX: AppState["scrollX"];
        scrollY: AppState["scrollY"];
        height: AppState["height"];
        width: AppState["width"];
        editingTextElement: AppState["editingTextElement"];
        /** note: first render of newElement will always bust the cache
         * (we'd have to prefilter elements outside of this function) */
        newElementId: ExcalidrawElement["id"] | undefined;
        pendingImageElementId: AppState["pendingImageElementId"];
        sceneNonce: ReturnType<InstanceType<typeof Scene>["getSceneNonce"]>;
    }) => {
        elementsMap: Map<string, NonDeletedExcalidrawElement> & import("../utility-types").MakeBrand<"NonDeletedElementsMap"> & import("../utility-types").MakeBrand<"RenderableElementsMap">;
        visibleElements: readonly NonDeletedExcalidrawElement[];
    }) & {
        clear: () => void;
    };
    destroy(): void;
}
