import { jsx } from 'react/jsx-runtime';
import { createContext, useRef, useContext, useState, useEffect } from 'react';
import { createStore } from 'jotai/vanilla';
import { useStore, useAtom, useAtomValue, useSetAtom, Provider } from 'jotai/react';
import { useHydrateAtoms } from 'jotai/react/utils';
import { atom } from 'jotai';

function _extends() {
  _extends = Object.assign ? Object.assign.bind() : function (target) {
    for (var i = 1; i < arguments.length; i++) {
      var source = arguments[i];
      for (var key in source) {
        if (Object.prototype.hasOwnProperty.call(source, key)) {
          target[key] = source[key];
        }
      }
    }
    return target;
  };
  return _extends.apply(this, arguments);
}

function createIsolation() {
  const StoreContext = createContext(null);
  function Provider({
    store,
    initialValues = [],
    children
  }) {
    const storeRef = useRef(store);
    if (!storeRef.current) {
      storeRef.current = createStore();
    }
    useHydrateAtoms(initialValues, {
      store: storeRef.current
    });
    return jsx(StoreContext.Provider, {
      value: storeRef.current,
      children: children
    });
  }
  const useStore$1 = options => {
    const store = useContext(StoreContext);
    if (!store) throw new Error('Missing Provider from createIsolation');
    return useStore(_extends({
      store
    }, options));
  };
  const useAtom$1 = (anAtom, options) => {
    const store = useStore$1();
    return useAtom(anAtom, _extends({
      store
    }, options));
  };
  const useAtomValue$1 = (anAtom, options) => {
    const store = useStore$1();
    return useAtomValue(anAtom, _extends({
      store
    }, options));
  };
  const useSetAtom$1 = (anAtom, options) => {
    const store = useStore$1();
    return useSetAtom(anAtom, _extends({
      store
    }, options));
  };
  return {
    Provider,
    useStore: useStore$1,
    useAtom: useAtom$1,
    useAtomValue: useAtomValue$1,
    useSetAtom: useSetAtom$1
  };
}

