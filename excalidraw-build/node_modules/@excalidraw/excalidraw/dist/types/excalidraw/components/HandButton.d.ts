import "./ToolIcon.scss";
type LockIconProps = {
    title?: string;
    name?: string;
    checked: boolean;
    onChange?(): void;
    isMobile?: boolean;
};
export declare const HandButton: (props: LockIconProps) => import("react/jsx-runtime").JSX.Element;
export {};
