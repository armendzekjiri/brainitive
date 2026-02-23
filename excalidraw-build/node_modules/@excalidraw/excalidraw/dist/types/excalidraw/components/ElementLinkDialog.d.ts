import type { AppProps, UIAppState } from "../types";
import type { ElementsMap, ExcalidrawElement } from "../element/types";
import "./ElementLinkDialog.scss";
declare const ElementLinkDialog: ({ sourceElementId, onClose, elementsMap, appState, generateLinkForSelection, }: {
    sourceElementId: ExcalidrawElement["id"];
    elementsMap: ElementsMap;
    appState: UIAppState;
    onClose?: (() => void) | undefined;
    generateLinkForSelection: AppProps["generateLinkForSelection"];
}) => import("react/jsx-runtime").JSX.Element;
export default ElementLinkDialog;
