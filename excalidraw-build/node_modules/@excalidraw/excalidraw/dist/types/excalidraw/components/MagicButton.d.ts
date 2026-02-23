import type { JSX } from "react";
import "./ToolIcon.scss";
export declare const ElementCanvasButton: (props: {
    title?: string;
    icon: JSX.Element;
    name?: string;
    checked: boolean;
    onChange?(): void;
    isMobile?: boolean;
}) => import("react/jsx-runtime").JSX.Element;