const globalScopeKey = {};
if (process.env.NODE_ENV !== 'production') {
  globalScopeKey.name = 'unscoped';
  globalScopeKey.toString = toString;
}
function createScope(atoms, atomFamilies, parentScope, scopeName) {
  const explicit = new WeakMap();
  const implicit = new WeakMap();
  const inherited = new WeakMap();
  const currentScope = {
    getAtom,
    cleanup() {},
    prepareWriteAtom(anAtom, originalAtom, implicitScope) {
      if (originalAtom.read === defaultRead && isWritableAtom(originalAtom) && isWritableAtom(anAtom) && originalAtom.write !== defaultWrite && currentScope !== implicitScope) {
        // atom is writable with init and holds a value
        // we need to preserve the value, so we don't want to copy the atom
        // instead, we need to override write until the write is finished
        const {
          write
        } = originalAtom;
        anAtom.write = createScopedWrite(originalAtom.write.bind(originalAtom), implicitScope);
        return () => {
          anAtom.write = write;
        };
      }
      return undefined;
    }
  };
  if (scopeName && process.env.NODE_ENV !== 'production') {
    currentScope.name = scopeName;
    currentScope.toString = toString;
  }
  // populate explicitly scoped atoms
  for (const anAtom of atoms) {
    explicit.set(anAtom, [cloneAtom(anAtom, currentScope), currentScope]);
  }
  const cleanupFamiliesSet = new Set();
  for (const atomFamily of atomFamilies) {
    for (const param of atomFamily.getParams()) {
      const anAtom = atomFamily(param);
      if (!explicit.has(anAtom)) {
        explicit.set(anAtom, [cloneAtom(anAtom, currentScope), currentScope]);
      }
    }
    const cleanupFamily = atomFamily.unstable_listen(e => {
      if (e.type === 'CREATE' && !explicit.has(e.atom)) {
        explicit.set(e.atom, [cloneAtom(e.atom, currentScope), currentScope]);
      } else if (!atoms.has(e.atom)) {
        explicit.delete(e.atom);
      }
    });
    cleanupFamiliesSet.add(cleanupFamily);
  }
  currentScope.cleanup = combineVoidFunctions(currentScope.cleanup, ...Array.from(cleanupFamiliesSet));
  /**
   * Returns a scoped atom from the original atom.
   * @param anAtom
   * @param implicitScope the atom is implicitly scoped in the provided scope
   * @returns the scoped atom and the scope of the atom
   */
  function getAtom(anAtom, implicitScope) {
    var _inherited$get2;
    if (explicit.has(anAtom)) {
      return explicit.get(anAtom);
    }
    if (implicitScope === currentScope) {
      // dependencies of explicitly scoped atoms are implicitly scoped
      // implicitly scoped atoms are only accessed by implicit and explicit scoped atoms
      if (!implicit.has(anAtom)) {
        implicit.set(anAtom, [cloneAtom(anAtom, implicitScope), implicitScope]);
      }
      return implicit.get(anAtom);
    }
    const scopeKey = implicitScope != null ? implicitScope : globalScopeKey;
    if (parentScope) {
      var _inherited$get;
      // inherited atoms are copied so they can access scoped atoms
      // but they are not explicitly scoped
      // dependencies of inherited atoms first check if they are explicitly scoped
      // otherwise they use their original scope's atom
      if (!((_inherited$get = inherited.get(scopeKey)) != null && _inherited$get.has(anAtom))) {
        const [ancestorAtom, explicitScope] = parentScope.getAtom(anAtom, implicitScope);
        setInheritedAtom(inheritAtom(ancestorAtom, anAtom, explicitScope), anAtom, implicitScope, explicitScope);
      }
      return inherited.get(scopeKey).get(anAtom);
    }
    if (!((_inherited$get2 = inherited.get(scopeKey)) != null && _inherited$get2.has(anAtom))) {
      // non-primitive atoms may need to access scoped atoms
      // so we need to create a copy of the atom
      setInheritedAtom(inheritAtom(anAtom, anAtom), anAtom);
    }
    return inherited.get(scopeKey).get(anAtom);
  }
  function setInheritedAtom(scopedAtom, originalAtom, implicitScope, explicitScope) {
    const scopeKey = implicitScope != null ? implicitScope : globalScopeKey;
    if (!inherited.has(scopeKey)) {
      inherited.set(scopeKey, new WeakMap());
    }
    inherited.get(scopeKey).set(originalAtom, [scopedAtom, explicitScope].filter(Boolean));
  }
  /**
   * @returns a copy of the atom for derived atoms or the original atom for primitive and writable atoms
   */
  function inheritAtom(anAtom, originalAtom, implicitScope) {
    if (originalAtom.read !== defaultRead) {
      return cloneAtom(originalAtom, implicitScope);
    }
    return anAtom;
  }
  /**
   * @returns a scoped copy of the atom
   */
  function cloneAtom(originalAtom, implicitScope) {
    // avoid reading `init` to preserve lazy initialization
    const scopedAtom = Object.create(Object.getPrototypeOf(originalAtom), Object.getOwnPropertyDescriptors(originalAtom));
    if (scopedAtom.read !== defaultRead) {
      scopedAtom.read = createScopedRead(originalAtom.read.bind(originalAtom), implicitScope);
    }
    if (isWritableAtom(scopedAtom) && isWritableAtom(originalAtom) && scopedAtom.write !== defaultWrite) {
      scopedAtom.write = createScopedWrite(originalAtom.write.bind(originalAtom), implicitScope);
    }
    return scopedAtom;
  }
  function createScopedRead(read, implicitScope) {
    return function scopedRead(get, opts) {
      return read(function scopedGet(a) {
        const [scopedAtom] = getAtom(a, implicitScope);
        return get(scopedAtom);
      },
      //
      opts);
    };
  }
  function createScopedWrite(write, implicitScope) {
    return function scopedWrite(get, set, ...args) {
      return write(function scopedGet(a) {
        const [scopedAtom] = getAtom(a, implicitScope);
        return get(scopedAtom);
      }, function scopedSet(a, ...v) {
        const [scopedAtom] = getAtom(a, implicitScope);
        return set(scopedAtom, ...v);
      }, ...args);
    };
  }
  return currentScope;
}
function isWritableAtom(anAtom) {
  return 'write' in anAtom;
}
const {
  read: defaultRead,
  write: defaultWrite
} = atom(null);
function toString() {
  return this.name;
}
function combineVoidFunctions(...fns) {
  return function combinedFunctions() {
    for (const fn of fns) {
      fn();
    }
  };
}

