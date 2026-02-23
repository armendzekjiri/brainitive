import "./ToolIcon.scss";
type PenModeIconProps = {
    title?: string;
    name?: string;
    checked: boolean;
    onChange?(): void;
    zenModeEnabled?: boolean;
    isMobile?: boolean;
    penDetected: boolean;
};
export declare const PenModeButton: (props: PenModeIconProps) => import("react/jsx-runtime").JSX.Element | null;
export {};
