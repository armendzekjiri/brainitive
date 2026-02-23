import type { JSX } from "react";
import "./IconPicker.scss";
export declare function IconPicker<T>({ value, label, options, onChange, group, numberOfOptionsToAlwaysShow, }: {
    label: string;
    value: T;
    options: readonly {
        value: T;
        text: string;
        icon: JSX.Element;
        keyBinding: string | null;
    }[];
    onChange: (value: T) => void;
    numberOfOptionsToAlwaysShow?: number;
    group?: string;
}): import("react/jsx-runtime").JSX.Element;
