/// <reference types="react" />
import type { SidebarTabName } from "../../types";
export declare const SidebarTab: {
    ({ tab, children, ...rest }: {
        tab: SidebarTabName;
        children: React.ReactNode;
    } & import("react").HTMLAttributes<HTMLDivElement>): import("react/jsx-runtime").JSX.Element;
    displayName: string;
};
