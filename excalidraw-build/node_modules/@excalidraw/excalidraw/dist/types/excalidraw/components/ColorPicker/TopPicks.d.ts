import type { ColorPickerType } from "./colorPickerUtils";
interface TopPicksProps {
    onChange: (color: string) => void;
    type: ColorPickerType;
    activeColor: string;
    topPicks?: readonly string[];
}
export declare const TopPicks: ({ onChange, type, activeColor, topPicks, }: TopPicksProps) => import("react/jsx-runtime").JSX.Element | null;
export {};
