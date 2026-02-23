import type { Emitter } from "../emitter";
export declare const useEmitter: <TEvent extends unknown>(emitter: Emitter<[TEvent]>, initialState: TEvent) => TEvent;
