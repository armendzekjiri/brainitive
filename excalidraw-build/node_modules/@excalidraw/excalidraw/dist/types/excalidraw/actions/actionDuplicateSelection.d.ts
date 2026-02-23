/// <reference types="react" />
import type { ExcalidrawElement } from "../element/types";
import type { AppState } from "../types";
export declare const actionDuplicateSelection: {
    name: "duplicateSelection";
    label: string;
    icon: import("react/jsx-runtime").JSX.Element;
    trackEvent: {
        category: "element";
    };
    perform: (elements: readonly import("../element/types").OrderedExcalidrawElement[], appState: Readonly<AppState>, formData: any, app: import("../types").AppClassProperties) => false | {
        captureUpdate: "IMMEDIATELY";
        elements?: readonly ExcalidrawElement[] | null | undefined;
        appState?: Partial<AppState> | null | undefined;
        files?: import("../types").BinaryFiles | null | undefined;
        replaceFiles?: boolean | undefined;
    };
    keyTest: (event: KeyboardEvent | import("react").KeyboardEvent<Element>) => boolean;
    PanelComponent: ({ elements, appState, updateData }: import("./types").PanelComponentProps) => import("react/jsx-runtime").JSX.Element;
} & {
    keyTest?: ((event: KeyboardEvent | import("react").KeyboardEvent<Element>) => boolean) | undefined;
};
