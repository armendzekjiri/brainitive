import type { ExcalidrawElement } from "../../element/types";
import type Scene from "../../scene/Scene";
import type { AppState } from "../../types";
interface AngleProps {
    element: ExcalidrawElement;
    scene: Scene;
    appState: AppState;
    property: "angle";
}
declare const Angle: ({ element, scene, appState, property }: AngleProps) => import("react/jsx-runtime").JSX.Element;
export default Angle;
