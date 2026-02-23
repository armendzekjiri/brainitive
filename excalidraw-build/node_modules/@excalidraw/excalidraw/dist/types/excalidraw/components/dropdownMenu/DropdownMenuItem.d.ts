import type { JSX } from "react";
import React from "react";
import type { ValueOf } from "../../utility-types";
declare const DropdownMenuItem: {
    ({ icon, value, order, children, shortcut, className, hovered, selected, textStyle, onSelect, onClick, ...rest }: {
        icon?: JSX.Element | undefined;
        value?: string | number | undefined;
        order?: number | undefined;
        onSelect?: ((event: Event) => void) | undefined;
        children: React.ReactNode;
        shortcut?: string | undefined;
        hovered?: boolean | undefined;
        selected?: boolean | undefined;
        textStyle?: React.CSSProperties | undefined;
        className?: string | undefined;
    } & Omit<React.ButtonHTMLAttributes<HTMLButtonElement>, "onSelect">): import("react/jsx-runtime").JSX.Element;
    displayName: string;
    Badge: {
        ({ type, children, }: {
            type?: ValueOf<{
                readonly GREEN: "green";
                readonly RED: "red";
                readonly BLUE: "blue";
            }> | undefined;
            children: React.ReactNode;
        }): import("react/jsx-runtime").JSX.Element;
        displayName: string;
    };
};
export declare const DropDownMenuItemBadgeType: {
    readonly GREEN: "green";
    readonly RED: "red";
    readonly BLUE: "blue";
};
export declare const DropDownMenuItemBadge: {
    ({ type, children, }: {
        type?: ValueOf<{
            readonly GREEN: "green";
            readonly RED: "red";
            readonly BLUE: "blue";
        }> | undefined;
        children: React.ReactNode;
    }): import("react/jsx-runtime").JSX.Element;
    displayName: string;
};
export default DropdownMenuItem;
