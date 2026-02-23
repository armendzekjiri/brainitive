import type { ActionManager } from "../actions/manager";
import type { ExcalidrawElement, NonDeletedElementsMap, NonDeletedSceneElementsMap } from "../element/types";
import type { AppClassProperties, AppProps, UIAppState, Zoom } from "../types";
import "./Actions.scss";
export declare const canChangeStrokeColor: (appState: UIAppState, targetElements: ExcalidrawElement[]) => boolean;
export declare const canChangeBackgroundColor: (appState: UIAppState, targetElements: ExcalidrawElement[]) => boolean;
export declare const SelectedShapeActions: ({ appState, elementsMap, renderAction, app, }: {
    appState: UIAppState;
    elementsMap: NonDeletedElementsMap | NonDeletedSceneElementsMap;
    renderAction: ActionManager["renderAction"];
    app: AppClassProperties;
}) => import("react/jsx-runtime").JSX.Element;
export declare const ShapesSwitcher: ({ activeTool, appState, app, UIOptions, }: {
    activeTool: UIAppState["activeTool"];
    appState: UIAppState;
    app: AppClassProperties;
    UIOptions: AppProps["UIOptions"];
}) => import("react/jsx-runtime").JSX.Element;
export declare const ZoomActions: ({ renderAction, zoom, }: {
    renderAction: ActionManager["renderAction"];
    zoom: Zoom;
}) => import("react/jsx-runtime").JSX.Element;
export declare const UndoRedoActions: ({ renderAction, className, }: {
    renderAction: ActionManager["renderAction"];
    className?: string | undefined;
}) => import("react/jsx-runtime").JSX.Element;
export declare const ExitZenModeAction: ({ actionManager, showExitZenModeBtn, }: {
    actionManager: ActionManager;
    showExitZenModeBtn: boolean;
}) => import("react/jsx-runtime").JSX.Element;
export declare const FinalizeAction: ({ renderAction, className, }: {
    renderAction: ActionManager["renderAction"];
    className?: string | undefined;
}) => import("react/jsx-runtime").JSX.Element;
