import React from "react";
import type { FontFamilyValues } from "../../element/types";
import "./FontPicker.scss";
export declare const DEFAULT_FONTS: {
    value: number;
    icon: import("react/jsx-runtime").JSX.Element;
    text: string;
    testId: string;
}[];
export declare const isDefaultFont: (fontFamily: number | null) => boolean;
interface FontPickerProps {
    isOpened: boolean;
    selectedFontFamily: FontFamilyValues | null;
    hoveredFontFamily: FontFamilyValues | null;
    onSelect: (fontFamily: FontFamilyValues) => void;
    onHover: (fontFamily: FontFamilyValues) => void;
    onLeave: () => void;
    onPopupChange: (open: boolean) => void;
}
export declare const FontPicker: React.MemoExoticComponent<({ isOpened, selectedFontFamily, hoveredFontFamily, onSelect, onHover, onLeave, onPopupChange, }: FontPickerProps) => import("react/jsx-runtime").JSX.Element>;
export {};
