import type { JSX } from "react";
import React from "react";
declare const DropdownMenuItemLink: {
    ({ icon, shortcut, href, children, onSelect, className, selected, rel, ...rest }: {
        href: string;
        icon?: JSX.Element | undefined;
        children: React.ReactNode;
        shortcut?: string | undefined;
        className?: string | undefined;
        selected?: boolean | undefined;
        onSelect?: ((event: Event) => void) | undefined;
        rel?: string | undefined;
    } & React.AnchorHTMLAttributes<HTMLAnchorElement>): import("react/jsx-runtime").JSX.Element;
    displayName: string;
};
export default DropdownMenuItemLink;
