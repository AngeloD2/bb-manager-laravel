import { useEffect, useMemo, useState } from "react";

const CACHE_NAME = "bcc-edge-cache-v1";
const CACHE_LIMIT = 3;

function shouldAuthenticate(url) {
  if (!url) return false;
  // S3/R2 presigned URLs are self-authenticating; sending our Laravel Sanctum
  // Bearer token will cause S3/R2 to reject the request with a 403 Forbidden.
  if (
    url.includes("X-Amz-Signature") ||
    url.includes("Signature=") ||
    url.includes("cloudflarestorage.com") ||
    url.includes("amazonaws.com")
  ) {
    return false;
  }
  return url.includes("/api/");
}

// Module-level cache: stable URL → blob object URL.
// Bounded to CACHE_LIMIT to prevent massive memory leaks.
const blobCache = new Map();

function getCachedObjectUrl(url, blob) {
  if (blobCache.has(url)) {
    // move to end to mark as recently used
    const objUrl = blobCache.get(url);
    blobCache.delete(url);
    blobCache.set(url, objUrl);
    return objUrl;
  }

  if (blobCache.size >= CACHE_LIMIT) {
    const firstKey = blobCache.keys().next().value;
    URL.revokeObjectURL(blobCache.get(firstKey));
    blobCache.delete(firstKey);
  }

  const newObjUrl = URL.createObjectURL(blob);
  blobCache.set(url, newObjUrl);
  return newObjUrl;
}

/**
 * Fetches an asset URL with Sanctum auth and returns a blob: object URL.
 *
 * On the first play the asset is fetched from the network (via the stable
 * Laravel proxy which 302-redirects to S3), stored in the Browser Cache API
 * for persistence across reloads, and memoised in blobCache for the session.
 *
 * On subsequent plays of the same asset blobCache returns the blob URL
 * synchronously — zero network, zero disk access.
 *
 * Returns { src, notCached, downloading }:
 *   src         – blob: URL to render, or null while loading
 *   notCached   – true only in offline_mode when the asset is unavailable
 *   downloading – true while the initial network fetch is in progress
 */

/**
 * Helper to execute a fetch request and process the response body,
 * with exponential backoff and timeout to handle shoddy internet connections.
 */
async function fetchWithRetryBlock(url, headers, processResponseCallback, maxRetries = 3) {
  for (let i = 0; i < maxRetries; i++) {
    try {
      const controller = new AbortController();
      // 5 min timeout for the entire download to prevent indefinite hangs
      const timeoutId = setTimeout(() => controller.abort(), 5 * 60 * 1000);

      const res = await fetch(url, { headers, signal: controller.signal });
      if (!res.ok) {
        clearTimeout(timeoutId);
        // Do not retry 4xx errors (except 408 Request Timeout or 429 Too Many Requests)
        if (res.status >= 400 && res.status < 500 && res.status !== 408 && res.status !== 429) {
          throw new Error(`HTTP ${res.status}`);
        }
        if (i === maxRetries - 1) throw new Error(`HTTP ${res.status}`);
        throw new Error(`Retrying HTTP ${res.status}`);
      }

      const result = await processResponseCallback(res);
      
      clearTimeout(timeoutId);
      return result;
    } catch (err) {
      if (i === maxRetries - 1) throw err;
      console.warn(`[useEdgeCache] download failed, retrying (${i + 1}/${maxRetries}) for ${url}`, err);
      await new Promise((resolve) => setTimeout(resolve, 2000 * Math.pow(2, i)));
    }
  }
}

/**
 * Ordered fetch attempts for one asset: the (possibly presigned) fetchUrl
 * first, then the stable authenticated proxy as a recovery path. The proxy
 * (`/api/.../assets/{id}/serve`) mints a fresh presigned URL server-side on
 * every request, so it recovers when the baked-in presigned URL has expired or
 * points at an object that was still being processed when the manifest was
 * built. The bearer token is attached ONLY to URLs that need it (never to a
 * self-authenticating S3/R2 URL, which would make S3/R2 reject the request).
 */
