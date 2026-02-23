import type { ExcalidrawElement, ExcalidrawFrameLikeElement, NonDeletedExcalidrawElement } from "../element/types";
import type { ExportType } from "../scene/types";
import type { AppState, BinaryFiles } from "../types";
import type { FileSystemHandle } from "./filesystem";
export { loadFromBlob } from "./blob";
export { loadFromJSON, saveAsJSON } from "./json";
export type ExportedElements = readonly NonDeletedExcalidrawElement[] & {
    _brand: "exportedElements";
};
export declare const prepareElementsForExport: (elements: readonly ExcalidrawElement[], { selectedElementIds }: Pick<AppState, "selectedElementIds">, exportSelectionOnly: boolean) => {
    exportingFrame: ExcalidrawFrameLikeElement | null;
    exportedElements: ExportedElements;
};
export declare const exportCanvas: (type: Omit<ExportType, "backend">, elements: ExportedElements, appState: AppState, files: BinaryFiles, { exportBackground, exportPadding, viewBackgroundColor, name, fileHandle, exportingFrame, }: {
    exportBackground: boolean;
    exportPadding?: number | undefined;
    viewBackgroundColor: string;
    /** filename, if applicable */
    name?: string | undefined;
    fileHandle?: FileSystemHandle | null | undefined;
    exportingFrame: ExcalidrawFrameLikeElement | null;
}) => Promise<FileSystemHandle | null | undefined>;
