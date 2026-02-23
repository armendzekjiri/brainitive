import type { ExcalidrawElement } from "../../element/types";
import type Scene from "../../scene/Scene";
import type { AppState } from "../../types";
interface MultiAngleProps {
    elements: readonly ExcalidrawElement[];
    scene: Scene;
    appState: AppState;
    property: "angle";
}
declare const MultiAngle: ({ elements, scene, appState, property, }: MultiAngleProps) => import("react/jsx-runtime").JSX.Element;
export default MultiAngle;
