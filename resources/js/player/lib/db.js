// Offline-first persistence for the decoupled-brain player.
//
// Two logical stores:
//   • meta      — key/value blobs: the device session, the pre-baked schedule,
//                 and the quota snapshot. Survives power-cycle so the board can
//                 cold-boot and play with no network.
//   • log_queue — append-only play events keyed by a client-generated UUID.
//                 Each carries `synced:false` until the server confirms ingest;
//                 the UUID is the idempotency key that prevents double-charging.
//
// IndexedDB is primary (works over plain-http LAN, unlike the Cache API). If it
// is unavailable or fails to open, we degrade to a localStorage-backed shim so
// the app still runs — durability is reduced but playback is not blocked.

const DB_NAME = "bb-player";
const DB_VERSION = 1;
const META_STORE = "meta";
const QUEUE_STORE = "log_queue";

let dbPromise = null;

function hasIndexedDB() {
  try {
    return typeof indexedDB !== "undefined" && indexedDB !== null;
  } catch {
    return false;
  }
}

function openDB() {
  if (dbPromise) return dbPromise;
  dbPromise = new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, DB_VERSION);
    req.onupgradeneeded = () => {
      const db = req.result;
      if (!db.objectStoreNames.contains(META_STORE)) {
        db.createObjectStore(META_STORE);
      }
      if (!db.objectStoreNames.contains(QUEUE_STORE)) {
        const store = db.createObjectStore(QUEUE_STORE, {
          keyPath: "client_event_id",
        });
        store.createIndex("synced", "synced", { unique: false });
      }
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
  return dbPromise;
}

function tx(store, mode, fn) {
  return openDB().then(
    (db) =>
      new Promise((resolve, reject) => {
        const transaction = db.transaction(store, mode);
        const objectStore = transaction.objectStore(store);
        const result = fn(objectStore);
        transaction.oncomplete = () => resolve(result.value);
        transaction.onerror = () => reject(transaction.error);
        transaction.onabort = () => reject(transaction.error);
      }),
  );
}

// ── localStorage fallback ───────────────────────────────────────────────────
const LS_META_PREFIX = "bb_meta:";
const LS_QUEUE_KEY = "bb_log_queue";

const lsFallback = {
  metaGet(key) {
    try {
      const raw = localStorage.getItem(LS_META_PREFIX + key);
      return Promise.resolve(raw ? JSON.parse(raw) : null);
    } catch {
      return Promise.resolve(null);
    }
  },
  metaSet(key, value) {
    try {
      localStorage.setItem(LS_META_PREFIX + key, JSON.stringify(value));
    } catch {
      /* quota or disabled storage — best effort */
    }
    return Promise.resolve();
  },
  metaDel(key) {
    try {
      localStorage.removeItem(LS_META_PREFIX + key);
    } catch {
      /* ignore */
    }
    return Promise.resolve();
  },
  _readQueue() {
    try {
      const raw = localStorage.getItem(LS_QUEUE_KEY);
      return raw ? JSON.parse(raw) : [];
    } catch {
      return [];
    }
  },
  _writeQueue(items) {
    try {
      localStorage.setItem(LS_QUEUE_KEY, JSON.stringify(items));
    } catch {
      /* ignore */
    }
  },
  queueAdd(event) {
    const items = lsFallback._readQueue();
    items.push(event);
    lsFallback._writeQueue(items);
    return Promise.resolve();
  },
  queueUnsynced() {
    return Promise.resolve(lsFallback._readQueue().filter((e) => !e.synced));
  },
  queueMarkSynced(ids) {
    const set = new Set(ids);
    const items = lsFallback
      ._readQueue()
      .map((e) => (set.has(e.client_event_id) ? { ...e, synced: true } : e));
    lsFallback._writeQueue(items);
    return Promise.resolve();
  },
  queuePurgeSynced() {
    lsFallback._writeQueue(lsFallback._readQueue().filter((e) => !e.synced));
    return Promise.resolve();
  },
};

// ── Public API (IndexedDB primary, localStorage fallback) ────────────────────
const useFallback = !hasIndexedDB();

export function metaGet(key) {
  if (useFallback) return lsFallback.metaGet(key);
  return tx(META_STORE, "readonly", (store) => {
    const out = {};
    const req = store.get(key);
    req.onsuccess = () => (out.value = req.result ?? null);
    return out;
  }).catch(() => lsFallback.metaGet(key));
}

export function metaSet(key, value) {
  if (useFallback) return lsFallback.metaSet(key, value);
  return tx(META_STORE, "readwrite", (store) => {
    store.put(value, key);
    return {};
  }).catch(() => lsFallback.metaSet(key, value));
}

export function metaDel(key) {
  if (useFallback) return lsFallback.metaDel(key);
  return tx(META_STORE, "readwrite", (store) => {
    store.delete(key);
    return {};
  }).catch(() => lsFallback.metaDel(key));
}

export function queueAdd(event) {
  if (useFallback) return lsFallback.queueAdd(event);
  return tx(QUEUE_STORE, "readwrite", (store) => {
    store.put(event);
    return {};
  }).catch(() => lsFallback.queueAdd(event));
}

export function queueUnsynced() {
  if (useFallback) return lsFallback.queueUnsynced();
  return tx(QUEUE_STORE, "readonly", (store) => {
    const out = { value: [] };
    const req = store.getAll();
    req.onsuccess = () =>
      (out.value = (req.result || []).filter((e) => !e.synced));
    return out;
  }).catch(() => lsFallback.queueUnsynced());
}

export function queueMarkSynced(ids) {
  if (!ids || ids.length === 0) return Promise.resolve();
  if (useFallback) return lsFallback.queueMarkSynced(ids);
  return tx(QUEUE_STORE, "readwrite", (store) => {
    ids.forEach((id) => {
      const req = store.get(id);
      req.onsuccess = () => {
        const rec = req.result;
        if (rec) store.put({ ...rec, synced: true });
      };
    });
    return {};
  }).catch(() => lsFallback.queueMarkSynced(ids));
}

export function queuePurgeSynced() {
  if (useFallback) return lsFallback.queuePurgeSynced();
  return tx(QUEUE_STORE, "readwrite", (store) => {
    const req = store.getAll();
    req.onsuccess = () =>
      (req.result || [])
        .filter((e) => e.synced)
        .forEach((e) => store.delete(e.client_event_id));
    return {};
  }).catch(() => lsFallback.queuePurgeSynced());
}
