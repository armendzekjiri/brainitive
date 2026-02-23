import { type PropsWithChildren } from 'react';
import type { AnyAtom, AnyAtomFamily } from './types';
export declare function ScopeProvider({ atoms, atomFamilies, children, debugName, }: PropsWithChildren<{
    atoms: Iterable<AnyAtom>;
    atomFamilies?: Iterable<AnyAtomFamily>;
    debugName?: string;
}>): JSX.Element;
export declare function ScopeProvider({ atoms, atomFamilies, children, debugName, }: PropsWithChildren<{
    atoms?: Iterable<AnyAtom>;
    atomFamilies: Iterable<AnyAtomFamily>;
    debugName?: string;
}>): JSX.Element;
