import type { ExcalidrawElement } from "../../element/types";
import type Scene from "../../scene/Scene";
import type { AppState } from "../../types";
interface DimensionDragInputProps {
    property: "width" | "height";
    element: ExcalidrawElement;
    scene: Scene;
    appState: AppState;
}
declare const DimensionDragInput: ({ property, element, scene, appState, }: DimensionDragInputProps) => import("react/jsx-runtime").JSX.Element;
export default DimensionDragInput;
