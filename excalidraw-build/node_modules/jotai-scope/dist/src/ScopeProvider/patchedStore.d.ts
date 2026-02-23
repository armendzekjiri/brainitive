import type { Store, Scope } from './types';
/**
 * @returns a patched store that intercepts get and set calls to apply the scope
 */
export declare function createPatchedStore(baseStore: Store, scope: Scope): Store;
/**
 * @returns true if the current scope is the first descendant scope under Provider
 */
export declare function isTopLevelScope(parentStore: Store): boolean;
