import type { ElementUpdate } from "./element/mutateElement";
import type { ExcalidrawElement, Ordered, OrderedExcalidrawElement, SceneElementsMap } from "./element/types";
import type { AppState, ObservedAppState } from "./types";
import type { ValueOf } from "./utility-types";
/**
 * Represents the difference between two objects of the same type.
 *
 * Both `deleted` and `inserted` partials represent the same set of added, removed or updated properties, where:
 * - `deleted` is a set of all the deleted values
 * - `inserted` is a set of all the inserted (added, updated) values
 *
 * Keeping it as pure object (without transient state, side-effects, etc.), so we won't have to instantiate it on load.
 */
declare class Delta<T> {
    readonly deleted: Partial<T>;
    readonly inserted: Partial<T>;
    private constructor();
    static create<T>(deleted: Partial<T>, inserted: Partial<T>, modifier?: (delta: Partial<T>) => Partial<T>, modifierOptions?: "deleted" | "inserted"): Delta<T>;
    /**
     * Calculates the delta between two objects.
     *
     * @param prevObject - The previous state of the object.
     * @param nextObject - The next state of the object.
     *
     * @returns new delta instance.
     */
    static calculate<T extends {
        [key: string]: any;
    }>(prevObject: T, nextObject: T, modifier?: (partial: Partial<T>) => Partial<T>, postProcess?: (deleted: Partial<T>, inserted: Partial<T>) => [Partial<T>, Partial<T>]): Delta<T>;
    static empty(): Delta<unknown>;
    static isEmpty<T>(delta: Delta<T>): boolean;
    /**
     * Merges deleted and inserted object partials.
     */
    static mergeObjects<T extends {
        [key: string]: unknown;
    }>(prev: T, added: T, removed: T): T;
    /**
     * Merges deleted and inserted array partials.
     */
    static mergeArrays<T>(prev: readonly T[] | null, added: readonly T[] | null | undefined, removed: readonly T[] | null | undefined, predicate?: (value: T) => string): T[];
    /**
     * Diff object partials as part of the `postProcess`.
     */
    static diffObjects<T, K extends keyof T, V extends ValueOf<T[K]>>(deleted: Partial<T>, inserted: Partial<T>, property: K, setValue: (prevValue: V | undefined) => V): void;
    /**
     * Diff array partials as part of the `postProcess`.
     */
    static diffArrays<T, K extends keyof T, V extends T[K]>(deleted: Partial<T>, inserted: Partial<T>, property: K, groupBy: (value: V extends ArrayLike<infer T> ? T : never) => string): void;
    /**
     * Compares if object1 contains any different value compared to the object2.
     */
    static isLeftDifferent<T extends {}>(object1: T, object2: T, skipShallowCompare?: boolean): boolean;
    /**
     * Compares if object2 contains any different value compared to the object1.
     */
    static isRightDifferent<T extends {}>(object1: T, object2: T, skipShallowCompare?: boolean): boolean;
    /**
     * Returns all the object1 keys that have distinct values.
     */
    static getLeftDifferences<T extends {}>(object1: T, object2: T, skipShallowCompare?: boolean): string[];
    /**
     * Returns all the object2 keys that have distinct values.
     */
    static getRightDifferences<T extends {}>(object1: T, object2: T, skipShallowCompare?: boolean): string[];
    /**
     * Iterator comparing values of object properties based on the passed joining strategy.
     *
     * @yields keys of properties with different values
     *
     * WARN: it's based on shallow compare performed only on the first level and doesn't go deeper than that.
     */
    private static distinctKeysIterator;
}
/**
 * Encapsulates the modifications captured as `Delta`/s.
 */