async function fetchAssetBlock(stableKey, fetchUrl, token, processResponseCallback) {
  const primary = fetchUrl || stableKey;
  const candidates =
    stableKey && stableKey !== primary ? [primary, stableKey] : [primary];

  let lastErr;
  for (const url of candidates) {
    const headers =
      token && shouldAuthenticate(url) ? { Authorization: `Bearer ${token}` } : {};
    try {
      return await fetchWithRetryBlock(url, headers, processResponseCallback);
    } catch (err) {
      lastErr = err;
    }
  }
  throw lastErr;
}

/**
 * Downloads a single asset URL and primes blobCache (and the Cache API when
 * available) so a later useEdgeCache(url) call resolves synchronously. Safe to
 * call repeatedly — already-cached URLs are skipped.
 */
export async function prefetchAsset(stableKey, fetchUrl, token) {
  if (!stableKey || blobCache.has(stableKey)) return;

  const cacheApi = typeof caches !== "undefined" ? caches : null;

  if (cacheApi) {
    const cache = await cacheApi.open(CACHE_NAME);
    let match = await cache.match(stableKey);
    if (!match) {
      await fetchAssetBlock(stableKey, fetchUrl, token, async (res) => {
        await cache.put(stableKey, res.clone());
      });
    }
  } else {
    // No Cache API (plain HTTP LAN). Fetching it warms the browser's native memory cache.
    // We do NOT create ObjectURLs here to prevent RAM exhaustion.
    await fetchAssetBlock(stableKey, fetchUrl, token, async (res) => {
      await res.blob();
    });
  }
}

/**
 * Downloads every item in `items` before reporting ready.
 * Each item can be a string (backward compatible) or an object: { stableKey, fetchUrl }.
 */
export function usePrefetchAssets(items, token) {
  // Both pieces of state are tagged with the batch `key` they belong to, so a
  // new batch invalidates them during render. Resetting them from the effect
  // instead would cost an extra render pass on every loop change.
  const [readyKey, setReadyKey] = useState(null);
  const [progressState, setProgressState] = useState({ key: null, done: 0 });

  const normalizedItems = useMemo(() => {
    return (items || []).filter(Boolean).map((item) => {
      if (typeof item === "string") {
        return { stableKey: item, fetchUrl: item };
      }
      return item;
    });
  }, [items]);

  const key = normalizedItems.map((i) => i.stableKey).join("|");
  const total = normalizedItems.length;

  // `key` already describes the batch exactly, so the effect depends on this
  // re-tagged snapshot rather than on `normalizedItems` directly: a new-but-
  // equivalent array from the caller must not restart the downloads mid-flight.
  const [batch, setBatch] = useState({ key, items: normalizedItems });
  if (batch.key !== key) {
    setBatch({ key, items: normalizedItems });
  }

  useEffect(() => {
    if (batch.items.length === 0) return undefined;

    let cancelled = false;
    let done = 0;

    Promise.all(
      batch.items.map(async (item) => {
        try {
          await prefetchAsset(item.stableKey, item.fetchUrl, token);
        } catch (err) {
          console.error(
            "[usePrefetchAssets] failed to prefetch",
            item.stableKey,
            err,
          );
        } finally {
          done += 1;
          if (!cancelled) setProgressState({ key: batch.key, done });
        }
      }),
    ).then(() => {
      if (!cancelled) setReadyKey(batch.key);
    });

    return () => {
      cancelled = true;
    };
  }, [batch, token]);

  const progress = useMemo(
    () => ({ done: progressState.key === key ? progressState.done : 0, total }),
    [progressState, key, total],
  );

  return { ready: total === 0 || readyKey === key, progress };
}

