import type { ReactNode } from "react";
interface TTDDialogPanelProps {
    label: string;
    children: ReactNode;
    panelAction?: {
        label: string;
        action: () => void;
        icon?: ReactNode;
    };
    panelActionDisabled?: boolean;
    onTextSubmitInProgess?: boolean;
    renderTopRight?: () => ReactNode;
    renderSubmitShortcut?: () => ReactNode;
    renderBottomRight?: () => ReactNode;
}
export declare const TTDDialogPanel: ({ label, children, panelAction, panelActionDisabled, onTextSubmitInProgess, renderTopRight, renderSubmitShortcut, renderBottomRight, }: TTDDialogPanelProps) => import("react/jsx-runtime").JSX.Element;
export {};
