(function (global, factory) {
  typeof exports === 'object' && typeof module !== 'undefined' ? factory(exports) :
  typeof define === 'function' && define.amd ? define(['exports'], factory) :
  (global = typeof globalThis !== 'undefined' ? globalThis : global || self, factory(global.jotaiVanilla = {}));
})(this, (function (exports) { 'use strict';

  var keyCount = 0;
  function atom(read, write) {
    var key = "atom" + ++keyCount;
    var config = {
      toString: function toString() {
        return this.debugLabel ? key + ':' + this.debugLabel : key;
      }
    };
    if (typeof read === 'function') {
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
    return set(this, typeof arg === 'function' ? arg(get(this)) : arg);
  }

  function _arrayLikeToArray(r, a) {
    (null == a || a > r.length) && (a = r.length);
    for (var e = 0, n = Array(a); e < a; e++) n[e] = r[e];
    return n;
  }
  function _createForOfIteratorHelperLoose(r, e) {
    var t = "undefined" != typeof Symbol && r[Symbol.iterator] || r["@@iterator"];
    if (t) return (t = t.call(r)).next.bind(t);
    if (Array.isArray(r) || (t = _unsupportedIterableToArray(r)) || e) {
      t && (r = t);
      var o = 0;
      return function () {
        return o >= r.length ? {
          done: !0
        } : {
          done: !1,
          value: r[o++]
        };
      };
    }
    throw new TypeError("Invalid attempt to iterate non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method.");
  }
  function _unsupportedIterableToArray(r, a) {
    if (r) {
      if ("string" == typeof r) return _arrayLikeToArray(r, a);
      var t = {}.toString.call(r).slice(8, -1);
      return "Object" === t && r.constructor && (t = r.constructor.name), "Map" === t || "Set" === t ? Array.from(r) : "Arguments" === t || /^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(t) ? _arrayLikeToArray(r, a) : void 0;
    }
  }

  var isSelfAtom = function isSelfAtom(atom, a) {
    return atom.unstable_is ? atom.unstable_is(a) : a === atom;
  };
  var hasInitialValue = function hasInitialValue(atom) {
    return 'init' in atom;
  };
  var isActuallyWritableAtom = function isActuallyWritableAtom(atom) {
    return !!atom.write;
  };
  var cancelablePromiseMap = new WeakMap();
  var isPendingPromise = function isPendingPromise(value) {
    var _cancelablePromiseMap;
    return isPromiseLike(value) && !((_cancelablePromiseMap = cancelablePromiseMap.get(value)) != null && _cancelablePromiseMap[1]);
  };
  var cancelPromise = function cancelPromise(promise, nextValue) {
    var promiseState = cancelablePromiseMap.get(promise);
    if (promiseState) {
      promiseState[1] = true;
      promiseState[0].forEach(function (fn) {
        return fn(nextValue);
      });
    } else {
      throw new Error('[Bug] cancelable promise not found');
    }
  };
  var patchPromiseForCancelability = function patchPromiseForCancelability(promise) {
    if (cancelablePromiseMap.has(promise)) {
      return;
    }
    var promiseState = [new Set(), false];
    cancelablePromiseMap.set(promise, promiseState);
    var settle = function settle() {
      promiseState[1] = true;
    };
    promise.then(settle, settle);
    promise.onCancel = function (fn) {
      promiseState[0].add(fn);
    };
  };
  var isPromiseLike = function isPromiseLike(x) {
    return typeof (x == null ? void 0 : x.then) === 'function';
  };
  var isAtomStateInitialized = function isAtomStateInitialized(atomState) {
    return 'v' in atomState || 'e' in atomState;
  };
  var returnAtomValue = function returnAtomValue(atomState) {
    if ('e' in atomState) {
      throw atomState.e;
    }
    if (!('v' in atomState)) {
      throw new Error('[Bug] atom state is not initialized');
    }
    return atomState.v;
  };
  var addPendingPromiseToDependency = function addPendingPromiseToDependency(atom, promise, dependencyAtomState) {
    if (!dependencyAtomState.p.has(atom)) {
      dependencyAtomState.p.add(atom);
      promise.then(function () {
        dependencyAtomState.p.delete(atom);
      }, function () {
        dependencyAtomState.p.delete(atom);
      });
    }
  };
  var addDependency = function addDependency(batch, atom, atomState, a, aState) {
    var _aState$m;
    if (a === atom) {
      throw new Error('[Bug] atom cannot depend on itself');
    }
    atomState.d.set(a, aState.n);
    if (isPendingPromise(atomState.v)) {
      addPendingPromiseToDependency(atom, atomState.v, aState);
    }
    (_aState$m = aState.m) == null || _aState$m.t.add(atom);
    if (batch) {
      addBatchAtomDependent(batch, a, atom);
    }
  };
  var createBatch = function createBatch() {
    return {
      D: new Map(),
      H: new Set(),
      M: new Set(),
      L: new Set()
    };
  };
  var addBatchFunc = function addBatchFunc(batch, priority, fn) {
    batch[priority].add(fn);
  };
  var registerBatchAtom = function registerBatchAtom(batch, atom, atomState) {
    if (!batch.D.has(atom)) {
      batch.D.set(atom, new Set());
      addBatchFunc(batch, 'M', function () {
        var _atomState$m;
        (_atomState$m = atomState.m) == null || _atomState$m.l.forEach(function (listener) {
          return addBatchFunc(batch, 'M', listener);
        });
      });
    }
  };
  var addBatchAtomDependent = function addBatchAtomDependent(batch, atom, dependent) {
    var dependents = batch.D.get(atom);
    if (dependents) {
      dependents.add(dependent);
    }
  };
  var getBatchAtomDependents = function getBatchAtomDependents(batch, atom) {
    return batch.D.get(atom);
  };
  var flushBatch = function flushBatch(batch) {
    var error;
    var hasError = false;
    var call = function call(fn) {
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
  var _buildStore = function buildStore() {
    for (var _len = arguments.length, _ref = new Array(_len), _key = 0; _key < _len; _key++) {
      _ref[_key] = arguments[_key];
    }
    var getAtomState = _ref[0],
      atomRead = _ref[1],
      atomWrite = _ref[2],
      atomOnMount = _ref[3];
    var setAtomStateValueOrPromise = function setAtomStateValueOrPromise(atom, atomState, valueOrPromise) {
      var hasPrevValue = 'v' in atomState;
      var prevValue = atomState.v;
      var pendingPromise = isPendingPromise(atomState.v) ? atomState.v : null;
      if (isPromiseLike(valueOrPromise)) {
        patchPromiseForCancelability(valueOrPromise);
        for (var _iterator = _createForOfIteratorHelperLoose(atomState.d.keys()), _step; !(_step = _iterator()).done;) {
          var a = _step.value;
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
    var _readAtomState = function readAtomState(batch, atom) {
      var atomState = getAtomState(atom);
      if (isAtomStateInitialized(atomState)) {
        if (atomState.m && !atomState.x) {
          return atomState;
        }
        if (Array.from(atomState.d).every(function (_ref2) {
          var a = _ref2[0],
            n = _ref2[1];
          return (_readAtomState(batch, a).n === n
          );
        })) {
          return atomState;
        }
      }
      atomState.d.clear();
      var isSync = true;
      var getter = function getter(a) {
        if (isSelfAtom(atom, a)) {
          var _aState = getAtomState(a);
          if (!isAtomStateInitialized(_aState)) {
            if (hasInitialValue(a)) {
              setAtomStateValueOrPromise(a, _aState, a.init);
            } else {
              throw new Error('no atom init');
            }
          }
          return returnAtomValue(_aState);
        }
        var aState = _readAtomState(batch, a);
        try {
          return returnAtomValue(aState);
        } finally {
          if (isSync) {
            addDependency(batch, atom, atomState, a, aState);
          } else {
            var _batch = createBatch();
            addDependency(_batch, atom, atomState, a, aState);
            mountDependencies(_batch, atom, atomState);
            flushBatch(_batch);
          }
        }
      };
      var controller;
      var setSelf;
      var options = {
        get signal() {
          if (!controller) {
            controller = new AbortController();
          }
          return controller.signal;
        },
        get setSelf() {
          if (!isActuallyWritableAtom(atom)) {
            console.warn('setSelf function cannot be used with read-only atom');
          }
          if (!setSelf && isActuallyWritableAtom(atom)) {
            setSelf = function setSelf() {
              if (isSync) {
                console.warn('setSelf function cannot be called in sync');
              }
              if (!isSync) {
                for (var _len2 = arguments.length, args = new Array(_len2), _key2 = 0; _key2 < _len2; _key2++) {
                  args[_key2] = arguments[_key2];
                }
                return writeAtom.apply(void 0, [atom].concat(args));
              }
            };
          }
          return setSelf;
        }
      };
      try {
        var valueOrPromise = atomRead(atom, getter, options);
        setAtomStateValueOrPromise(atom, atomState, valueOrPromise);
        if (isPromiseLike(valueOrPromise)) {
          valueOrPromise.onCancel == null || valueOrPromise.onCancel(function () {
            var _controller;
            return (_controller = controller) == null ? void 0 : _controller.abort();
          });
          var complete = function complete() {
            if (atomState.m) {
              var _batch2 = createBatch();
              mountDependencies(_batch2, atom, atomState);
              flushBatch(_batch2);
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
    var readAtom = function readAtom(atom) {
      return returnAtomValue(_readAtomState(undefined, atom));
    };
    var getMountedOrBatchDependents = function getMountedOrBatchDependents(batch, atom, atomState) {
      var _getBatchAtomDependen;
      var dependents = new Map();
      for (var _iterator2 = _createForOfIteratorHelperLoose(((_atomState$m2 = atomState.m) == null ? void 0 : _atomState$m2.t) || []), _step2; !(_step2 = _iterator2()).done;) {
        var _atomState$m2;
        var a = _step2.value;
        var aState = getAtomState(a);
        if (aState.m) {
          dependents.set(a, aState);
        }
      }
      for (var _iterator3 = _createForOfIteratorHelperLoose(atomState.p), _step3; !(_step3 = _iterator3()).done;) {
        var atomWithPendingPromise = _step3.value;
        dependents.set(atomWithPendingPromise, getAtomState(atomWithPendingPromise));
      }
      (_getBatchAtomDependen = getBatchAtomDependents(batch, atom)) == null || _getBatchAtomDependen.forEach(function (dependent) {
        dependents.set(dependent, getAtomState(dependent));
      });
      return dependents;
    };
    var recomputeDependents = function recomputeDependents(batch, atom, atomState) {
      var topSortedReversed = [];
      var visiting = new Set();
      var visited = new Set();
      var stack = [[atom, atomState]];
      while (stack.length > 0) {
        var _ref3 = stack[stack.length - 1],
          a = _ref3[0],
          aState = _ref3[1];
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
        for (var _iterator4 = _createForOfIteratorHelperLoose(getMountedOrBatchDependents(batch, a, aState)), _step4; !(_step4 = _iterator4()).done;) {
          var _step4$value = _step4.value,
            d = _step4$value[0],
            s = _step4$value[1];
          if (a !== d && !visiting.has(d)) {
            stack.push([d, s]);
          }
        }
      }
      addBatchFunc(batch, 'H', function () {
        var changedAtoms = new Set([atom]);
        for (var i = topSortedReversed.length - 1; i >= 0; --i) {
          var _ref4 = topSortedReversed[i],
            _a = _ref4[0],
            _aState2 = _ref4[1],
            prevEpochNumber = _ref4[2];
          var hasChangedDeps = false;
          for (var _iterator5 = _createForOfIteratorHelperLoose(_aState2.d.keys()), _step5; !(_step5 = _iterator5()).done;) {
            var dep = _step5.value;
            if (dep !== _a && changedAtoms.has(dep)) {
              hasChangedDeps = true;
              break;
            }
          }
          if (hasChangedDeps) {
            _readAtomState(batch, _a);
            mountDependencies(batch, _a, _aState2);
            if (prevEpochNumber !== _aState2.n) {
              registerBatchAtom(batch, _a, _aState2);
              changedAtoms.add(_a);
            }
          }
          delete _aState2.x;
        }
      });
    };
    var _writeAtomState = function writeAtomState(batch, atom) {
      var isSync = true;
      var getter = function getter(a) {
        return returnAtomValue(_readAtomState(batch, a));
      };
      var setter = function setter(a) {
        var aState = getAtomState(a);
        try {
          for (var _len4 = arguments.length, args = new Array(_len4 > 1 ? _len4 - 1 : 0), _key4 = 1; _key4 < _len4; _key4++) {
            args[_key4 - 1] = arguments[_key4];
          }
          if (isSelfAtom(atom, a)) {
            if (!hasInitialValue(a)) {
              throw new Error('atom not writable');
            }
            var prevEpochNumber = aState.n;
            var v = args[0];
            setAtomStateValueOrPromise(a, aState, v);
            mountDependencies(batch, a, aState);
            if (prevEpochNumber !== aState.n) {
              registerBatchAtom(batch, a, aState);
              recomputeDependents(batch, a, aState);
            }
            return undefined;
          } else {
            return _writeAtomState.apply(void 0, [batch, a].concat(args));
          }
        } finally {
          if (!isSync) {
            flushBatch(batch);
          }
        }
      };
      try {
        for (var _len3 = arguments.length, args = new Array(_len3 > 2 ? _len3 - 2 : 0), _key3 = 2; _key3 < _len3; _key3++) {
          args[_key3 - 2] = arguments[_key3];
        }
        return atomWrite.apply(void 0, [atom, getter, setter].concat(args));
      } finally {
        isSync = false;
      }
    };
    var writeAtom = function writeAtom(atom) {
      var batch = createBatch();
      try {
        for (var _len5 = arguments.length, args = new Array(_len5 > 1 ? _len5 - 1 : 0), _key5 = 1; _key5 < _len5; _key5++) {
          args[_key5 - 1] = arguments[_key5];
        }
        return _writeAtomState.apply(void 0, [batch, atom].concat(args));
      } finally {
        flushBatch(batch);
      }
    };
    var mountDependencies = function mountDependencies(batch, atom, atomState) {
      if (atomState.m && !isPendingPromise(atomState.v)) {
        for (var _iterator6 = _createForOfIteratorHelperLoose(atomState.d.keys()), _step6; !(_step6 = _iterator6()).done;) {
          var a = _step6.value;
          if (!atomState.m.d.has(a)) {
            var aMounted = _mountAtom(batch, a, getAtomState(a));
            aMounted.t.add(atom);
            atomState.m.d.add(a);
          }
        }
        for (var _iterator7 = _createForOfIteratorHelperLoose(atomState.m.d || []), _step7; !(_step7 = _iterator7()).done;) {
          var _a2 = _step7.value;
          if (!atomState.d.has(_a2)) {
            atomState.m.d.delete(_a2);
            var _aMounted = _unmountAtom(batch, _a2, getAtomState(_a2));
            _aMounted == null || _aMounted.t.delete(atom);
          }
        }
      }
    };
    var _mountAtom = function mountAtom(batch, atom, atomState) {
      if (!atomState.m) {
        _readAtomState(batch, atom);
        for (var _iterator8 = _createForOfIteratorHelperLoose(atomState.d.keys()), _step8; !(_step8 = _iterator8()).done;) {
          var a = _step8.value;
          var aMounted = _mountAtom(batch, a, getAtomState(a));
          aMounted.t.add(atom);
        }
        atomState.m = {
          l: new Set(),
          d: new Set(atomState.d.keys()),
          t: new Set()
        };
        if (isActuallyWritableAtom(atom)) {
          var mounted = atomState.m;
          var _setAtom;
          var createInvocationContext = function createInvocationContext(batch, fn) {
            var isSync = true;
            _setAtom = function _setAtom() {
              try {
                for (var _len6 = arguments.length, args = new Array(_len6), _key6 = 0; _key6 < _len6; _key6++) {
                  args[_key6] = arguments[_key6];
                }
                return _writeAtomState.apply(void 0, [batch, atom].concat(args));
              } finally {
                if (!isSync) {
                  flushBatch(batch);
                }
              }
            };
            try {
              return fn();
            } finally {
              isSync = false;
            }
          };
          addBatchFunc(batch, 'L', function () {
            var onUnmount = createInvocationContext(batch, function () {
              return atomOnMount(atom, function () {
                return _setAtom.apply(void 0, arguments);
              });
            });
            if (onUnmount) {
              mounted.u = function (batch) {
                return createInvocationContext(batch, onUnmount);
              };
            }
          });
        }
      }
      return atomState.m;
    };
    var _unmountAtom = function unmountAtom(batch, atom, atomState) {
      if (atomState.m && !atomState.m.l.size && !Array.from(atomState.m.t).some(function (a) {
        var _getAtomState$m;
        return (_getAtomState$m = getAtomState(a).m) == null ? void 0 : _getAtomState$m.d.has(atom);
      })) {
        var onUnmount = atomState.m.u;
        if (onUnmount) {
          addBatchFunc(batch, 'L', function () {
            return onUnmount(batch);
          });
        }
        delete atomState.m;
        for (var _iterator9 = _createForOfIteratorHelperLoose(atomState.d.keys()), _step9; !(_step9 = _iterator9()).done;) {
          var a = _step9.value;
          var aMounted = _unmountAtom(batch, a, getAtomState(a));
          aMounted == null || aMounted.t.delete(atom);
        }
        return undefined;
      }
      return atomState.m;
    };
    var subscribeAtom = function subscribeAtom(atom, listener) {
      var batch = createBatch();
      var atomState = getAtomState(atom);
      var mounted = _mountAtom(batch, atom, atomState);
      var listeners = mounted.l;
      listeners.add(listener);
      flushBatch(batch);
      return function () {
        listeners.delete(listener);
        var batch = createBatch();
        _unmountAtom(batch, atom, atomState);
        flushBatch(batch);
      };
    };
    var unstable_derive = function unstable_derive(fn) {
      return _buildStore.apply(void 0, fn(getAtomState, atomRead, atomWrite, atomOnMount));
    };
    var store = {
      get: readAtom,
      set: writeAtom,
      sub: subscribeAtom,
      unstable_derive: unstable_derive
    };
    return store;
  };
  var deriveDevStoreRev4 = function deriveDevStoreRev4(store) {
    var proxyAtomStateMap = new WeakMap();
    var debugMountedAtoms = new Set();
    var savedGetAtomState;
    var inRestoreAtom = 0;
    var derivedStore = store.unstable_derive(function (getAtomState, atomRead, atomWrite, atomOnMount) {
      savedGetAtomState = getAtomState;
      return [function (atom) {
        var proxyAtomState = proxyAtomStateMap.get(atom);
        if (!proxyAtomState) {
          var atomState = getAtomState(atom);
          proxyAtomState = new Proxy(atomState, {
            set: function set(target, prop, value) {
              if (prop === 'm') {
                debugMountedAtoms.add(atom);
              }
              return Reflect.set(target, prop, value);
            },
            deleteProperty: function deleteProperty(target, prop) {
              if (prop === 'm') {
                debugMountedAtoms.delete(atom);
              }
              return Reflect.deleteProperty(target, prop);
            }
          });
          proxyAtomStateMap.set(atom, proxyAtomState);
        }
        return proxyAtomState;
      }, atomRead, function (atom, getter, setter) {
        for (var _len7 = arguments.length, args = new Array(_len7 > 3 ? _len7 - 3 : 0), _key7 = 3; _key7 < _len7; _key7++) {
          args[_key7 - 3] = arguments[_key7];
        }
        if (inRestoreAtom) {
          return setter.apply(void 0, [atom].concat(args));
        }
        return atomWrite.apply(void 0, [atom, getter, setter].concat(args));
      }, atomOnMount];
    });
    var savedStoreSet = derivedStore.set;
    var devStore = {
      dev4_get_internal_weak_map: function dev4_get_internal_weak_map() {
        return {
          get: function get(atom) {
            var atomState = savedGetAtomState(atom);
            if (atomState.n === 0) {
              return undefined;
            }
            return atomState;
          }
        };
      },
      dev4_get_mounted_atoms: function dev4_get_mounted_atoms() {
        return debugMountedAtoms;
      },
      dev4_restore_atoms: function dev4_restore_atoms(values) {
        var restoreAtom = {
          read: function read() {
            return null;
          },
          write: function write(_get, set) {
            ++inRestoreAtom;
            try {
              for (var _iterator10 = _createForOfIteratorHelperLoose(values), _step10; !(_step10 = _iterator10()).done;) {
                var _step10$value = _step10.value,
                  _atom = _step10$value[0],
                  value = _step10$value[1];
                if (hasInitialValue(_atom)) {
                  set(_atom, value);
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
  var createStore = function createStore() {
    var atomStateMap = new WeakMap();
    var getAtomState = function getAtomState(atom) {
      if (!atom) {
        throw new Error('Atom is undefined or null');
      }
      var atomState = atomStateMap.get(atom);
      if (!atomState) {
        atomState = {
          d: new Map(),
          p: new Set(),
          n: 0
        };
        atomStateMap.set(atom, atomState);
      }
      return atomState;
    };
    var store = _buildStore(getAtomState, function (atom) {
      for (var _len8 = arguments.length, params = new Array(_len8 > 1 ? _len8 - 1 : 0), _key8 = 1; _key8 < _len8; _key8++) {
        params[_key8 - 1] = arguments[_key8];
      }
      return atom.read.apply(atom, params);
    }, function (atom) {
      for (var _len9 = arguments.length, params = new Array(_len9 > 1 ? _len9 - 1 : 0), _key9 = 1; _key9 < _len9; _key9++) {
        params[_key9 - 1] = arguments[_key9];
      }
      return atom.write.apply(atom, params);
    }, function (atom) {
      for (var _len10 = arguments.length, params = new Array(_len10 > 1 ? _len10 - 1 : 0), _key10 = 1; _key10 < _len10; _key10++) {
        params[_key10 - 1] = arguments[_key10];
      }
      return atom.onMount == null ? void 0 : atom.onMount.apply(atom, params);
    });
    {
      return deriveDevStoreRev4(store);
    }
  };
  var defaultStore;
  var getDefaultStore = function getDefaultStore() {
    if (!defaultStore) {
      defaultStore = createStore();
      {
        var _ref5;
        (_ref5 = globalThis).__JOTAI_DEFAULT_STORE__ || (_ref5.__JOTAI_DEFAULT_STORE__ = defaultStore);
        if (globalThis.__JOTAI_DEFAULT_STORE__ !== defaultStore) {
          console.warn('Detected multiple Jotai instances. It may cause unexpected behavior with the default store. https://github.com/pmndrs/jotai/discussions/2044');
        }
      }
    }
    return defaultStore;
  };

  exports.atom = atom;
  exports.createStore = createStore;
  exports.getDefaultStore = getDefaultStore;

}));
