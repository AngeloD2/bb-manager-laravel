// App-shell cache for the billboard player.
//
// The player already survives a dead network once it is RUNNING: lib/db.js keeps
// the session, schedule and quota in IndexedDB, and useEdgeCache keeps the media
// in the Cache API. The gap this closes is COLD BOOT — until now, a board that
// power-cycled while the backend was unreachable could not load the HTML and JS
// needed to get to that offline logic, so it sat on a dead page with a perfectly
// good schedule on disk.
//
// Scope is "/" (served from the web root), which covers both /player and the
// hashed /build assets it pulls in.

const CACHE_PREFIX = 'bcc-player-shell-';
const CACHE = CACHE_PREFIX + 'v1';
const SHELL_URL = '/player';

// How long a cold boot waits on the network before falling back to the cached
// shell. A board on a wedged link must not hang on a white screen, but when the
// network is healthy we do want the newest deploy rather than a stale one.
const NETWORK_TIMEOUT_MS = 3000;

self.addEventListener('install', (event) => {
  // Pre-seed the shell so the very first power-cycle after install is covered
  // even if that boot happens offline.
  event.waitUntil(
    caches
      .open(CACHE)
      .then((cache) => cache.add(new Request(SHELL_URL, { cache: 'reload' })))
      .catch(() => {})
      .then(() => self.skipWaiting()),
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      // Drop only OUR superseded shell caches. useEdgeCache owns
      // "bcc-edge-cache-v1" and it holds the media a board needs to keep playing
      // offline — deleting it here would be exactly the outage this file exists
      // to prevent.
      const names = await caches.keys();
      await Promise.all(
        names
          .filter((n) => n.startsWith(CACHE_PREFIX) && n !== CACHE)
          .map((n) => caches.delete(n)),
      );
      await self.clients.claim();
    })(),
  );
});

// Never resolves to a rejection: a failed fetch becomes null so callers can fall
// through to the cache without an unhandled rejection.
function tryFetch(request, cache, cacheKey) {
  return fetch(request)
    .then((res) => {
      if (res && res.status === 200 && cache && cacheKey) {
        cache.put(cacheKey, res.clone()).catch(() => {});
      }
      return res;
    })
    .catch(() => null);
}

// Cold boot: prefer the network (so deploys land), but never let a slow or dead
// link outlast NETWORK_TIMEOUT_MS before we serve the cached shell.
async function shellNetworkFirst(request) {
  const cache = await caches.open(CACHE);
  const network = tryFetch(request, cache, SHELL_URL);

  let timer;
  const timeout = new Promise((resolve) => {
    timer = setTimeout(() => resolve(null), NETWORK_TIMEOUT_MS);
  });

  const raced = await Promise.race([network, timeout]);
  clearTimeout(timer);
  if (raced) return raced;

  const cached = await cache.match(SHELL_URL);
  if (cached) return cached;

  // Nothing cached and the network is slow — wait it out rather than failing a
  // boot the network might still complete.
  return (
    (await network) ||
    new Response('Player offline and no cached shell available.', {
      status: 503,
      headers: { 'Content-Type': 'text/plain' },
    })
  );
}

// Vite fingerprints these filenames, so a cached hit can never be stale: a new
// build means a new URL.
async function cacheFirst(request) {
  const cache = await caches.open(CACHE);
  const hit = await cache.match(request);
  if (hit) return hit;

  const res = await tryFetch(request, cache, request);
  return res || Response.error();
}

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;

  let url;
  try {
    url = new URL(request.url);
  } catch {
    return;
  }

  // Cross-origin (S3/R2 media, Reverb websockets) — not ours to manage.
  if (url.origin !== self.location.origin) return;

  // The API is network-only, always. Two reasons, both load-bearing:
  //   • useConnectionStatus decides the board is online by probing /sync/ping.
  //     Answering that from a cache would make a board with a dead backend
  //     believe it is online and stop queueing plays for later reconcile.
  //   • /sync carries the quota snapshot the server bills against, and
  //     /assets/{id}/serve mints a short-lived presigned URL per request.
  //     Neither is safe to replay from a cache.
  if (url.pathname.startsWith('/api/')) return;

  if (request.mode === 'navigate') {
    event.respondWith(shellNetworkFirst(request));
    return;
  }

  if (url.pathname.startsWith('/build/')) {
    event.respondWith(cacheFirst(request));
    return;
  }

  // Small static extras (favicon.svg, icons.svg).
  event.respondWith(cacheFirst(request));
});
