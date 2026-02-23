import React from "react";
import type { Language } from "../i18n";
import type { Theme } from "../element/types";
interface Props {
    langCode: Language["code"];
    children: React.ReactElement;
    theme?: Theme;
}
export declare const InitializeApp: (props: Props) => import("react/jsx-runtime").JSX.Element;
export {};
