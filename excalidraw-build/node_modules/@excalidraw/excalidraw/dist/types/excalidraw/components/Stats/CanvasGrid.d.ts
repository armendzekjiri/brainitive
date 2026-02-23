/// <reference types="react" />
import type Scene from "../../scene/Scene";
import type { AppState } from "../../types";
interface PositionProps {
    property: "gridStep";
    scene: Scene;
    appState: AppState;
    setAppState: React.Component<any, AppState>["setState"];
}
declare const CanvasGrid: ({ property, scene, appState, setAppState, }: PositionProps) => import("react/jsx-runtime").JSX.Element;
export default CanvasGrid;
