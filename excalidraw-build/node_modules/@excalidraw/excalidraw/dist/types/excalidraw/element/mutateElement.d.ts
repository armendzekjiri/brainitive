import type { ExcalidrawElement } from "./types";
import type { Mutable } from "../utility-types";
export type ElementUpdate<TElement extends ExcalidrawElement> = Omit<Partial<TElement>, "id" | "version" | "versionNonce" | "updated">;
export declare const mutateElement: <TElement extends Mutable<ExcalidrawElement>>(element: TElement, updates: ElementUpdate<TElement>, informMutation?: boolean, options?: {
    isDragging?: boolean;
}) => TElement;
export declare const newElementWith: <TElement extends ExcalidrawElement>(element: TElement, updates: ElementUpdate<TElement>, force?: boolean) => TElement;
/**
 * Mutates element, bumping `version`, `versionNonce`, and `updated`.
 *
 * NOTE: does not trigger re-render.
 */
export declare const bumpVersion: <T extends Mutable<ExcalidrawElement>>(element: T, version?: ExcalidrawElement["version"]) => T;
