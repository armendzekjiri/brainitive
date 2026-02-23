import type { OrderedExcalidrawElement } from "../element/types";
import type { AppState } from "../types";
import type { MakeBrand } from "../utility-types";
export type ReconciledExcalidrawElement = OrderedExcalidrawElement & MakeBrand<"ReconciledElement">;
export type RemoteExcalidrawElement = OrderedExcalidrawElement & MakeBrand<"RemoteExcalidrawElement">;
export declare const reconcileElements: (localElements: readonly OrderedExcalidrawElement[], remoteElements: readonly RemoteExcalidrawElement[], localAppState: AppState) => ReconciledExcalidrawElement[];
