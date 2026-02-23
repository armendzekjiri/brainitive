import type { AppStateChange, ElementsChange } from "./change";
import type { SceneElementsMap } from "./element/types";
import { Emitter } from "./emitter";
import type { Snapshot } from "./store";
import type { AppState } from "./types";
export declare class HistoryChangedEvent {
    readonly isUndoStackEmpty: boolean;
    readonly isRedoStackEmpty: boolean;
    constructor(isUndoStackEmpty?: boolean, isRedoStackEmpty?: boolean);
}
export declare class History {
    readonly onHistoryChangedEmitter: Emitter<[HistoryChangedEvent]>;
    private readonly undoStack;
    private readonly redoStack;
    get isUndoStackEmpty(): boolean;
    get isRedoStackEmpty(): boolean;
    clear(): void;
    /**
     * Record a local change which will go into the history
     */
    record(elementsChange: ElementsChange, appStateChange: AppStateChange): void;
    undo(elements: SceneElementsMap, appState: AppState, snapshot: Readonly<Snapshot>): void | [SceneElementsMap, AppState];
    redo(elements: SceneElementsMap, appState: AppState, snapshot: Readonly<Snapshot>): void | [SceneElementsMap, AppState];
    private perform;
    private static pop;
    private static push;
}
export declare class HistoryEntry {
    readonly appStateChange: AppStateChange;
    readonly elementsChange: ElementsChange;
    private constructor();
    static create(appStateChange: AppStateChange, elementsChange: ElementsChange): HistoryEntry;
    inverse(): HistoryEntry;
    applyTo(elements: SceneElementsMap, appState: AppState, snapshot: Readonly<Snapshot>): [SceneElementsMap, AppState, boolean];
    /**
     * Apply latest (remote) changes to the history entry, creates new instance of `HistoryEntry`.
     */
    applyLatestChanges(elements: SceneElementsMap): HistoryEntry;
    isEmpty(): boolean;
}
