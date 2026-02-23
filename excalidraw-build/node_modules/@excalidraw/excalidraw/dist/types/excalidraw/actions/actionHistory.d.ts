import type { Action } from "./types";
import type { History } from "../history";
import type { Store } from "../store";
type ActionCreator = (history: History, store: Store) => Action;
export declare const createUndoAction: ActionCreator;
export declare const createRedoAction: ActionCreator;
export {};
