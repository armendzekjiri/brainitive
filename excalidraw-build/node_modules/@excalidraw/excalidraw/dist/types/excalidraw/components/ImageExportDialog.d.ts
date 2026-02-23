import type { ActionManager } from "../actions/manager";
import type { AppClassProperties, BinaryFiles, UIAppState } from "../types";
import type { NonDeletedExcalidrawElement } from "../element/types";
import "./ImageExportDialog.scss";
export declare const ErrorCanvasPreview: () => import("react/jsx-runtime").JSX.Element;
export declare const ImageExportDialog: ({ elements, appState, files, actionManager, onExportImage, onCloseRequest, name, }: {
    appState: UIAppState;
    elements: readonly NonDeletedExcalidrawElement[];
    files: BinaryFiles;
    actionManager: ActionManager;
    onExportImage: AppClassProperties["onExportImage"];
    onCloseRequest: () => void;
    name: string;
}) => import("react/jsx-runtime").JSX.Element;
