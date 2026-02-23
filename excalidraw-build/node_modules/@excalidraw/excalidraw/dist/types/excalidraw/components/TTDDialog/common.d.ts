/// <reference types="react" />
import type { MermaidConfig } from "@excalidraw/mermaid-to-excalidraw";
import type { MermaidToExcalidrawResult } from "@excalidraw/mermaid-to-excalidraw/dist/interfaces";
import type { NonDeletedExcalidrawElement } from "../../element/types";
import type { AppClassProperties, BinaryFiles } from "../../types";
export interface MermaidToExcalidrawLibProps {
    loaded: boolean;
    api: Promise<{
        parseMermaidToExcalidraw: (definition: string, config?: MermaidConfig) => Promise<MermaidToExcalidrawResult>;
    }>;
}
interface ConvertMermaidToExcalidrawFormatProps {
    canvasRef: React.RefObject<HTMLDivElement | null>;
    mermaidToExcalidrawLib: MermaidToExcalidrawLibProps;
    mermaidDefinition: string;
    setError: (error: Error | null) => void;
    data: React.MutableRefObject<{
        elements: readonly NonDeletedExcalidrawElement[];
        files: BinaryFiles | null;
    }>;
}
export declare const convertMermaidToExcalidraw: ({ canvasRef, mermaidToExcalidrawLib, mermaidDefinition, setError, data, }: ConvertMermaidToExcalidrawFormatProps) => Promise<void>;
export declare const saveMermaidDataToStorage: (mermaidDefinition: string) => void;
export declare const insertToEditor: ({ app, data, text, shouldSaveMermaidDataToStorage, }: {
    app: AppClassProperties;
    data: React.MutableRefObject<{
        elements: readonly NonDeletedExcalidrawElement[];
        files: BinaryFiles | null;
    }>;
    text?: string | undefined;
    shouldSaveMermaidDataToStorage?: boolean | undefined;
}) => void;
export {};
