/// <reference types="react" />
interface TTDDialogOutputProps {
    error: Error | null;
    canvasRef: React.RefObject<HTMLDivElement | null>;
    loaded: boolean;
}
export declare const TTDDialogOutput: ({ error, canvasRef, loaded, }: TTDDialogOutputProps) => import("react/jsx-runtime").JSX.Element;
export {};
