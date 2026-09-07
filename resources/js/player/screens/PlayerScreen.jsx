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

export default function PlayerScreen({ apiUrl, token, syncData }) {
  const videoRef = useRef(null);
  const interruptRef = useRef(false);
  const imageTimerRef = useRef(null);
  const recordedRef = useRef(null); // guards against double-recording one play

  const [syncState, setSyncState] = useState(syncData);
  const device = syncState?.device || {};
  const { isOnline } = useConnectionStatus({ apiUrl, token });

  // Paused (a.k.a. "frozen") holds the current frame on screen without
  // advancing the loop. Seeded from the server's persisted is_frozen so a
  // cold-booted board comes up paused if it was paused. While paused the loop
  // is disabled (see `enabled` below) and background reconcile is suspended, so
  // resume is instant from the in-memory schedule with no refetch flash.
  const [paused, setPaused] = useState(!!syncData?.device?.is_frozen);
  const pausedRef = useRef(paused);
  useEffect(() => { pausedRef.current = paused; }, [paused]);

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
    if (data?.device) setPaused(!!data.device.is_frozen);
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
      clearTimeout(imageTimerRef.current);
      if (videoRef.current) videoRef.current.pause();
      setPaused(true);
      setSyncState((s) => (s?.device ? { ...s, device: { ...s.device, is_frozen: true } } : s));
      return;
    }
    if (command === 'unfreeze' || command === 'resume') {
      setPaused(false); // re-enables the loop; it resumes from the in-memory schedule
      setSyncState((s) => (s?.device ? { ...s, device: { ...s.device, is_frozen: false } } : s));
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
    clearTimeout(imageTimerRef.current);
    if (videoRef.current) videoRef.current.pause();
    interrupt(); // cut mid-loop immediately
    refresh();
  }, [interrupt, refresh, cancelOverride, assetsById, injectOverride]);

  usePusher({ deviceId: device.id, onCommand: handleCommand });

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

  const { src, resolvedKey, notCached, downloading } = useEdgeCache(stableKey, fetchUrl, device.offline_mode, token);

  const isImage = currentAsset && IMAGE_TYPES.has(currentAsset.file_type);
  const [imageLoaded, setImageLoaded] = useState(false);

  // We only want to increment the video element's key (which forces a remount/restart)
  // when the actual blob src is ready for the CURRENT play sequence. Otherwise,
  // incrementing the key while the src is still pointing to the OLD asset causes
  // the player to flicker and restart the old asset.
  const [renderedPlayId, setRenderedPlayId] = useState(playId);
  useEffect(() => {
    if (resolvedKey === stableKey) {
      setRenderedPlayId(playId);
    }
  }, [resolvedKey, stableKey, playId]);

  // Reset loaded state whenever the image src changes
  useEffect(() => {
    setImageLoaded(false);
    return () => clearTimeout(imageTimerRef.current);
  }, [src]);

  // Record the play exactly once for the current asset instance (video onPlay
  // can fire again on resume; images only fire onLoad once).
  function meterPlay() {
    if (!currentAsset || recordedRef.current === currentAsset) return;
    recordedRef.current = currentAsset;
    recordPlay(currentAsset);          // meter the spot locally + queue the log
    onVideoPlay(currentAsset.asset_id);
    startMutation.mutate(currentAsset.asset_id);
  }

  function handleImageLoad() {
    if (!currentAsset) return;
    setImageLoaded(true);
    if (pausedRef.current) return; // hold the frame; don't meter or arm an advance
    meterPlay();
    const ms = (currentAsset.duration_secs || 5) * 1000;
    imageTimerRef.current = setTimeout(onVideoEnded, ms);
  }

  function handleVideoPlay() {
    meterPlay();
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
          onEnded={onVideoEnded}
          onError={onVideoError}
        />
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
