import type { NonDeletedSceneElementsMap } from "../../element/types";
import type { AppState } from "../../types";
import type { RenderableElementsMap, StaticCanvasRenderConfig } from "../../scene/types";
import type { RoughCanvas } from "roughjs/bin/canvas";
interface NewElementCanvasProps {
    appState: AppState;
    elementsMap: RenderableElementsMap;
    allElementsMap: NonDeletedSceneElementsMap;
    scale: number;
    rc: RoughCanvas;
    renderConfig: StaticCanvasRenderConfig;
}
declare const NewElementCanvas: (props: NewElementCanvasProps) => import("react/jsx-runtime").JSX.Element;
export default NewElementCanvas;
