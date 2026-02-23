import { atom, createStore, type PrimitiveAtom } from "jotai";
import { createIsolation } from "jotai-scope";
export { atom, PrimitiveAtom };
export declare const useAtom: typeof import("jotai").useAtom, useSetAtom: typeof import("jotai").useSetAtom, useAtomValue: typeof import("jotai").useAtomValue, useStore: (options?: {
    store?: {
        get: <Value>(atom: import("jotai").Atom<Value>) => Value;
        set: <Value_1, Args extends unknown[], Result>(atom: import("jotai").WritableAtom<Value_1, Args, Result>, ...args: Args) => Result;
        sub: (atom: import("jotai").Atom<unknown>, listener: () => void) => () => void;
    } | ({
        get: <Value_2>(atom: import("jotai").Atom<Value_2>) => Value_2;
        set: <Value_1_1, Args_1 extends unknown[], Result_1>(atom: import("jotai").WritableAtom<Value_1_1, Args_1, Result_1>, ...args: Args_1) => Result_1;
        sub: (atom: import("jotai").Atom<unknown>, listener: () => void) => () => void;
    } & {
        dev4_get_internal_weak_map: () => WeakMap<import("jotai").Atom<unknown>, {
            readonly d: Map<import("jotai").Atom<unknown>, number>;
            readonly p: Set<import("jotai").Atom<unknown>>;
            n: number;
            m?: {
                readonly l: Set<() => void>;
                readonly d: Set<import("jotai").Atom<unknown>>;
                readonly t: Set<import("jotai").Atom<unknown>>;
                u?: (() => void) | undefined;
            } | undefined;
            v?: unknown;
            e?: unknown;
        }>;
        dev4_get_mounted_atoms: () => Set<import("jotai").Atom<unknown>>;
        dev4_restore_atoms: (values: Iterable<readonly [import("jotai").Atom<unknown>, unknown]>) => void;
    }) | undefined;
} | undefined) => {
    get: <Value_3>(atom: import("jotai").Atom<Value_3>) => Value_3;
    set: <Value_1_2, Args_2 extends unknown[], Result_2>(atom: import("jotai").WritableAtom<Value_1_2, Args_2, Result_2>, ...args: Args_2) => Result_2;
    sub: (atom: import("jotai").Atom<unknown>, listener: () => void) => () => void;
} | ({
    get: <Value_4>(atom: import("jotai").Atom<Value_4>) => Value_4;
    set: <Value_1_3, Args_3 extends unknown[], Result_3>(atom: import("jotai").WritableAtom<Value_1_3, Args_3, Result_3>, ...args: Args_3) => Result_3;
    sub: (atom: import("jotai").Atom<unknown>, listener: () => void) => () => void;
} & {
    dev4_get_internal_weak_map: () => WeakMap<import("jotai").Atom<unknown>, {
        readonly d: Map<import("jotai").Atom<unknown>, number>;
        readonly p: Set<import("jotai").Atom<unknown>>;
        n: number;
        m?: {
            readonly l: Set<() => void>;
            readonly d: Set<import("jotai").Atom<unknown>>;
            readonly t: Set<import("jotai").Atom<unknown>>;
            u?: (() => void) | undefined;
        } | undefined;
        v?: unknown;
        e?: unknown;
    }>;
    dev4_get_mounted_atoms: () => Set<import("jotai").Atom<unknown>>;
    dev4_restore_atoms: (values: Iterable<readonly [import("jotai").Atom<unknown>, unknown]>) => void;
});
export declare const EditorJotaiProvider: ReturnType<typeof createIsolation>["Provider"];
export declare const editorJotaiStore: ReturnType<typeof createStore>;
