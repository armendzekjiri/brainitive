let keyCount = 0;
function atom(read, write) {
  const key = `atom${++keyCount}`;
  const config = {
    toString() {
      return (import.meta.env ? import.meta.env.MODE : void 0) !== "production" && this.debugLabel ? key + ":" + this.debugLabel : key;
    }
  };
  if (typeof read === "function") {
    config.read = read;
  } else {
    config.init = read;
    config.read = defaultRead;
    config.write = defaultWrite;
  }
  if (write) {
    config.write = write;
  }
  return config;
}
function defaultRead(get) {
  return get(this);
}
function defaultWrite(get, set, arg) {
  return set(
    this,
    typeof arg === "function" ? arg(get(this)) : arg
  );
}

const isSelfAtom = (atom, a) => atom.unstable_is ? atom.unstable_is(a) : a === atom;
const hasInitialValue = (atom) => "init" in atom;
const isActuallyWritableAtom = (atom) => !!atom.write;
const cancelablePromiseMap = /* @__PURE__ */ new WeakMap();
const isPendingPromise = (value) => {
  var _a;
  return isPromiseLike(value) && !((_a = cancelablePromiseMap.get(value)) == null ? void 0 : _a[1]);
};
const cancelPromise = (promise, nextValue) => {
  const promiseState = cancelablePromiseMap.get(promise);
  if (promiseState) {
    promiseState[1] = true;
    promiseState[0].forEach((fn) => fn(nextValue));
  } else if ((import.meta.env ? import.meta.env.MODE : void 0) !== "production") {
    throw new Error("[Bug] cancelable promise not found");
  }
};
const patchPromiseForCancelability = (promise) => {
  if (cancelablePromiseMap.has(promise)) {
    return;
  }
  const promiseState = [/* @__PURE__ */ new Set(), false];
  cancelablePromiseMap.set(promise, promiseState);
  const settle = () => {
    promiseState[1] = true;
  };
  promise.then(settle, settle);
  promise.onCancel = (fn) => {
    promiseState[0].add(fn);
  };
};
const isPromiseLike = (x) => typeof (x == null ? void 0 : x.then) === "function";
const isAtomStateInitialized = (atomState) => "v" in atomState || "e" in atomState;
const returnAtomValue = (atomState) => {
  if ("e" in atomState) {
    throw atomState.e;
  }
  if ((import.meta.env ? import.meta.env.MODE : void 0) !== "production" && !("v" in atomState)) {
    throw new Error("[Bug] atom state is not initialized");
  }
  return atomState.v;
};
const addPendingPromiseToDependency = (atom, promise, dependencyAtomState) => {
  if (!dependencyAtomState.p.has(atom)) {
    dependencyAtomState.p.add(atom);
    promise.then(
      () => {
        dependencyAtomState.p.delete(atom);
      },
      () => {
        dependencyAtomState.p.delete(atom);
      }
    );
  }
};
const addDependency = (batch, atom, atomState, a, aState) => {
  var _a;
  if ((import.meta.env ? import.meta.env.MODE : void 0) !== "production" && a === atom) {
    throw new Error("[Bug] atom cannot depend on itself");
  }
  atomState.d.set(a, aState.n);
  if (isPendingPromise(atomState.v)) {
    addPendingPromiseToDependency(atom, atomState.v, aState);
  }
  (_a = aState.m) == null ? void 0 : _a.t.add(atom);
  if (batch) {
    addBatchAtomDependent(batch, a, atom);
  }
};
const createBatch = () => ({
  D: /* @__PURE__ */ new Map(),
  H: /* @__PURE__ */ new Set(),
  M: /* @__PURE__ */ new Set(),
  L: /* @__PURE__ */ new Set()
});
const addBatchFunc = (batch, priority, fn) => {
  batch[priority].add(fn);
};
const registerBatchAtom = (batch, atom, atomState) => {
  if (!batch.D.has(atom)) {
    batch.D.set(atom, /* @__PURE__ */ new Set());
    addBatchFunc(batch, "M", () => {
      var _a;
      (_a = atomState.m) == null ? void 0 : _a.l.forEach((listener) => addBatchFunc(batch, "M", listener));
    });
  }
};
const addBatchAtomDependent = (batch, atom, dependent) => {
  const dependents = batch.D.get(atom);
  if (dependents) {
    dependents.add(dependent);
  }
};
const getBatchAtomDependents = (batch, atom) => batch.D.get(atom);
const flushBatch = (batch) => {
  let error;
  let hasError = false;
  const call = (fn) => {
    try {
      fn();
    } catch (e) {
      if (!hasError) {
        error = e;
        hasError = true;
      }
    }
  };
  while (batch.H.size || batch.M.size || batch.L.size) {
    batch.D.clear();
    batch.H.forEach(call);
    batch.H.clear();
    batch.M.forEach(call);
    batch.M.clear();
    batch.L.forEach(call);
    batch.L.clear();
  }
  if (hasError) {
    throw error;
  }
};
const buildStore = (...[getAtomState, atomRead, atomWrite, atomOnMount]) => {
  const setAtomStateValueOrPromise = (atom, atomState, valueOrPromise) => {
    const hasPrevValue = "v" in atomState;
    const prevValue = atomState.v;
    const pendingPromise = isPendingPromise(atomState.v) ? atomState.v : null;
    if (isPromiseLike(valueOrPromise)) {
      patchPromiseForCancelability(valueOrPromise);
      for (const a of atomState.d.keys()) {
        addPendingPromiseToDependency(atom, valueOrPromise, getAtomState(a));
      }
      atomState.v = valueOrPromise;
    } else {
      atomState.v = valueOrPromise;
    }
    delete atomState.e;
    delete atomState.x;
    if (!hasPrevValue || !Object.is(prevValue, atomState.v)) {
      ++atomState.n;
      if (pendingPromise) {
        cancelPromise(pendingPromise, valueOrPromise);
      }
    }
  };
  const readAtomState = (batch, atom) => {
    var _a;
    const atomState = getAtomState(atom);
    if (isAtomStateInitialized(atomState)) {
      if (atomState.m && !atomState.x) {
        return atomState;
      }
      if (Array.from(atomState.d).every(
        ([a, n]) => (
          // Recursively, read the atom state of the dependency, and
          // check if the atom epoch number is unchanged
          readAtomState(batch, a).n === n
        )
      )) {
        return atomState;
      }
    }
    atomState.d.clear();
    let isSync = true;
    const getter = (a) => {
      if (isSelfAtom(atom, a)) {
        const aState2 = getAtomState(a);
        if (!isAtomStateInitialized(aState2)) {
          if (hasInitialValue(a)) {
            setAtomStateValueOrPromise(a, aState2, a.init);
          } else {
            throw new Error("no atom init");
          }
        }
        return returnAtomValue(aState2);
      }
      const aState = readAtomState(batch, a);
      try {
        return returnAtomValue(aState);
      } finally {
        if (isSync) {
          addDependency(batch, atom, atomState, a, aState);
        } else {
          const batch2 = createBatch();
          addDependency(batch2, atom, atomState, a, aState);
          mountDependencies(batch2, atom, atomState);
          flushBatch(batch2);
        }
      }
    };
    let controller;
    let setSelf;
    const options = {
      get signal() {
        if (!controller) {
          controller = new AbortController();
        }
        return controller.signal;
      },
      get setSelf() {
        if ((import.meta.env ? import.meta.env.MODE : void 0) !== "production" && !isActuallyWritableAtom(atom)) {
          console.warn("setSelf function cannot be used with read-only atom");
        }
        if (!setSelf && isActuallyWritableAtom(atom)) {
          setSelf = (...args) => {
            if ((import.meta.env ? import.meta.env.MODE : void 0) !== "production" && isSync) {
              console.warn("setSelf function cannot be called in sync");
            }
            if (!isSync) {
              return writeAtom(atom, ...args);
            }
          };
        }
        return setSelf;
      }
    };
    try {
      const valueOrPromise = atomRead(atom, getter, options);
      setAtomStateValueOrPromise(atom, atomState, valueOrPromise);
      if (isPromiseLike(valueOrPromise)) {
        (_a = valueOrPromise.onCancel) == null ? void 0 : _a.call(valueOrPromise, () => controller == null ? void 0 : controller.abort());
        const complete = () => {
          if (atomState.m) {
            const batch2 = createBatch();
            mountDependencies(batch2, atom, atomState);
            flushBatch(batch2);
          }
        };
        valueOrPromise.then(complete, complete);
      }
      return atomState;
    } catch (error) {
      delete atomState.v;
      atomState.e = error;
      delete atomState.x;
      ++atomState.n;
      return atomState;
    } finally {
      isSync = false;
    }
  };
  const readAtom = (atom) => returnAtomValue(readAtomState(void 0, atom));
  const getMountedOrBatchDependents = (batch, atom, atomState) => {
    var _a, _b;
    const dependents = /* @__PURE__ */ new Map();
    for (const a of ((_a = atomState.m) == null ? void 0 : _a.t) || []) {
      const aState = getAtomState(a);
      if (aState.m) {
        dependents.set(a, aState);
      }
    }
    for (const atomWithPendingPromise of atomState.p) {
      dependents.set(
        atomWithPendingPromise,
        getAtomState(atomWithPendingPromise)
      );
    }
    (_b = getBatchAtomDependents(batch, atom)) == null ? void 0 : _b.forEach((dependent) => {
      dependents.set(dependent, getAtomState(dependent));
    });
    return dependents;
  };
  const recomputeDependents = (batch, atom, atomState) => {
    const topSortedReversed = [];
    const visiting = /* @__PURE__ */ new Set();
    const visited = /* @__PURE__ */ new Set();
    const stack = [[atom, atomState]];
    while (stack.length > 0) {
      const [a, aState] = stack[stack.length - 1];
      if (visited.has(a)) {
        stack.pop();
        continue;
      }
      if (visiting.has(a)) {
        topSortedReversed.push([a, aState, aState.n]);
        visited.add(a);
        aState.x = true;
        stack.pop();
        continue;
      }
      visiting.add(a);
      for (const [d, s] of getMountedOrBatchDependents(batch, a, aState)) {
        if (a !== d && !visiting.has(d)) {
          stack.push([d, s]);
        }
      }
    }
    addBatchFunc(batch, "H", () => {
      const changedAtoms = /* @__PURE__ */ new Set([atom]);
      for (let i = topSortedReversed.length - 1; i >= 0; --i) {
        const [a, aState, prevEpochNumber] = topSortedReversed[i];
        let hasChangedDeps = false;
        for (const dep of aState.d.keys()) {
          if (dep !== a && changedAtoms.has(dep)) {
            hasChangedDeps = true;
            break;
          }
        }
        if (hasChangedDeps) {
          readAtomState(batch, a);
          mountDependencies(batch, a, aState);
          if (prevEpochNumber !== aState.n) {
            registerBatchAtom(batch, a, aState);
            changedAtoms.add(a);
          }
        }
        delete aState.x;
      }
    });
  };
  const writeAtomState = (batch, atom, ...args) => {
    let isSync = true;
    const getter = (a) => returnAtomValue(readAtomState(batch, a));
    const setter = (a, ...args2) => {
      const aState = getAtomState(a);
      try {
        if (isSelfAtom(atom, a)) {
          if (!hasInitialValue(a)) {
            throw new Error("atom not writable");
          }
          const prevEpochNumber = aState.n;
          const v = args2[0];
          setAtomStateValueOrPromise(a, aState, v);
          mountDependencies(batch, a, aState);
          if (prevEpochNumber !== aState.n) {
            registerBatchAtom(batch, a, aState);
            recomputeDependents(batch, a, aState);
          }
          return void 0;
        } else {
          return writeAtomState(batch, a, ...args2);
        }
      } finally {
        if (!isSync) {
          flushBatch(batch);
        }
      }
    };
    try {
      return atomWrite(atom, getter, setter, ...args);
    } finally {
      isSync = false;
    }
  };
  const writeAtom = (atom, ...args) => {
    const batch = createBatch();
    try {
      return writeAtomState(batch, atom, ...args);
    } finally {
      flushBatch(batch);
    }
  };
  const mountDependencies = (batch, atom, atomState) => {
    if (atomState.m && !isPendingPromise(atomState.v)) {
      for (const a of atomState.d.keys()) {
        if (!atomState.m.d.has(a)) {
          const aMounted = mountAtom(batch, a, getAtomState(a));
          aMounted.t.add(atom);
          atomState.m.d.add(a);
        }
      }
      for (const a of atomState.m.d || []) {
        if (!atomState.d.has(a)) {
          atomState.m.d.delete(a);
          const aMounted = unmountAtom(batch, a, getAtomState(a));
          aMounted == null ? void 0 : aMounted.t.delete(atom);
        }
      }
    }
  };
  const mountAtom = (batch, atom, atomState) => {
    if (!atomState.m) {
      readAtomState(batch, atom);
      for (const a of atomState.d.keys()) {
        const aMounted = mountAtom(batch, a, getAtomState(a));
        aMounted.t.add(atom);
      }
      atomState.m = {
        l: /* @__PURE__ */ new Set(),
        d: new Set(atomState.d.keys()),
        t: /* @__PURE__ */ new Set()
      };
      if (isActuallyWritableAtom(atom)) {
        const mounted = atomState.m;
        let setAtom;
        const createInvocationContext = (batch2, fn) => {
          let isSync = true;
          setAtom = (...args) => {
            try {
              return writeAtomState(batch2, atom, ...args);
            } finally {
              if (!isSync) {
                flushBatch(batch2);
              }
            }
          };
          try {
            return fn();
          } finally {
            isSync = false;
          }
        };
        addBatchFunc(batch, "L", () => {
          const onUnmount = createInvocationContext(
            batch,
            () => atomOnMount(atom, (...args) => setAtom(...args))
          );
          if (onUnmount) {
            mounted.u = (batch2) => createInvocationContext(batch2, onUnmount);
          }
        });
      }
    }
    return atomState.m;
  };
  const unmountAtom = (batch, atom, atomState) => {
    if (atomState.m && !atomState.m.l.size && !Array.from(atomState.m.t).some((a) => {
      var _a;
      return (_a = getAtomState(a).m) == null ? void 0 : _a.d.has(atom);
    })) {
      const onUnmount = atomState.m.u;
      if (onUnmount) {
        addBatchFunc(batch, "L", () => onUnmount(batch));
      }
      delete atomState.m;
      for (const a of atomState.d.keys()) {
        const aMounted = unmountAtom(batch, a, getAtomState(a));
        aMounted == null ? void 0 : aMounted.t.delete(atom);
      }
      return void 0;
    }
    return atomState.m;
  };
  const subscribeAtom = (atom, listener) => {
    const batch = createBatch();
    const atomState = getAtomState(atom);
    const mounted = mountAtom(batch, atom, atomState);
    const listeners = mounted.l;
    listeners.add(listener);
    flushBatch(batch);
    return () => {
      listeners.delete(listener);
      const batch2 = createBatch();
      unmountAtom(batch2, atom, atomState);
      flushBatch(batch2);
    };
  };
  const unstable_derive = (fn) => buildStore(...fn(getAtomState, atomRead, atomWrite, atomOnMount));
  const store = {
    get: readAtom,
    set: writeAtom,
    sub: subscribeAtom,
    unstable_derive
  };
  return store;
};
const deriveDevStoreRev4 = (store) => {
  const proxyAtomStateMap = /* @__PURE__ */ new WeakMap();
  const debugMountedAtoms = /* @__PURE__ */ new Set();
  let savedGetAtomState;
  let inRestoreAtom = 0;
  const derivedStore = store.unstable_derive(
    (getAtomState, atomRead, atomWrite, atomOnMount) => {
      savedGetAtomState = getAtomState;
      return [
        (atom) => {
          let proxyAtomState = proxyAtomStateMap.get(atom);
          if (!proxyAtomState) {
            const atomState = getAtomState(atom);
            proxyAtomState = new Proxy(atomState, {
              set(target, prop, value) {
                if (prop === "m") {
                  debugMountedAtoms.add(atom);
                }
                return Reflect.set(target, prop, value);
              },
              deleteProperty(target, prop) {
                if (prop === "m") {
                  debugMountedAtoms.delete(atom);
                }
                return Reflect.deleteProperty(target, prop);
              }
            });
            proxyAtomStateMap.set(atom, proxyAtomState);
          }
          return proxyAtomState;
        },
        atomRead,
        (atom, getter, setter, ...args) => {
          if (inRestoreAtom) {
            return setter(atom, ...args);
          }
          return atomWrite(atom, getter, setter, ...args);
        },
        atomOnMount
      ];
    }
  );
  const savedStoreSet = derivedStore.set;
  const devStore = {
    // store dev methods (these are tentative and subject to change without notice)
    dev4_get_internal_weak_map: () => ({
      get: (atom) => {
        const atomState = savedGetAtomState(atom);
        if (atomState.n === 0) {
          return void 0;
        }
        return atomState;
      }
    }),
    dev4_get_mounted_atoms: () => debugMountedAtoms,
    dev4_restore_atoms: (values) => {
      const restoreAtom = {
        read: () => null,
        write: (_get, set) => {
          ++inRestoreAtom;
          try {
            for (const [atom, value] of values) {
              if (hasInitialValue(atom)) {
                set(atom, value);
              }
            }
          } finally {
            --inRestoreAtom;
          }
        }
      };
      savedStoreSet(restoreAtom);
    }
  };
  return Object.assign(derivedStore, devStore);
};
const createStore = () => {
  const atomStateMap = /* @__PURE__ */ new WeakMap();
  const getAtomState = (atom) => {
    if ((import.meta.env ? import.meta.env.MODE : void 0) !== "production" && !atom) {
      throw new Error("Atom is undefined or null");
    }
    let atomState = atomStateMap.get(atom);
    if (!atomState) {
      atomState = { d: /* @__PURE__ */ new Map(), p: /* @__PURE__ */ new Set(), n: 0 };
      atomStateMap.set(atom, atomState);
    }
    return atomState;
  };
  const store = buildStore(
    getAtomState,
    (atom, ...params) => atom.read(...params),
    (atom, ...params) => atom.write(...params),
    (atom, ...params) => {
      var _a;
      return (_a = atom.onMount) == null ? void 0 : _a.call(atom, ...params);
    }
  );
  if ((import.meta.env ? import.meta.env.MODE : void 0) !== "production") {
    return deriveDevStoreRev4(store);
  }
  return store;
};
let defaultStore;
const getDefaultStore = () => {
  if (!defaultStore) {
    defaultStore = createStore();
    if ((import.meta.env ? import.meta.env.MODE : void 0) !== "production") {
      globalThis.__JOTAI_DEFAULT_STORE__ || (globalThis.__JOTAI_DEFAULT_STORE__ = defaultStore);
      if (globalThis.__JOTAI_DEFAULT_STORE__ !== defaultStore) {
        console.warn(
          "Detected multiple Jotai instances. It may cause unexpected behavior with the default store. https://github.com/pmndrs/jotai/discussions/2044"
        );
      }
    }
  }
  return defaultStore;
};

export { atom, createStore, getDefaultStore };
