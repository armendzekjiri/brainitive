import type { JSX } from "react";
declare const MenuItemContent: ({ textStyle, icon, shortcut, children, }: {
    icon?: JSX.Element | undefined;
    shortcut?: string | undefined;
    textStyle?: import("react").CSSProperties | undefined;
    children: React.ReactNode;
}) => import("react/jsx-runtime").JSX.Element;
export default MenuItemContent;
