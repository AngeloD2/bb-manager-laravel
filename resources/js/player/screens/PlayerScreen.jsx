import { useRef, useCallback, useEffect, useState, useMemo } from 'react';
import { useMutation } from '@tanstack/react-query';
import { usePusher } from '../hooks/usePusher';
import { usePlaybackLoop } from '../hooks/usePlaybackLoop';
import { useEdgeCache, usePrefetchAssets } from '../hooks/useEdgeCache';
import { useConnectionStatus } from '../hooks/useConnectionStatus';
import { useLocalScheduler } from '../hooks/useLocalScheduler';
import { useSyncEngine } from '../hooks/useSyncEngine';
import { reportStart, assetServeUrl } from '../api';
import { persistSession } from '../lib/session';

const IMAGE_TYPES = new Set(['GIF', 'PHOTO']);

// Slack on top of a video's own runtime before the watchdog calls it stalled,
// so ordinary buffering jitter never cuts a video short.
const VIDEO_STALL_GRACE_MS = 2000;

// How long past its own duration an image may spend not loading before the
// loop gives up on it and moves on.
const IMAGE_LOAD_GRACE_MS = 3000;

export default function PlayerScreen({ apiUrl, token, syncData }) {
  const videoRef = useRef(null);
  const interruptRef = useRef(false);
  const advanceTimerRef = useRef(null);
  const recordedRef = useRef(null); // guards against double-recording one play

  const [syncState, setSyncState] = useState(syncData);
  const billboard = syncState?.billboard || {};
  const { isOnline } = useConnectionStatus({ apiUrl, token });

  // Paused (a.k.a. "frozen") holds the current frame on screen without
  // advancing the loop. Seeded from the server's persisted is_frozen so a
  // cold-booted board comes up paused if it was paused. While paused the loop
  // is disabled (see `enabled` below) and background reconcile is suspended, so
  // resume is instant from the in-memory schedule with no refetch flash.
  const [paused, setPaused] = useState(!!syncData?.billboard?.is_frozen);
  const pausedRef = useRef(paused);
  useEffect(() => { pausedRef.current = paused; }, [paused]);

  // Blacking out is a hold like freezing -- the loop stops either way -- but it
  // shows nothing, and there is no frame left to continue from, so resuming
  // replays the held asset from its start instead of picking it up mid-way.
  const [blackedOut, setBlackedOut] = useState(!!syncData?.billboard?.is_blacked_out);

  // Set when the next resume must restart the held asset rather than continue
  // it. Only a blackout sets it; a freeze leaves the frame on screen.
  const restartOnResumeRef = useRef(false);

  // An image advances on a timer, so continuing one mid-duration means knowing
  // how much of it was left when the hold landed. A video needs none of this:
  // the element keeps its own currentTime.
  const imageEndsAtRef = useRef(null);
  const imageRemainingRef = useRef(null);

  // Stop the pending image advance and remember what was left of it.
  function holdImageTimer() {
    clearTimeout(advanceTimerRef.current);
    if (imageEndsAtRef.current != null) {
      imageRemainingRef.current = Math.max(0, imageEndsAtRef.current - Date.now());
    }
  }

  const startMutation = useMutation({
    mutationFn: (assetId) => reportStart(apiUrl, token, assetId),
  });

  // Combined media manifest (primary + fallback), indexed for the scheduler and
  // used to drive prefetching.
  const allAssets = useMemo(
    () => [
      ...(syncState?.eligible_assets || []),
      ...(syncState?.fallback_assets || []),
      ...(syncState?.standalone_assets || []),
    ],
    [syncState],
  );

  const assetsById = useMemo(() => {
    const map = new Map();
    for (const a of allAssets) if (a?.id) map.set(a.id, a);
    return map;
  }, [allAssets]);

  // Download every asset in the loop (primary + fallback) before playing
  // anything. usePlaybackLoop stays disabled until prefetch reports ready.
  const prefetchAssets = useMemo(() => {
    return allAssets
      .filter((a) => a?.id)
      .map((a) => ({
        stableKey: assetServeUrl(apiUrl, a.id),
        fetchUrl: a.download_url || assetServeUrl(apiUrl, a.id),
      }));
  }, [allAssets, apiUrl]);

  const { ready: assetsReady, progress } = usePrefetchAssets(prefetchAssets, token);

  // Local playback brain: picks the next asset offline and meters spots locally.
  const { pickNext, recordPlay, dropSynced, injectOverride, cancelOverride } = useLocalScheduler({
    schedule: syncState?.schedule,
    quota: syncState?.quota,
    assetsById,
  });

  const getNextAsset = useCallback(() => pickNext(), [pickNext]);

  const { currentAsset, noAsset, onVideoPlay, onVideoEnded, onVideoError, interrupt, playId } =
    usePlaybackLoop({ interruptRef, enabled: assetsReady && !paused, getNextAsset });

  // Server is authoritative: a fresh /sync snapshot replaces local state and
  // re-seeds the scheduler (which re-applies still-unsynced plays).
  // Any pending_overrides in the snapshot are injected into the scheduler's
  // priority queue BEFORE the state update so the next pickNext() sees them.
  const onReconcile = useCallback((data) => {
    const overrides = data?.pending_overrides || [];
    overrides.forEach((o) => {
      if (!o?.asset) return;
      const a = o.asset;
      injectOverride({
        override_id:   o.id,
        asset_id:      a.id,
        asset_name:    a.name,
        file_type:     a.file_type,
        duration_secs: a.duration_secs,
        loop_id:       a.loop_id ?? null,
        download_url:  a.download_url ?? null,
        is_override:   true,
      });
    });
    // The server's persisted freeze flag is authoritative on a fresh snapshot
    // (covers cold boot and a freeze toggled from another admin client).
    if (data?.billboard) setPaused(!!data.billboard.is_frozen);
    setSyncState(data);
  }, [injectOverride]);

  // Persist the session (token + latest snapshot) so the board can cold-boot
  // offline and resume playing without re-login. Covers the initial load and
  // every reconcile.
  useEffect(() => {
    persistSession({ apiUrl, token, syncData: syncState });
  }, [apiUrl, token, syncState]);

  const { refresh } = useSyncEngine({
    apiUrl, token, isOnline, paused, dropSynced, onReconcile,
  });

  const handleCommand = useCallback((command, payload) => {
    // ── Pause / resume ────────────────────────────────────────────────────
    // 'freeze'/'pause' hold the current frame: stop the video and any image
    // timer, mark paused (which disables the loop), and crucially do NOT
    // interrupt() or refresh() — interrupting would advance to the next asset
    // (the old "black flash then keeps playing" bug) and refreshing would pull
    // the frozen, empty schedule and lose the in-memory queue needed for an
    // instant resume.
    if (command === 'freeze' || command === 'pause') {
      holdImageTimer();
      if (videoRef.current) videoRef.current.pause();
      restartOnResumeRef.current = false; // the frame stays up; continue from it
      setPaused(true);
      setSyncState((s) => (s?.billboard ? { ...s, billboard: { ...s.billboard, is_frozen: true } } : s));
      return;
    }
    // Same hold as a freeze, but the panel goes dark and the held asset is
    // replayed in full when it comes back.
    if (command === 'blackout') {
      holdImageTimer();
      if (videoRef.current) videoRef.current.pause();
      restartOnResumeRef.current = true;
      setBlackedOut(true);
      setPaused(true);
      setSyncState((s) => (s?.billboard ? { ...s, billboard: { ...s.billboard, is_frozen: true, is_blacked_out: true } } : s));
      return;
    }
    if (command === 'unblackout') {
      setBlackedOut(false);
      setPaused(false);
      setSyncState((s) => (s?.billboard ? { ...s, billboard: { ...s.billboard, is_frozen: false, is_blacked_out: false } } : s));
      refresh();
      return;
    }
    if (command === 'unfreeze' || command === 'resume') {
      setPaused(false); // re-enables the loop; it resumes from the in-memory schedule
      setSyncState((s) => (s?.billboard ? { ...s, billboard: { ...s.billboard, is_frozen: false } } : s));
      refresh();        // reconcile counters + repopulate the (previously frozen) schedule
      return;
    }

    // ── Loop-cutting commands (override / cancel / sync) ──────────────────
    // Only these tear down the current asset and re-fetch. Any other/unknown
    // command is ignored so it can never silently advance playback.
    if (command !== 'override' && command !== 'override_cancelled' && command !== 'sync') {
      return;
    }

    if (command === 'override' && payload && payload.asset_id) {
       const a = assetsById.get(payload.asset_id);
       if (a) {
          injectOverride({
             override_id:   payload.override_id,
             asset_id:      a.id,
             asset_name:    a.name,
             file_type:     a.file_type,
             duration_secs: a.duration_secs,
             loop_id:       a.loop_id ?? null,
             download_url:  a.download_url ?? null,
             is_override:   true,
          });
       } else if (payload.file_type) {
          // Fallback to payload details if asset is not yet in syncState
          injectOverride({
             override_id:   payload.override_id,
             asset_id:      payload.asset_id,
             asset_name:    payload.asset_name,
             file_type:     payload.file_type,
             duration_secs: payload.duration_secs,
             loop_id:       payload.loop_id ?? null,
             download_url:  payload.download_url ?? null,
             is_override:   true,
          });
       }
       refresh();
       return;
    }

    if (command === 'override_cancelled') {
      cancelOverride();
      refresh();
      return;
    }

    // For 'sync' and other loop-cutting commands:
    interruptRef.current = true;
    clearTimeout(advanceTimerRef.current);
    if (videoRef.current) videoRef.current.pause();
    interrupt(); // cut mid-loop immediately
    refresh();
  }, [interrupt, refresh, cancelOverride, assetsById, injectOverride]);

  usePusher({ billboardId: billboard.id, onCommand: handleCommand });

  const stableKey = currentAsset && currentAsset.asset_id
    ? assetServeUrl(apiUrl, currentAsset.asset_id)
    : null;

  const fetchUrl = useMemo(() => {
    if (!currentAsset) return null;
    const assetId = currentAsset.asset_id;
    if (!assetId) {
      return currentAsset.download_url || currentAsset.file_path || null;
    }
    const details =
      syncState?.eligible_assets?.find((a) => a.id === assetId) ||
      syncState?.fallback_assets?.find((a) => a.id === assetId) ||
      syncState?.standalone_assets?.find((a) => a.id === assetId);
    // Prefer the synced manifest's URL; otherwise honor the fresh download_url
    // carried on the asset itself (e.g. an override pushed over the WebSocket
    // before the reconcile /sync has landed) before falling back to the
    // authenticated proxy.
    return (
      details?.download_url ||
      currentAsset.download_url ||
      assetServeUrl(apiUrl, assetId)
    );
  }, [currentAsset, syncState, apiUrl]);

  const { src, resolvedKey, notCached, downloading } = useEdgeCache(stableKey, fetchUrl, billboard.offline_mode, token);

  const isImage = currentAsset && IMAGE_TYPES.has(currentAsset.file_type);

  // Tag the loaded flag with the src it was recorded against, so a new src
  // reads as not-yet-loaded by derivation instead of via a reset effect.
  const [loadedSrc, setLoadedSrc] = useState(null);
  const imageLoaded = src !== null && loadedSrc === src;

  // We only want to increment the video element's key (which forces a remount/restart)
  // when the actual blob src is ready for the CURRENT play sequence. Otherwise,
  // incrementing the key while the src is still pointing to the OLD asset causes
  // the player to flicker and restart the old asset. Adjusting the state during
  // render (React's documented alternative to a reset effect) re-runs this
  // component before anything is committed, so no extra frame is painted.
  const [renderedPlayId, setRenderedPlayId] = useState(playId);
  if (resolvedKey === stableKey && renderedPlayId !== playId) {
    setRenderedPlayId(playId);
  }

  // Arm the bound as soon as a video is on screen rather than only once it
  // starts playing: a video that never fires 'play' at all -- autoplay refused,
  // a source that stalls before the first frame -- is exactly the freeze this
  // guards against, and arming from the play handler alone would miss it.
  // handleVideoPlay re-arms with the element's real duration once playback
  // actually begins. Images arm from their own onLoad, so for them this only
  // drops the pending timer on src change or unmount.
  useEffect(() => {
    if (!src || paused) return () => clearTimeout(advanceTimerRef.current);

    // Coming off a hold, the asset is still the held one -- the loop no longer
    // advances on resume -- so this decides how it comes back:
    //   after a freeze   → continue where it stopped
    //   after a blackout → replay it in full, since no frame was left up
    const replay = restartOnResumeRef.current;
    restartOnResumeRef.current = false;

    if (isImage && !imageLoaded) {
      // Neither 'load' nor 'error' has fired yet. Bound the wait so a request
      // that simply stalls cannot hold the panel forever.
      advanceTimerRef.current = setTimeout(advance, ((currentAsset?.duration_secs || 5) * 1000) + IMAGE_LOAD_GRACE_MS);
      return () => clearTimeout(advanceTimerRef.current);
    }

    if (isImage) {
      // Nothing to restart for an image; it is already painted. Re-arm what was
      // left of its time, or the whole duration when replaying.
      const ms = replay || imageRemainingRef.current == null
        ? (currentAsset?.duration_secs || 5) * 1000
        : imageRemainingRef.current;
      imageRemainingRef.current = null;
      if (imageLoaded) {
        imageEndsAtRef.current = Date.now() + ms;
        advanceTimerRef.current = setTimeout(advance, ms);
      }
      return () => clearTimeout(advanceTimerRef.current);
    }

    // A freeze paused the element directly, and nothing on the resume path
    // starts it again. A paused video fires neither 'play' nor 'ended', so an
    // unfrozen board would sit on that frame -- the same freeze this watchdog
    // exists to prevent, reached from the other direction. Depending on
    // `paused` (the state, not the ref) is what makes this re-run on resume.
    const el = videoRef.current;
    if (el) {
      if (replay) el.currentTime = 0;
      if (el.paused) el.play().catch(() => {});
    }
    armVideoWatchdog();
    return () => clearTimeout(advanceTimerRef.current);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [src, isImage, paused, imageLoaded]);

  // Record the play exactly once for the current asset instance (video onPlay
  // can fire again on resume; images only fire onLoad once).
  function meterPlay() {
    if (!currentAsset || recordedRef.current === currentAsset) return;
    recordedRef.current = currentAsset;
    recordPlay(currentAsset);          // meter the spot locally + queue the log
    onVideoPlay(currentAsset.asset_id);
    startMutation.mutate(currentAsset.asset_id);
  }

  // Every advance funnels through here so the pending timer is always dropped
  // before the loop moves on, whether the media ended on its own or the
  // watchdog below had to force it.
  function advance() {
    clearTimeout(advanceTimerRef.current);
    if (pausedRef.current) return; // a frozen board holds its frame
    onVideoEnded();
  }

  // Video advance is driven by the element's 'ended' event, which never fires
  // if playback pauses or stalls WITHOUT erroring -- a backgrounded tab, a
  // stalled stream, a mid-playback decode hiccup. 'error' covers hard failures;
  // nothing covered a silent stall, so the board froze on that frame
  // indefinitely while /sync/ping kept reporting it healthy. Images have always
  // advanced on a timer; this gives video the same guarantee.
  //
  // The element's own duration is the truth when it is known; duration_secs is
  // the fallback for a stream whose length the browser cannot report yet.
  function armVideoWatchdog() {
    clearTimeout(advanceTimerRef.current);
    const el = videoRef.current;
    const natural = el && Number.isFinite(el.duration) && el.duration > 0 ? el.duration : null;
    const secs = natural || currentAsset?.duration_secs || 5;
    advanceTimerRef.current = setTimeout(advance, secs * 1000 + VIDEO_STALL_GRACE_MS);
  }

  function handleImageLoad() {
    if (!currentAsset) return;
    setLoadedSrc(src);
    if (pausedRef.current) return; // hold the frame; don't meter or arm an advance
    meterPlay();
    const ms = (currentAsset.duration_secs || 5) * 1000;
    imageEndsAtRef.current = Date.now() + ms;
    advanceTimerRef.current = setTimeout(advance, ms);
  }

  // 'play' fires again when a paused video resumes, so re-arm rather than
  // assume the first arming still describes the remaining runtime.
  function handleVideoPlay() {
    meterPlay();
    armVideoWatchdog();
  }

  function handleVideoError(e) {
    clearTimeout(advanceTimerRef.current);
    onVideoError(e);
  }

  // An image arms its advance from onLoad, so an image that never loads never
  // arms one -- and the board sits on a blank frame indefinitely. A 404 asset
  // (an object missing from the bucket) does exactly that: 'load' never fires,
  // and without this nothing else would move the loop on either.
  function handleImageError() {
    clearTimeout(advanceTimerRef.current);
    if (pausedRef.current) return;
    advanceTimerRef.current = setTimeout(advance, 1000);
  }

  // 'fill' stretches the media to the full display surface, ignoring its own
  // aspect ratio — the billboard wants edge-to-edge coverage, not letterboxing.
  const mediaStyle = { 
    width: '100%', height: '100%', objectFit: 'fill', display: 'block',
    transform: 'translateZ(0)', willChange: 'transform'
  };

  // Losing connectivity is a normal operating state for the player, not an error:
  // the scheduler picks the next asset locally and the edge cache serves it from
  // disk with no network. Only blank the screen when an asset is genuinely
  // unavailable (offline_mode cache miss). Connectivity is handled separately by
  // the sync engine (background reconcile/flush), which is gated on `isOnline`.
  const errorMessage = notCached
    ? 'Asset not in edge cache'
    : null;

  // Hold the screen while the full loop downloads up front, or if an
  // individual asset is still streaming in on demand.
  const preloading = !assetsReady;

  return (
    <div style={{ position: 'fixed', inset: 0, background: '#000', overflow: 'hidden' }}>
      {!errorMessage && (preloading || downloading) && (
        <DownloadingOverlay progress={preloading ? progress : null} />
      )}
      {errorMessage && <ErrorOverlay message={errorMessage} />}

      {!errorMessage && src && isImage && (
        <img
          key={`${resolvedKey}-${renderedPlayId}`}
          src={src}
          alt=""
          style={{ ...mediaStyle, visibility: imageLoaded ? 'visible' : 'hidden' }}
          onLoad={handleImageLoad}
          onError={handleImageError}
        />
      )}
      {!errorMessage && src && !isImage && (
        <video
          key={`${resolvedKey}-${renderedPlayId}`}
          ref={videoRef}
          src={src}
          autoPlay
          muted
          playsInline
          style={mediaStyle}
          onPlay={handleVideoPlay}
          onEnded={advance}
          onError={handleVideoError}
        />
      )}
      {/* A blackout hides the panel without unmounting the media, so the held
          asset is still there to replay when it comes back. */}
      {blackedOut && (
        <div style={{
          position: 'absolute', inset: 0, background: '#000', zIndex: 10,
        }} />
      )}

      {!errorMessage && !src && noAsset && (
        <div style={{
          display: 'flex', alignItems: 'center', justifyContent: 'center',
          height: '100%', color: '#333', fontSize: 16,
          fontFamily: 'system-ui, sans-serif', letterSpacing: '0.05em',
        }}>
          No media scheduled
        </div>
      )}
    </div>
  );
}

function DownloadingOverlay({ progress }) {
  const label = progress && progress.total > 0
    ? `Downloading assets... ${progress.done}/${progress.total}`
    : 'Downloading assets...';
  return (
    <div style={{
      position: 'absolute', inset: 0,
      display: 'flex', flexDirection: 'column',
      alignItems: 'center', justifyContent: 'center',
      background: '#0a0a0a',
      fontFamily: 'system-ui, sans-serif',
      userSelect: 'none',
    }}>
      <div style={{
        width: 36, height: 36, marginBottom: 20,
        border: '3px solid #333',
        borderTopColor: '#aaa',
        borderRadius: '50%',
        animation: 'spin 0.9s linear infinite',
      }} />
      <style>{`@keyframes spin { to { transform: rotate(360deg); } }`}</style>
      <div style={{ color: '#888', fontSize: 13, letterSpacing: '0.08em', textTransform: 'uppercase' }}>
        {label}
      </div>
    </div>
  );
}

function ErrorOverlay({ message }) {
  return (
    <div style={{
      position: 'absolute', inset: 0,
      display: 'flex', flexDirection: 'column',
      alignItems: 'center', justifyContent: 'center',
      background: '#0a0a0a',
      fontFamily: 'system-ui, sans-serif',
      userSelect: 'none',
    }}>
      <div style={{
        width: 48, height: 48, marginBottom: 20,
        borderRadius: '50%',
        border: '2px solid #c0392b',
        display: 'flex', alignItems: 'center', justifyContent: 'center',
        color: '#c0392b', fontSize: 24, fontWeight: 700, lineHeight: 1,
      }}>
        !
      </div>
      <div style={{ color: '#c0392b', fontSize: 13, letterSpacing: '0.08em', textTransform: 'uppercase' }}>
        {message}
      </div>
    </div>
  );
}
