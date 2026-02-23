import React from "react";
import type { Action, UpdaterFn, ActionName, ActionResult, PanelComponentProps, ActionSource } from "./types";
import type { OrderedExcalidrawElement } from "../element/types";
import type { AppClassProperties, AppState } from "../types";
export declare class ActionManager {
    actions: Record<ActionName, Action>;
    updater: (actionResult: ActionResult | Promise<ActionResult>) => void;
    getAppState: () => Readonly<AppState>;
    getElementsIncludingDeleted: () => readonly OrderedExcalidrawElement[];
    app: AppClassProperties;
    constructor(updater: UpdaterFn, getAppState: () => AppState, getElementsIncludingDeleted: () => readonly OrderedExcalidrawElement[], app: AppClassProperties);
    registerAction(action: Action): void;
    registerAll(actions: readonly Action[]): void;
    handleKeyDown(event: React.KeyboardEvent | KeyboardEvent): boolean;
    executeAction<T extends Action>(action: T, source?: ActionSource, value?: Parameters<T["perform"]>[2]): void;
    /**
     * @param data additional data sent to the PanelComponent
     */
    renderAction: (name: ActionName, data?: PanelComponentProps["data"]) => import("react/jsx-runtime").JSX.Element | null;
    isActionEnabled: (action: Action) => boolean;
}
