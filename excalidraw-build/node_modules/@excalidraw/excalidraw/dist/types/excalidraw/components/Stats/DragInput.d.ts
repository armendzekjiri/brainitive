/// <reference types="react" />
import type { ElementsMap, ExcalidrawElement } from "../../element/types";
import type { StatsInputProperty } from "./utils";
import type Scene from "../../scene/Scene";
import "./DragInput.scss";
import type { AppState } from "../../types";
export type DragInputCallbackType<P extends StatsInputProperty, E = ExcalidrawElement> = (props: {
    accumulatedChange: number;
    instantChange: number;
    originalElements: readonly E[];
    originalElementsMap: ElementsMap;
    shouldKeepAspectRatio: boolean;
    shouldChangeByStepSize: boolean;
    scene: Scene;
    nextValue?: number;
    property: P;
    originalAppState: AppState;
    setInputValue: (value: number) => void;
}) => void;
interface StatsDragInputProps<T extends StatsInputProperty, E = ExcalidrawElement> {
    label: string | React.ReactNode;
    icon?: React.ReactNode;
    value: number | "Mixed";
    elements: readonly E[];
    editable?: boolean;
    shouldKeepAspectRatio?: boolean;
    dragInputCallback: DragInputCallbackType<T, E>;
    property: T;
    scene: Scene;
    appState: AppState;
    /** how many px you need to drag to get 1 unit change */
    sensitivity?: number;
}
declare const StatsDragInput: <T extends StatsInputProperty, E extends ExcalidrawElement = ExcalidrawElement>({ label, icon, dragInputCallback, value, elements, editable, shouldKeepAspectRatio, property, scene, appState, sensitivity, }: StatsDragInputProps<T, E>) => import("react/jsx-runtime").JSX.Element | null;
export default StatsDragInput;
