import "./Range.scss";
export type RangeProps = {
    updateData: (value: number) => void;
    appState: any;
    elements: any;
    testId?: string;
};
export declare const Range: ({ updateData, appState, elements, testId, }: RangeProps) => import("react/jsx-runtime").JSX.Element;
