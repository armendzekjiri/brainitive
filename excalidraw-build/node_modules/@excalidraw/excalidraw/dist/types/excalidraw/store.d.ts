import { AppStateChange, ElementsChange } from "./change";
import type { OrderedExcalidrawElement } from "./element/types";
import { Emitter } from "./emitter";
import type { AppState, ObservedAppState } from "./types";
import type { ValueOf } from "./utility-types";
export declare const getObservedAppState: (appState: AppState) => ObservedAppState;
export declare const CaptureUpdateAction: {
    /**
     * Immediately undoable.
     *
     * Use for updates which should be captured.
     * Should be used for most of the local updates.
     *
     * These updates will _immediately_ make it to the local undo / redo stacks.
     */
    readonly IMMEDIATELY: "IMMEDIATELY";
    /**
     * Never undoable.
     *
     * Use for updates which should never be recorded, such as remote updates
     * or scene initialization.
     *
     * These updates will _never_ make it to the local undo / redo stacks.
     */
    readonly NEVER: "NEVER";
    /**
     * Eventually undoable.
     *
     * Use for updates which should not be captured immediately - likely
     * exceptions which are part of some async multi-step process. Otherwise, all
     * such updates would end up being captured with the next
     * `CaptureUpdateAction.IMMEDIATELY` - triggered either by the next `updateScene`
     * or internally by the editor.
     *
     * These updates will _eventually_ make it to the local undo / redo stacks.
     */
    readonly EVENTUALLY: "EVENTUALLY";
};
export type CaptureUpdateActionType = ValueOf<typeof CaptureUpdateAction>;
/**
 * Represent an increment to the Store.
 */
declare class StoreIncrementEvent {
    readonly elementsChange: ElementsChange;
    readonly appStateChange: AppStateChange;
    constructor(elementsChange: ElementsChange, appStateChange: AppStateChange);
}
/**
 * Store which captures the observed changes and emits them as `StoreIncrementEvent` events.
 *
 * @experimental this interface is experimental and subject to change.
 */
export interface IStore {
    onStoreIncrementEmitter: Emitter<[StoreIncrementEvent]>;
    get snapshot(): Snapshot;
    set snapshot(snapshot: Snapshot);
    /**
     * Use to schedule update of the snapshot, useful on updates for which we don't need to calculate increments (i.e. remote updates).
     */
    shouldUpdateSnapshot(): void;
    /**
     * Use to schedule calculation of a store increment.
     */
    shouldCaptureIncrement(): void;
    /**
     * Based on the scheduled operation, either only updates store snapshot or also calculates increment and emits the result as a `StoreIncrementEvent`.
     *
     * @emits StoreIncrementEvent when increment is calculated.
     */
    commit(elements: Map<string, OrderedExcalidrawElement> | undefined, appState: AppState | ObservedAppState | undefined): void;
    /**
     * Clears the store instance.
     */
    clear(): void;
    /**
     * Filters out yet uncomitted elements from `nextElements`, which are part of in-progress local async actions (ephemerals) and thus were not yet commited to the snapshot.
     *
     * This is necessary in updates in which we receive reconciled elements, already containing elements which were not yet captured by the local store (i.e. collab).
     */
    filterUncomittedElements(prevElements: Map<string, OrderedExcalidrawElement>, nextElements: Map<string, OrderedExcalidrawElement>): Map<string, OrderedExcalidrawElement>;
}
export declare class Store implements IStore {
    readonly onStoreIncrementEmitter: Emitter<[StoreIncrementEvent]>;
    private scheduledActions;
    private _snapshot;
    get snapshot(): Snapshot;
    set snapshot(snapshot: Snapshot);
    shouldCaptureIncrement: () => void;
    shouldUpdateSnapshot: () => void;
    private scheduleAction;
    commit: (elements: Map<string, OrderedExcalidrawElement> | undefined, appState: AppState | ObservedAppState | undefined) => void;
    captureIncrement: (elements: Map<string, OrderedExcalidrawElement> | undefined, appState: AppState | ObservedAppState | undefined) => void;
    updateSnapshot: (elements: Map<string, OrderedExcalidrawElement> | undefined, appState: AppState | ObservedAppState | undefined) => void;
    filterUncomittedElements: (prevElements: Map<string, OrderedExcalidrawElement>, nextElements: Map<string, OrderedExcalidrawElement>) => Map<string, OrderedExcalidrawElement>;
    clear: () => void;
    private satisfiesScheduledActionsInvariant;
}
export declare class Snapshot {
    readonly elements: Map<string, OrderedExcalidrawElement>;
    readonly appState: ObservedAppState;
    readonly meta: {
        didElementsChange: boolean;
        didAppStateChange: boolean;
        isEmpty?: boolean;
    };
    private constructor();
    static empty(): Snapshot;
    isEmpty(): boolean | undefined;
    /**
     * Efficiently clone the existing snapshot, only if we detected changes.
     *
     * @returns same instance if there are no changes detected, new instance otherwise.
     */
    maybeClone(elements: Map<string, OrderedExcalidrawElement> | undefined, appState: AppState | ObservedAppState | undefined): Snapshot;
    private maybeCreateAppStateSnapshot;
    private detectChangedAppState;
    private maybeCreateElementsSnapshot;
    /**
     * Detect if there any changed elements.
     *
     * NOTE: we shouldn't just use `sceneVersionNonce` instead, as we need to call this before the scene updates.
     */
    private detectChangedElements;
    /**
     * Perform structural clone, cloning only elements that changed.
     */
    private createElementsSnapshot;
}
export {};
