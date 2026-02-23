import type { ExcalidrawElement, NonDeletedExcalidrawElement } from "./element/types";
import type { BinaryFiles } from "./types";
import type { Spreadsheet } from "./charts";
import { ALLOWED_PASTE_MIME_TYPES } from "./constants";
export type PastedMixedContent = {
    type: "text" | "imageUrl";
    value: string;
}[];
export interface ClipboardData {
    spreadsheet?: Spreadsheet;
    elements?: readonly ExcalidrawElement[];
    files?: BinaryFiles;
    text?: string;
    mixedContent?: PastedMixedContent;
    errorMessage?: string;
    programmaticAPI?: boolean;
}
type AllowedPasteMimeTypes = typeof ALLOWED_PASTE_MIME_TYPES[number];
export declare const probablySupportsClipboardReadText: boolean;
export declare const probablySupportsClipboardWriteText: boolean;
export declare const probablySupportsClipboardBlob: boolean;
export declare const createPasteEvent: ({ types, files, }: {
    types?: {
        "image/svg+xml"?: string | File | undefined;
        "image/png"?: string | File | undefined;
        "image/jpeg"?: string | File | undefined;
        "image/gif"?: string | File | undefined;
        "image/webp"?: string | File | undefined;
        "image/bmp"?: string | File | undefined;
        "image/x-icon"?: string | File | undefined;
        "image/avif"?: string | File | undefined;
        "image/jfif"?: string | File | undefined;
        "text/plain"?: string | File | undefined;
        "text/html"?: string | File | undefined;
    } | undefined;
    files?: File[] | undefined;
}) => ClipboardEvent;
export declare const serializeAsClipboardJSON: ({ elements, files, }: {
    elements: readonly NonDeletedExcalidrawElement[];
    files: BinaryFiles | null;
}) => string;
export declare const copyToClipboard: (elements: readonly NonDeletedExcalidrawElement[], files: BinaryFiles | null, clipboardEvent?: ClipboardEvent | null) => Promise<void>;
/**
 * Reads OS clipboard programmatically. May not work on all browsers.
 * Will prompt user for permission if not granted.
 */
export declare const readSystemClipboard: () => Promise<{
    "image/svg+xml"?: string | File | undefined;
    "image/png"?: string | File | undefined;
    "image/jpeg"?: string | File | undefined;
    "image/gif"?: string | File | undefined;
    "image/webp"?: string | File | undefined;
    "image/bmp"?: string | File | undefined;
    "image/x-icon"?: string | File | undefined;
    "image/avif"?: string | File | undefined;
    "image/jfif"?: string | File | undefined;
    "text/plain"?: string | File | undefined;
    "text/html"?: string | File | undefined;
}>;
/**
 * Attempts to parse clipboard event.
 */
export declare const parseClipboard: (event: ClipboardEvent, isPlainPaste?: boolean) => Promise<ClipboardData>;
export declare const copyBlobToClipboardAsPng: (blob: Blob | Promise<Blob>) => Promise<void>;
export declare const copyTextToSystemClipboard: (text: string | null, clipboardEvent?: ClipboardEvent | null) => Promise<void>;
export {};
