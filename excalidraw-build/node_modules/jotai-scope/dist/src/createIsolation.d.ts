import type { ReactNode } from 'react';
import { createStore } from 'jotai/vanilla';
import type { WritableAtom } from 'jotai/vanilla';
import { useAtom as useAtomOrig, useAtomValue as useAtomValueOrig, useSetAtom as useSetAtomOrig } from 'jotai/react';
type Store = ReturnType<typeof createStore>;
type AnyWritableAtom = WritableAtom<unknown, any[], any>;
export declare function createIsolation(): {
    Provider: ({ store, initialValues, children, }: {
        store?: Store;
        initialValues?: Iterable<readonly [AnyWritableAtom, unknown]>;
        children: ReactNode;
    }) => import("react/jsx-runtime").JSX.Element;
    useStore: (options?: {
        store?: {
            get: <Value>(atom: import("jotai/vanilla").Atom<Value>) => Value;
            set: <Value_1, Args extends unknown[], Result>(atom: WritableAtom<Value_1, Args, Result>, ...args: Args) => Result;
            sub: (atom: import("jotai/vanilla").Atom<unknown>, listener: () => void) => () => void;
        } | ({
            get: <Value>(atom: import("jotai/vanilla").Atom<Value>) => Value;
            set: <Value_1, Args extends unknown[], Result>(atom: WritableAtom<Value_1, Args, Result>, ...args: Args) => Result;
            sub: (atom: import("jotai/vanilla").Atom<unknown>, listener: () => void) => () => void;
        } & {
            dev4_get_internal_weak_map: () => WeakMap<import("jotai/vanilla").Atom<unknown>, {
                readonly d: Map<import("jotai/vanilla").Atom<unknown>, number>;
                readonly p: Set<import("jotai/vanilla").Atom<unknown>>;
                n: number;
                m?: {
                    readonly l: Set<() => void>;
                    readonly d: Set<import("jotai/vanilla").Atom<unknown>>;
                    readonly t: Set<import("jotai/vanilla").Atom<unknown>>;
                    u?: () => void;
                };
                v?: unknown;
                e?: unknown;
            }>;
            dev4_get_mounted_atoms: () => Set<import("jotai/vanilla").Atom<unknown>>;
            dev4_restore_atoms: (values: Iterable<readonly [import("jotai/vanilla").Atom<unknown>, unknown]>) => void;
        });
    } | undefined) => {
        get: <Value>(atom: import("jotai/vanilla").Atom<Value>) => Value;
        set: <Value_1, Args extends unknown[], Result>(atom: WritableAtom<Value_1, Args, Result>, ...args: Args) => Result;
        sub: (atom: import("jotai/vanilla").Atom<unknown>, listener: () => void) => () => void;
    } | ({
        get: <Value>(atom: import("jotai/vanilla").Atom<Value>) => Value;
        set: <Value_1, Args extends unknown[], Result>(atom: WritableAtom<Value_1, Args, Result>, ...args: Args) => Result;
        sub: (atom: import("jotai/vanilla").Atom<unknown>, listener: () => void) => () => void;
    } & {
        dev4_get_internal_weak_map: () => WeakMap<import("jotai/vanilla").Atom<unknown>, {
            readonly d: Map<import("jotai/vanilla").Atom<unknown>, number>;
            readonly p: Set<import("jotai/vanilla").Atom<unknown>>;
            n: number;
            m?: {
                readonly l: Set<() => void>;
                readonly d: Set<import("jotai/vanilla").Atom<unknown>>;
                readonly t: Set<import("jotai/vanilla").Atom<unknown>>;
                u?: () => void;
            };
            v?: unknown;
            e?: unknown;
        }>;
        dev4_get_mounted_atoms: () => Set<import("jotai/vanilla").Atom<unknown>>;
        dev4_restore_atoms: (values: Iterable<readonly [import("jotai/vanilla").Atom<unknown>, unknown]>) => void;
    });
    useAtom: typeof useAtomOrig;
    useAtomValue: typeof useAtomValueOrig;
    useSetAtom: typeof useSetAtomOrig;
};
export {};
