// The app-shell service worker decides, per request, whether to answer from
// cache. Two of those decisions are load-bearing enough that a regression would
// be an outage rather than a slowdown:
//
//   • It must never answer /api/* from cache. useConnectionStatus decides the
//     board is online by probing /sync/ping, so a cached 200 there would make a
//     board with a dead backend believe it is online and stop queueing plays.
//   • It must never delete "bcc-edge-cache-v1", which is where useEdgeCache
//     keeps the media a board needs to keep playing offline.
//
// public/sw.js is plain static JS (not bundled), so we evaluate it here with
// injected service-worker globals and drive its event handlers directly.
import { test } from 'node:test';
import assert from 'node:assert';
import { readFileSync } from 'node:fs';

const SW_SRC = new URL('../../public/sw.js', import.meta.url);
const ORIGIN = 'https://boards.example.test';

class FakeRequest {
  constructor(url, opts = {}) {
    this.url = String(url);
    this.method = 'GET';
    Object.assign(this, opts);
  }
}

function body(res) {
  return res && typeof res.text === 'function' ? res.text() : Promise.resolve(null);
}

function makeCaches() {
  const stores = new Map();
  const key = (k) => (typeof k === 'string' ? new URL(k, ORIGIN).href : k.url);

  function open(name) {
    if (!stores.has(name)) stores.set(name, new Map());
    const store = stores.get(name);
    return Promise.resolve({
      match: (k) => Promise.resolve(store.get(key(k))),
      put: (k, v) => { store.set(key(k), v); return Promise.resolve(); },
      add: (k) => globalThis.__swFetch(k).then((res) => { store.set(key(k), res); }),
    });
  }

  return {
    stores,
    open,
    keys: () => Promise.resolve([...stores.keys()]),
    delete: (n) => Promise.resolve(stores.delete(n)),
  };
}

// Loads sw.js into a fresh fake global scope and returns its handlers.
function loadSW({ fetchImpl } = {}) {
  const handlers = {};
  const claimed = { value: false };
  const caches = makeCaches();

  globalThis.__swFetch = fetchImpl || (() => Promise.reject(new Error('offline')));

  const self = {
    location: new URL('/sw.js', ORIGIN),
    addEventListener: (type, fn) => { handlers[type] = fn; },
    skipWaiting: () => Promise.resolve(),
    clients: { claim: () => { claimed.value = true; return Promise.resolve(); } },
  };

  const factory = new Function(
    'self', 'caches', 'fetch', 'Response', 'Request', 'URL',
    'setTimeout', 'clearTimeout', 'console',
    readFileSync(SW_SRC, 'utf8'),
  );
  factory(
    self, caches, (...a) => globalThis.__swFetch(...a), Response, FakeRequest, URL,
    setTimeout, clearTimeout, console,
  );

  return { handlers, caches, claimed };
}

// Drives the fetch handler; returns the Response it chose to serve, or null if
// it declined to handle the request (i.e. let it go straight to the network).
async function routeFetch(handlers, request) {
  let responded = null;
  handlers.fetch({ request, respondWith: (p) => { responded = p; } });
  return responded === null ? null : await responded;
}

test('activate purges superseded shell caches but preserves the media cache', async () => {
  const { handlers, caches } = loadSW();
  caches.stores.set('bcc-player-shell-v0', new Map());
  caches.stores.set('bcc-player-shell-v1', new Map());
  caches.stores.set('bcc-edge-cache-v1', new Map([['media', 'blob']]));

  await handlers.activate({ waitUntil: (p) => p });

  const names = [...caches.stores.keys()].sort();
  assert.deepStrictEqual(names, ['bcc-edge-cache-v1', 'bcc-player-shell-v1']);
  assert.strictEqual(caches.stores.get('bcc-edge-cache-v1').get('media'), 'blob');
});

test('API requests are never handled by the worker', async () => {
  const { handlers } = loadSW({ fetchImpl: () => Promise.resolve(new Response('x')) });
  for (const path of ['/api/v1/sync', '/api/v1/sync/ping', '/api/v1/assets/9/serve']) {
    const res = await routeFetch(handlers, new FakeRequest(ORIGIN + path));
    assert.strictEqual(res, null, `${path} must go straight to the network`);
  }
});

test('cross-origin and non-GET requests are left alone', async () => {
  const { handlers } = loadSW();
  assert.strictEqual(
    await routeFetch(handlers, new FakeRequest('https://r2.example.com/clip.mp4')),
    null,
  );
  assert.strictEqual(
    await routeFetch(handlers, new FakeRequest(ORIGIN + '/player', { method: 'POST' })),
    null,
  );
});

test('hashed build assets are served from cache after the first fetch', async () => {
  let hits = 0;
  const { handlers } = loadSW({
    fetchImpl: () => { hits += 1; return Promise.resolve(new Response('bundle')); },
  });
  const req = () => new FakeRequest(ORIGIN + '/build/assets/main-abc123.js');

  assert.strictEqual(await body(await routeFetch(handlers, req())), 'bundle');
  assert.strictEqual(hits, 1);

  assert.strictEqual(await body(await routeFetch(handlers, req())), 'bundle');
  assert.strictEqual(hits, 1, 'second request must not touch the network');
});

test('a cold boot with no network falls back to the cached shell', async () => {
  let online = true;
  const { handlers } = loadSW({
    fetchImpl: () =>
      online ? Promise.resolve(new Response('<!doctype html>shell'))
             : Promise.reject(new Error('offline')),
  });
  const nav = () => new FakeRequest(ORIGIN + '/player', { mode: 'navigate' });

  // First boot online primes the cache.
  assert.strictEqual(await body(await routeFetch(handlers, nav())), '<!doctype html>shell');

  online = false;
  const offline = await routeFetch(handlers, nav());
  assert.strictEqual(offline.status, 200);
  assert.strictEqual(await body(offline), '<!doctype html>shell');
});

test('a cold boot with no network and no cache fails honestly', async () => {
  const { handlers } = loadSW();
  const res = await routeFetch(
    handlers,
    new FakeRequest(ORIGIN + '/player', { mode: 'navigate' }),
  );
  assert.strictEqual(res.status, 503);
});
