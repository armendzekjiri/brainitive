import type { AnyAtomFamily, AnyAtom, Scope } from './types';
export declare function createScope(atoms: Set<AnyAtom>, atomFamilies: Set<AnyAtomFamily>, parentScope: Scope | undefined, scopeName?: string | undefined): Scope;
