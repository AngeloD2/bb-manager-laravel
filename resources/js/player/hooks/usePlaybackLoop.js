import { useState, useRef, useCallback, useEffect } from 'react';
import { useMutation } from '@tanstack/react-query';

// `getNextAsset` is the source of the next item to play. It used to be a network
// call to /playback/next; it is now the local scheduler (synchronous, offline,
// no transition flash). It may return the asset directly or a promise of it.
export function usePlaybackLoop({ interruptRef, enabled = true, getNextAsset }) {
  const [currentAsset, setCurrentAsset] = useState(null);
  // True only when the backend explicitly reports no asset to play. A plain
  // null currentAsset is ambiguous (it also covers the brief gap while the next
  // asset is being fetched), so we track the confirmed-empty case separately to
  // avoid flashing the empty-state UI between two already-downloaded assets.
  const [noAsset, setNoAsset] = useState(false);
  const [playId, setPlayId] = useState(0);
  const fetchingRef = useRef(false);
  const timeoutRef = useRef(null);
  // Monotonic token identifying the most recent fetch request. Every fetch
  // captures the value at dispatch time; a resolved fetch whose token is no
  // longer current is stale and must be discarded. This is what prevents a
  // naturally-ending asset's in-flight "next" fetch from clobbering (or
  // sneaking in ahead of) an override that interrupted right after it — the
  // race that let the next asset flash before the override and then cut it off.
  const genRef = useRef(0);

  // While disabled (assets still downloading) every fetch is a no-op; the
  // enabled effect below kicks off the loop once the assets are cached.
  const enabledRef = useRef(enabled);
  useEffect(() => { enabledRef.current = enabled; }, [enabled]);

  const fetchNextRef = useRef(null);

  const { mutate: triggerFetch, reset: resetFetch } = useMutation({
    mutationFn: async (gen) => ({ gen, asset: await getNextAsset() }),
    onSuccess: ({ gen, asset }) => {
      // Discard a result that a newer fetch (e.g. an override interrupt) has
      // already superseded — applying it would resurrect a stale asset.
      if (gen !== genRef.current) return;
      setCurrentAsset(asset);
      setNoAsset(!asset);
      if (!asset) {
        timeoutRef.current = setTimeout(() => {
          fetchingRef.current = false;
          fetchNextRef.current?.();
        }, 60000); // 60 seconds instead of 3s; WebSockets will interrupt this if media is assigned
      } else {
        setPlayId((id) => id + 1);
        fetchingRef.current = false;
      }
    },
    onError: (_err, gen) => {
      if (gen !== genRef.current) return;
      timeoutRef.current = setTimeout(() => {
        fetchingRef.current = false;
        fetchNextRef.current?.();
      }, 5000);
    },
  });

  const fetchNext = useCallback(() => {
    if (!enabledRef.current) return;
    if (fetchingRef.current) return;
    fetchingRef.current = true;
    interruptRef.current = false;
    setNoAsset(false);
    const myGen = ++genRef.current; // newest fetch wins; older in-flight ones are discarded
    triggerFetch(myGen);
  }, [triggerFetch]);

  // Keep a ref to the latest fetchNext so onSuccess/onError closures always call the current version
  useEffect(() => {
    fetchNextRef.current = fetchNext;
  }, [fetchNext]);

  // Start (or resume) the loop only once assets are downloaded. When `enabled`
  // flips back to true after a re-prefetch, this restarts playback.
  useEffect(() => {
    if (enabled) fetchNext();
    return () => clearTimeout(timeoutRef.current);
  }, [enabled, fetchNext]);

  // Retained for API compatibility; play-time accounting now happens via
  // recordPlay in PlayerScreen. Kept as a hook for the video/image onPlay events.
  const onVideoPlay = useCallback(() => {}, []);

  const onVideoEnded = useCallback(() => {
    // While disabled (assets downloading) or paused, hold the current frame
    // instead of advancing — a late natural-end firing right as a pause lands
    // must not blank the screen or sneak the loop forward.
    if (!enabledRef.current) return;
    // The play was already logged to the local queue on play start (see
    // recordPlay in PlayerScreen); here we only advance the loop.
    setCurrentAsset(null);
    fetchingRef.current = false;
    fetchNext();
  }, [fetchNext]);

  const onVideoError = useCallback(() => {
    setCurrentAsset(null);
    timeoutRef.current = setTimeout(() => {
      fetchingRef.current = false;
      fetchNext();
    }, 1000);
  }, [fetchNext]);

  const interrupt = useCallback(() => {
    clearTimeout(timeoutRef.current);
    resetFetch();
    setCurrentAsset(null);
    fetchingRef.current = false;
    fetchNext();
  }, [fetchNext, resetFetch]);

  return { currentAsset, noAsset, onVideoPlay, onVideoEnded, onVideoError, interrupt, playId };
}
