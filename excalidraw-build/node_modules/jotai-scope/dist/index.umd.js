(function (global, factory) {
  typeof exports === 'object' && typeof module !== 'undefined' ? factory(exports, require('react/jsx-runtime'), require('react'), require('jotai/vanilla'), require('jotai/react'), require('jotai/react/utils'), require('jotai')) :
  typeof define === 'function' && define.amd ? define(['exports', 'react/jsx-runtime', 'react', 'jotai/vanilla', 'jotai/react', 'jotai/react/utils', 'jotai'], factory) :
  (global = global || self, factory(global.jotaiScope = {}, global.jsxRuntime, global.React, global.vanilla, global.react$1, global.utils, global.jotai));
})(this, (function (exports, jsxRuntime, react, vanilla, react$1, utils, jotai) {
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
  function _unsupportedIterableToArray(o, minLen) {
    if (!o) return;
    if (typeof o === "string") return _arrayLikeToArray(o, minLen);
    var n = Object.prototype.toString.call(o).slice(8, -1);
    if (n === "Object" && o.constructor) n = o.constructor.name;
    if (n === "Map" || n === "Set") return Array.from(o);
    if (n === "Arguments" || /^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(n)) return _arrayLikeToArray(o, minLen);
  }
  function _arrayLikeToArray(arr, len) {
    if (len == null || len > arr.length) len = arr.length;
    for (var i = 0, arr2 = new Array(len); i < len; i++) arr2[i] = arr[i];
    return arr2;
  }
  function _createForOfIteratorHelperLoose(o, allowArrayLike) {
    var it = typeof Symbol !== "undefined" && o[Symbol.iterator] || o["@@iterator"];
    if (it) return (it = it.call(o)).next.bind(it);
    if (Array.isArray(o) || (it = _unsupportedIterableToArray(o)) || allowArrayLike && o && typeof o.length === "number") {
      if (it) o = it;
      var i = 0;
      return function () {
        if (i >= o.length) return {
          done: true
        };
        return {
          done: false,
          value: o[i++]
        };
      };
    }
    throw new TypeError("Invalid attempt to iterate non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method.");
  }

  function createIsolation() {
    var StoreContext = react.createContext(null);
    function Provider(_ref) {
      var store = _ref.store,
        _ref$initialValues = _ref.initialValues,
        initialValues = _ref$initialValues === void 0 ? [] : _ref$initialValues,
        children = _ref.children;
      var storeRef = react.useRef(store);
      if (!storeRef.current) {
        storeRef.current = vanilla.createStore();
      }
      utils.useHydrateAtoms(initialValues, {
        store: storeRef.current
      });
      return jsxRuntime.jsx(StoreContext.Provider, {
        value: storeRef.current,
        children: children
      });
    }
    var useStore = function useStore(options) {
      var store = react.useContext(StoreContext);
      if (!store) throw new Error('Missing Provider from createIsolation');
      return react$1.useStore(_extends({
        store: store
      }, options));
    };
    var useAtom = function useAtom(anAtom, options) {
      var store = useStore();
      return react$1.useAtom(anAtom, _extends({
        store: store
      }, options));
    };
    var useAtomValue = function useAtomValue(anAtom, options) {
      var store = useStore();
      return react$1.useAtomValue(anAtom, _extends({
        store: store
      }, options));
    };
    var useSetAtom = function useSetAtom(anAtom, options) {
      var store = useStore();
      return react$1.useSetAtom(anAtom, _extends({
        store: store
      }, options));
    };
    return {
      Provider: Provider,
      useStore: useStore,
      useAtom: useAtom,
      useAtomValue: useAtomValue,
      useSetAtom: useSetAtom
    };
  }

  var globalScopeKey = {};
  if (process.env.NODE_ENV !== 'production') {
    globalScopeKey.name = 'unscoped';
    globalScopeKey.toString = toString;
  }
  function createScope(atoms, atomFamilies, parentScope, scopeName) {
    var explicit = new WeakMap();
    var implicit = new WeakMap();
    var inherited = new WeakMap();
    var currentScope = {
      getAtom: getAtom,
      cleanup: function cleanup() {},
      prepareWriteAtom: function prepareWriteAtom(anAtom, originalAtom, implicitScope) {
        if (originalAtom.read === defaultRead && isWritableAtom(originalAtom) && isWritableAtom(anAtom) && originalAtom.write !== defaultWrite && currentScope !== implicitScope) {
          // atom is writable with init and holds a value
          // we need to preserve the value, so we don't want to copy the atom
          // instead, we need to override write until the write is finished
          var write = originalAtom.write;
          anAtom.write = createScopedWrite(originalAtom.write.bind(originalAtom), implicitScope);
          return function () {
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
    for (var _iterator = _createForOfIteratorHelperLoose(atoms), _step; !(_step = _iterator()).done;) {
      var anAtom = _step.value;
      explicit.set(anAtom, [cloneAtom(anAtom, currentScope), currentScope]);
    }
    var cleanupFamiliesSet = new Set();
    for (var _iterator2 = _createForOfIteratorHelperLoose(atomFamilies), _step2; !(_step2 = _iterator2()).done;) {
      var atomFamily = _step2.value;
      for (var _iterator3 = _createForOfIteratorHelperLoose(atomFamily.getParams()), _step3; !(_step3 = _iterator3()).done;) {
        var param = _step3.value;
        var _anAtom = atomFamily(param);
        if (!explicit.has(_anAtom)) {
          explicit.set(_anAtom, [cloneAtom(_anAtom, currentScope), currentScope]);
        }
      }
      var cleanupFamily = atomFamily.unstable_listen(function (e) {
        if (e.type === 'CREATE' && !explicit.has(e.atom)) {
          explicit.set(e.atom, [cloneAtom(e.atom, currentScope), currentScope]);
        } else if (!atoms.has(e.atom)) {
          explicit["delete"](e.atom);
        }
      });
      cleanupFamiliesSet.add(cleanupFamily);
    }
    currentScope.cleanup = combineVoidFunctions.apply(void 0, [currentScope.cleanup].concat(Array.from(cleanupFamiliesSet)));
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
      var scopeKey = implicitScope != null ? implicitScope : globalScopeKey;
      if (parentScope) {
        var _inherited$get;
        // inherited atoms are copied so they can access scoped atoms
        // but they are not explicitly scoped
        // dependencies of inherited atoms first check if they are explicitly scoped
        // otherwise they use their original scope's atom
        if (!((_inherited$get = inherited.get(scopeKey)) != null && _inherited$get.has(anAtom))) {
          var _parentScope$getAtom = parentScope.getAtom(anAtom, implicitScope),
            ancestorAtom = _parentScope$getAtom[0],
            explicitScope = _parentScope$getAtom[1];
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
      var scopeKey = implicitScope != null ? implicitScope : globalScopeKey;
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
      var scopedAtom = Object.create(Object.getPrototypeOf(originalAtom), Object.getOwnPropertyDescriptors(originalAtom));
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
          var _getAtom = getAtom(a, implicitScope),
            scopedAtom = _getAtom[0];
          return get(scopedAtom);
        },
        //
        opts);
      };
    }
    function createScopedWrite(write, implicitScope) {
      return function scopedWrite(get, set) {
        return write.apply(void 0, [function scopedGet(a) {
          var _getAtom2 = getAtom(a, implicitScope),
            scopedAtom = _getAtom2[0];
          return get(scopedAtom);
        }, function scopedSet(a) {
          var _getAtom3 = getAtom(a, implicitScope),
            scopedAtom = _getAtom3[0];
          return set.apply(void 0, [scopedAtom].concat([].slice.call(arguments, 1)));
        }].concat([].slice.call(arguments, 2)));
      };
    }
    return currentScope;
  }
  function isWritableAtom(anAtom) {
    return 'write' in anAtom;
  }
  var _atom = jotai.atom(null),
    defaultRead = _atom.read,
    defaultWrite = _atom.write;
  function toString() {
    return this.name;
  }
  function combineVoidFunctions() {
    var fns = [].slice.call(arguments);
    return function combinedFunctions() {
      for (var _iterator4 = _createForOfIteratorHelperLoose(fns), _step4; !(_step4 = _iterator4()).done;) {
        var fn = _step4.value;
        fn();
      }
    };
  }

  function PatchedStore() {}
  /**
   * @returns a patched store that intercepts get and set calls to apply the scope
   */
  function createPatchedStore(baseStore, scope) {
    var store = _extends({}, baseStore, {
      get: function get(anAtom) {
        var _scope$getAtom = scope.getAtom(anAtom),
          scopedAtom = _scope$getAtom[0];
        return baseStore.get.apply(baseStore, [scopedAtom].concat([].slice.call(arguments, 1)));
      },
      set: function set(anAtom) {
        var _scope$getAtom2 = scope.getAtom(anAtom),
          scopedAtom = _scope$getAtom2[0],
          implicitScope = _scope$getAtom2[1];
        var restore = scope.prepareWriteAtom(scopedAtom, anAtom, implicitScope);
        try {
          return baseStore.set.apply(baseStore, [scopedAtom].concat([].slice.call(arguments, 1)));
        } finally {
          restore == null || restore();
        }
      },
      sub: function sub(anAtom) {
        var _scope$getAtom3 = scope.getAtom(anAtom),
          scopedAtom = _scope$getAtom3[0];
        return baseStore.sub.apply(baseStore, [scopedAtom].concat([].slice.call(arguments, 1)));
      } // TODO: update this patch to support devtools
    });
    return Object.assign(Object.create(PatchedStore.prototype), store);
  }
  /**
   * @returns true if the current scope is the first descendant scope under Provider
   */
  function isTopLevelScope(parentStore) {
    return !(parentStore instanceof PatchedStore);
  }

  var ScopeContext = react.createContext({
    scope: undefined,
    baseStore: undefined
  });
  function ScopeProvider(_ref) {
    var atoms = _ref.atoms,
      atomFamilies = _ref.atomFamilies,
      children = _ref.children,
      debugName = _ref.debugName;
    var parentStore = react$1.useStore();
    var _useContext = react.useContext(ScopeContext),
      parentScope = _useContext.scope,
      _useContext$baseStore = _useContext.baseStore,
      baseStore = _useContext$baseStore === void 0 ? parentStore : _useContext$baseStore;
    // if this ScopeProvider is the first descendant scope under Provider then it is the top level scope
    // https://github.com/jotaijs/jotai-scope/pull/33#discussion_r1604268003
    if (isTopLevelScope(parentStore)) {
      parentScope = undefined;
      baseStore = parentStore;
    }
    // atomSet is used to detect if the atoms prop has changed.
    var atomSet = new Set(atoms);
    var atomFamilySet = new Set(atomFamilies);
    function initialize() {
      var scope = createScope(atomSet, atomFamilySet, parentScope, debugName);
      return {
        patchedStore: createPatchedStore(baseStore, scope),
        scopeContext: {
          scope: scope,
          baseStore: baseStore
        },
        hasChanged: function hasChanged(current) {
          return parentScope !== current.parentScope || baseStore !== current.baseStore || !isEqualSet(atomSet, current.atomSet) || !isEqualSet(atomFamilySet, current.atomFamilySet);
        }
      };
    }
    var _useState = react.useState(initialize),
      state = _useState[0],
      setState = _useState[1];
    var hasChanged = state.hasChanged,
      scopeContext = state.scopeContext,
      patchedStore = state.patchedStore;
    if (hasChanged({
      parentScope: parentScope,
      atomSet: atomSet,
      atomFamilySet: atomFamilySet,
      baseStore: baseStore
    })) {
      var _scopeContext$scope;
      (_scopeContext$scope = scopeContext.scope) == null || _scopeContext$scope.cleanup();
      setState(initialize);
    }
    var cleanup = scopeContext.scope.cleanup;
    useEvent(function () {
      return cleanup;
    }, []);
    return jsxRuntime.jsx(ScopeContext.Provider, {
      value: scopeContext,
      children: jsxRuntime.jsx(react$1.Provider, {
        store: patchedStore,
        children: children
      })
    });
  }
  function isEqualSet(a, b) {
    return a === b || a.size === b.size && Array.from(a).every(function (v) {
      return b.has(v);
    });
  }
  function useEvent(fn, deps) {
    var ref = react.useRef(fn);
    ref.current = fn;
    react.useEffect(function () {
      return ref.current();
    }, deps);
  }

  exports.ScopeProvider = ScopeProvider;
  exports.createIsolation = createIsolation;

}));
//# sourceMappingURL=index.umd.js.map