export function useEdgeCache(stableKey, fetchUrl, offlineMode, token) {
  // `resolved` holds the last asset we actually resolved, tagged with the key
  // it belongs to. It is deliberately NOT cleared when stableKey changes:
  // holding the previous frame while the next blob loads is what prevents a
  // black flash between assets. Callers compare resolvedKey against stableKey
  // to tell whether src matches the asset they asked for. `downloadingKey` and
  // `notCachedKey` are tagged the same way so a key change clears them during
  // render rather than through a reset effect.
  const [resolved, setResolved] = useState(null);
  const [downloadingKey, setDownloadingKey] = useState(null);
  const [notCachedKey, setNotCachedKey] = useState(null);

  const actualFetchUrl = fetchUrl || stableKey;

  useEffect(() => {
    // Nothing to play, or already resolved this session and served straight
    // out of blobCache by the derivation below — either way there is no state
    // to push from here.
    if (!stableKey || blobCache.has(stableKey)) return undefined;

    let isMounted = true;

    // The Cache API only exists in secure contexts (HTTPS or localhost).
    // Over a plain-http LAN address it is undefined — fall back to a direct
    // authenticated blob fetch so playback still works off the billboard's IP.
    const cacheApi = typeof caches !== "undefined" ? caches : null;

    async function load() {
      try {
        if (!cacheApi) {
          if (isMounted) setDownloadingKey(stableKey);
          const blob = await fetchAssetBlock(stableKey, actualFetchUrl, token, async (res) => {
            return await res.blob();
          });
          const objectUrl = getCachedObjectUrl(stableKey, blob);
          if (isMounted) {
            setResolved({ key: stableKey, src: objectUrl });
            setNotCachedKey(null);
            setDownloadingKey(null);
          }
          return;
        }

        const cache = await cacheApi.open(CACHE_NAME);
        const match = await cache.match(stableKey);

        if (match) {
          const objectUrl = getCachedObjectUrl(stableKey, await match.blob());
          if (isMounted) {
            setResolved({ key: stableKey, src: objectUrl });
            setNotCachedKey(null);
            setDownloadingKey(null);
          }
          return;
        }

        if (isMounted) setDownloadingKey(stableKey);

        const blob = await fetchAssetBlock(stableKey, actualFetchUrl, token, async (res) => {
          // We clone to put into cache, and also return a blob to use immediately.
          const [blob] = await Promise.all([
            res.clone().blob(),
            cache.put(stableKey, res)
          ]);
          return blob;
        });

        const objectUrl = getCachedObjectUrl(stableKey, blob);
        if (isMounted) {
          setResolved({ key: stableKey, src: objectUrl });
          setNotCachedKey(null);
          setDownloadingKey(null);
        }
      } catch (err) {
        console.error("[useEdgeCache] failed to load asset", stableKey, err);
        if (isMounted) {
          setDownloadingKey(null);
          if (offlineMode) {
            setNotCachedKey(stableKey);
          } else if (shouldAuthenticate(actualFetchUrl)) {
            // actualFetchUrl is a token-required proxy URL. A native <video>/<img>
            // element cannot send the bearer token, so assigning it as the raw src
            // would force an unauthenticated request → 401. We already tried
            // fetching it authenticated (with the proxy fallback) above, so don't
            // render a guaranteed-401 request — clear the src and let the next
            // reconcile/scheduler tick pick the asset back up.
            setResolved({ key: stableKey, src: null });
          } else {
            // Self-authenticating (presigned) URL — safe to let the browser load
            // it natively as a last resort (e.g. fetch blocked by CORS but the
            // native element can still play it).
            setResolved({ key: stableKey, src: actualFetchUrl });
          }
        }
      }
    }

    load();

    return () => {
      isMounted = false;
    };
  }, [stableKey, actualFetchUrl, offlineMode, token]);

  // Everything the caller sees is derived, so stale state from a previous
  // asset can never be reported against the current stableKey.
  const cachedSrc =
    stableKey && blobCache.has(stableKey) ? blobCache.get(stableKey) : null;

  let src = null;
  let resolvedKey = null;
  if (stableKey) {
    if (cachedSrc) {
      src = cachedSrc;
      resolvedKey = stableKey;
    } else if (resolved) {
      // Possibly the PREVIOUS asset — that is the point; see `resolved` above.
      src = resolved.src;
      resolvedKey = resolved.key;
    }
  }

  return {
    src,
    resolvedKey,
    notCached: !!stableKey && notCachedKey === stableKey,
    downloading: !!stableKey && downloadingKey === stableKey,
  };
}
