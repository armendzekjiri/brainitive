/// <reference types="react" />
import type { ExcalidrawElement } from "../element/types";
import type { AppClassProperties, AppState } from "../types";
export declare const distributeHorizontally: {
    name: "distributeHorizontally";
    label: string;
    trackEvent: {
        category: "element";
    };
    perform: (elements: readonly import("../element/types").OrderedExcalidrawElement[], appState: Readonly<AppState>, _: any, app: AppClassProperties) => {
        appState: Readonly<AppState>;
        elements: ExcalidrawElement[];
        captureUpdate: "IMMEDIATELY";
    };
    keyTest: (event: KeyboardEvent | import("react").KeyboardEvent<Element>) => boolean;
    PanelComponent: ({ elements, appState, updateData, app }: import("./types").PanelComponentProps) => import("react/jsx-runtime").JSX.Element;
} & {
    keyTest?: ((event: KeyboardEvent | import("react").KeyboardEvent<Element>) => boolean) | undefined;
};
export declare const distributeVertically: {
    name: "distributeVertically";
    label: string;
    trackEvent: {
        category: "element";
    };
    perform: (elements: readonly import("../element/types").OrderedExcalidrawElement[], appState: Readonly<AppState>, _: any, app: AppClassProperties) => {
        appState: Readonly<AppState>;
        elements: ExcalidrawElement[];
        captureUpdate: "IMMEDIATELY";
    };
    keyTest: (event: KeyboardEvent | import("react").KeyboardEvent<Element>) => boolean;
    PanelComponent: ({ elements, appState, updateData, app }: import("./types").PanelComponentProps) => import("react/jsx-runtime").JSX.Element;
} & {
    keyTest?: ((event: KeyboardEvent | import("react").KeyboardEvent<Element>) => boolean) | undefined;
};
