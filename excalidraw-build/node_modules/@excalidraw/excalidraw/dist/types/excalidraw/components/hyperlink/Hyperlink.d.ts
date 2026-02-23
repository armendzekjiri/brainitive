import type { AppState, ExcalidrawProps, UIAppState } from "../../types";
import type { ElementsMap, ExcalidrawEmbeddableElement, NonDeletedExcalidrawElement } from "../../element/types";
import "./Hyperlink.scss";
export declare const Hyperlink: ({ element, elementsMap, setAppState, onLinkOpen, setToast, updateEmbedValidationStatus, }: {
    element: NonDeletedExcalidrawElement;
    elementsMap: ElementsMap;
    setAppState: React.Component<any, AppState>["setState"];
    onLinkOpen: ExcalidrawProps["onLinkOpen"];
    setToast: (toast: {
        message: string;
        closable?: boolean;
        duration?: number;
    } | null) => void;
    updateEmbedValidationStatus: (element: ExcalidrawEmbeddableElement, status: boolean) => void;
}) => import("react/jsx-runtime").JSX.Element | null;
export declare const getContextMenuLabel: (elements: readonly NonDeletedExcalidrawElement[], appState: UIAppState) => "labels.link.editEmbed" | "labels.link.edit" | "labels.link.create";
export declare const showHyperlinkTooltip: (element: NonDeletedExcalidrawElement, appState: AppState, elementsMap: ElementsMap) => void;
export declare const hideHyperlinkToolip: () => void;