function PatchedStore() {}
/**
 * @returns a patched store that intercepts get and set calls to apply the scope
 */
function createPatchedStore(baseStore, scope) {
  const store = _extends({}, baseStore, {
    get(anAtom, ...args) {
      const [scopedAtom] = scope.getAtom(anAtom);
      return baseStore.get(scopedAtom, ...args);
    },
    set(anAtom, ...args) {
      const [scopedAtom, implicitScope] = scope.getAtom(anAtom);
      const restore = scope.prepareWriteAtom(scopedAtom, anAtom, implicitScope);
      try {
        return baseStore.set(scopedAtom, ...args);
      } finally {
        restore == null || restore();
      }
    },
    sub(anAtom, ...args) {
      const [scopedAtom] = scope.getAtom(anAtom);
      return baseStore.sub(scopedAtom, ...args);
    }
    // TODO: update this patch to support devtools
  });

  return Object.assign(Object.create(PatchedStore.prototype), store);
}
/**
 * @returns true if the current scope is the first descendant scope under Provider
 */
function isTopLevelScope(parentStore) {
  return !(parentStore instanceof PatchedStore);
}

const ScopeContext = createContext({
  scope: undefined,
  baseStore: undefined
});
function ScopeProvider({
  atoms,
  atomFamilies,
  children,
  debugName
}) {
  const parentStore = useStore();
  let {
    scope: parentScope,
    baseStore = parentStore
  } = useContext(ScopeContext);
  // if this ScopeProvider is the first descendant scope under Provider then it is the top level scope
  // https://github.com/jotaijs/jotai-scope/pull/33#discussion_r1604268003
  if (isTopLevelScope(parentStore)) {
    parentScope = undefined;
    baseStore = parentStore;
  }
  // atomSet is used to detect if the atoms prop has changed.
  const atomSet = new Set(atoms);
  const atomFamilySet = new Set(atomFamilies);
  function initialize() {
    const scope = createScope(atomSet, atomFamilySet, parentScope, debugName);
    return {
      patchedStore: createPatchedStore(baseStore, scope),
      scopeContext: {
        scope,
        baseStore
      },
      hasChanged(current) {
        return parentScope !== current.parentScope || baseStore !== current.baseStore || !isEqualSet(atomSet, current.atomSet) || !isEqualSet(atomFamilySet, current.atomFamilySet);
      }
    };
  }
  const [state, setState] = useState(initialize);
  const {
    hasChanged,
    scopeContext,
    patchedStore
  } = state;
  if (hasChanged({
    parentScope,
    atomSet,
    atomFamilySet,
    baseStore
  })) {
    var _scopeContext$scope;
    (_scopeContext$scope = scopeContext.scope) == null || _scopeContext$scope.cleanup();
    setState(initialize);
  }
  const {
    cleanup
  } = scopeContext.scope;
  useEvent(() => cleanup, []);
  return jsx(ScopeContext.Provider, {
    value: scopeContext,
    children: jsx(Provider, {
      store: patchedStore,
      children: children
    })
  });
}
function isEqualSet(a, b) {
  return a === b || a.size === b.size && Array.from(a).every(v => b.has(v));
}
function useEvent(fn, deps) {
  const ref = useRef(fn);
  ref.current = fn;
  useEffect(() => ref.current(), deps);
}

export { ScopeProvider, createIsolation };
//# sourceMappingURL=index.modern.mjs.map
