type CANVAS_ERROR_NAMES = "CANVAS_ERROR" | "CANVAS_POSSIBLY_TOO_BIG";
export declare class CanvasError extends Error {
    constructor(message?: string, name?: CANVAS_ERROR_NAMES);
}
export declare class AbortError extends DOMException {
    constructor(message?: string);
}
type ImageSceneDataErrorCode = "IMAGE_NOT_CONTAINS_SCENE_DATA" | "IMAGE_SCENE_DATA_ERROR";
export declare class ImageSceneDataError extends Error {
    code: ImageSceneDataErrorCode;
    constructor(message?: string, code?: ImageSceneDataErrorCode);
}
export declare class InvalidFractionalIndexError extends Error {
    code: "ELEMENT_HAS_INVALID_INDEX";
}
type WorkerErrorCodes = "WORKER_URL_NOT_DEFINED" | "WORKER_IN_THE_MAIN_CHUNK";
export declare class WorkerUrlNotDefinedError extends Error {
    code: WorkerErrorCodes;
    constructor(message?: string, code?: WorkerErrorCodes);
}
export declare class WorkerInTheMainChunkError extends Error {
    code: WorkerErrorCodes;
    constructor(message?: string, code?: WorkerErrorCodes);
}
/**
 * Use this for generic, handled errors, so you can check against them
 * and rethrow if needed
 */
export declare class ExcalidrawError extends Error {
    constructor(message: string);
}
export {};
