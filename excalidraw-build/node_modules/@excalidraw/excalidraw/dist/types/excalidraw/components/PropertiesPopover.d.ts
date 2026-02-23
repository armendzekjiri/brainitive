import React, { type ReactNode } from "react";
import * as Popover from "@radix-ui/react-popover";
interface PropertiesPopoverProps {
    className?: string;
    container: HTMLDivElement | null;
    children: ReactNode;
    style?: object;
    onClose: () => void;
    onKeyDown?: React.KeyboardEventHandler<HTMLDivElement>;
    onPointerLeave?: React.PointerEventHandler<HTMLDivElement>;
    onFocusOutside?: Popover.PopoverContentProps["onFocusOutside"];
    onPointerDownOutside?: Popover.PopoverContentProps["onPointerDownOutside"];
}
export declare const PropertiesPopover: React.ForwardRefExoticComponent<PropertiesPopoverProps & React.RefAttributes<HTMLDivElement>>;
export {};