interface Change<T> {
    /**
     * Inverses the `Delta`s inside while creating a new `Change`.
     */
    inverse(): Change<T>;
    /**
     * Applies the `Change` to the previous object.
     *
     * @returns a tuple of the next object `T` with applied change, and `boolean`, indicating whether the applied change resulted in a visible change.
     */
    applyTo(previous: T, ...options: unknown[]): [T, boolean];
    /**
     * Checks whether there are actually `Delta`s.
     */
    isEmpty(): boolean;
}
export declare class AppStateChange implements Change<AppState> {
    private readonly delta;
    private constructor();
    static calculate<T extends ObservedAppState>(prevAppState: T, nextAppState: T): AppStateChange;
    static empty(): AppStateChange;
    inverse(): AppStateChange;
    applyTo(appState: AppState, nextElements: SceneElementsMap): [AppState, boolean];
    isEmpty(): boolean;
    /**
     * It is necessary to post process the partials in case of reference values,
     * for which we need to calculate the real diff between `deleted` and `inserted`.
     */
    private static postProcess;
    /**
     * Mutates `nextAppState` be filtering out state related to deleted elements.
     *
     * @returns `true` if a visible change is found, `false` otherwise.
     */
    private filterInvisibleChanges;
    private static convertToAppStateKey;
    private static filterSelectedElements;
    private static filterSelectedGroups;
    private static stripElementsProps;
    private static stripStandaloneProps;
}
type ElementPartial<T extends ExcalidrawElement = ExcalidrawElement> = Omit<ElementUpdate<Ordered<T>>, "seed">;
/**
 * Elements change is a low level primitive to capture a change between two sets of elements.
 * It does so by encapsulating forward and backward `Delta`s, allowing to time-travel in both directions.
 */
export declare class ElementsChange implements Change<SceneElementsMap> {
    private readonly added;
    private readonly removed;
    private readonly updated;
    private constructor();
    static create(added: Map<string, Delta<ElementPartial>>, removed: Map<string, Delta<ElementPartial>>, updated: Map<string, Delta<ElementPartial>>, options?: {
        shouldRedistribute: boolean;
    }): ElementsChange;
    private static satisfiesAddition;
    private static satisfiesRemoval;
    private static satisfiesUpdate;
    private static validate;
    /**
     * Calculates the `Delta`s between the previous and next set of elements.
     *
     * @param prevElements - Map representing the previous state of elements.
     * @param nextElements - Map representing the next state of elements.
     *
     * @returns `ElementsChange` instance representing the `Delta` changes between the two sets of elements.
     */
    static calculate<T extends OrderedExcalidrawElement>(prevElements: Map<string, T>, nextElements: Map<string, T>): ElementsChange;
    static empty(): ElementsChange;
    inverse(): ElementsChange;
    isEmpty(): boolean;
    /**
     * Update delta/s based on the existing elements.
     *
     * @param elements current elements
     * @param modifierOptions defines which of the delta (`deleted` or `inserted`) will be updated
     * @returns new instance with modified delta/s
     */
    applyLatestChanges(elements: SceneElementsMap): ElementsChange;
    applyTo(elements: SceneElementsMap, snapshot: Map<string, OrderedExcalidrawElement>): [SceneElementsMap, boolean];
    private static createApplier;
    private static createGetter;
    private static applyDelta;
    /**
     * Check for visible changes regardless of whether they were removed, added or updated.
     */
    private static checkForVisibleDifference;
    /**
     * Resolves conflicts for all previously added, removed and updated elements.
     * Updates the previous deltas with all the changes after conflict resolution.
     *
     * @returns all elements affected by the conflict resolution
     */
    private resolveConflicts;
    /**
     * Non deleted affected elements of removed elements (before and after applying delta),
     * should be unbound ~ bindings should not point from non deleted into the deleted element/s.
     */
    private static unbindAffected;
    /**
     * Non deleted affected elements of added or updated element/s (before and after applying delta),
     * should be rebound (if possible) with the current element ~ bindings should be bidirectional.
     */
    private static rebindAffected;
    private static redrawTextBoundingBoxes;
    private static redrawBoundArrows;
    private static reorderElements;
    /**
     * It is necessary to post process the partials in case of reference values,
     * for which we need to calculate the real diff between `deleted` and `inserted`.
     */
    private static postProcess;
    private static stripIrrelevantProps;
}
export {};
