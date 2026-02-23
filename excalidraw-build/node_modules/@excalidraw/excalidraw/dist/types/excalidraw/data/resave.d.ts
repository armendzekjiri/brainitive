import type { ExcalidrawElement } from "../element/types";
import type { AppState, BinaryFiles } from "../types";
export declare const resaveAsImageWithScene: (elements: readonly ExcalidrawElement[], appState: AppState, files: BinaryFiles, name: string) => Promise<{
    fileHandle: import("browser-fs-access").FileSystemHandle;
}>;
