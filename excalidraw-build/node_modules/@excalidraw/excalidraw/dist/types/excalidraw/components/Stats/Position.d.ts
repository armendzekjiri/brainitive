import type { ElementsMap, ExcalidrawElement } from "../../element/types";
import type Scene from "../../scene/Scene";
import type { AppState } from "../../types";
interface PositionProps {
    property: "x" | "y";
    element: ExcalidrawElement;
    elementsMap: ElementsMap;
    scene: Scene;
    appState: AppState;
}
declare const Position: ({ property, element, elementsMap, scene, appState, }: PositionProps) => import("react/jsx-runtime").JSX.Element;
export default Position;
