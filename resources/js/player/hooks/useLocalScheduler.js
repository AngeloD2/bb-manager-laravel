import { useRef, useEffect, useCallback, useState } from "react";
import { Scheduler } from "../lib/scheduler";
import { queueAdd, queueUnsynced, addRejection } from "../lib/db";
import { loadHistory, persistHistory } from "../lib/session";

// React wrapper around the pure Scheduler. Owns the live scheduler instance and
// a mirror of the unsynced play queue, persists every play to IndexedDB, and
// re-seeds from the server snapshot whenever a reconciling /sync lands (while
// keeping not-yet-flushed local plays applied).
export function useLocalScheduler({ schedule, quota, assetsById }) {
  const schedulerRef = useRef(null);
  const pendingRef = useRef([]); // mirror of IndexedDB log_queue where synced=false
  const [historyReady, setHistoryReady] = useState(false);

  if (schedulerRef.current === null) {
    // pendingRef is empty on first render; persisted events are merged in by the
    // hydrate effect below.
    schedulerRef.current = new Scheduler({
      schedule,
      quota,
      assetsById,
      pendingEvents: [],
      history: [],
      onReject: (assetId, reason) => {
        addRejection(assetId, reason);
      },
    });
  }

  // Hydrate persisted unsynced events on mount (cold-boot may have a backlog),
  // merging rather than replacing so plays recorded before this resolves survive.
  useEffect(() => {
    let active = true;
    Promise.all([queueUnsynced(), loadHistory()]).then(([events, history]) => {
      if (!active) return;
      const seen = new Set(pendingRef.current.map((e) => e.client_event_id));
      const merged = [...pendingRef.current];
      for (const e of events) {
        if (!seen.has(e.client_event_id)) merged.push(e);
      }
      pendingRef.current = merged;
      schedulerRef.current.reseed({ schedule, quota, assetsById, pendingEvents: merged, history });
      setHistoryReady(true);
    });
    return () => {
      active = false;
    };
    // mount only — schedule/quota/assetsById changes handled by the effect below
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Reconcile: a new server snapshot is authoritative; re-apply unsynced plays.
  useEffect(() => {
    if (!historyReady) return;
    schedulerRef.current.reseed({
      schedule,
      quota,
      assetsById,
      pendingEvents: pendingRef.current,
    });
  }, [schedule, quota, assetsById, historyReady]);

  const pickNext = useCallback(() => schedulerRef.current.pickNext(), []);

  const recordPlay = useCallback((asset) => {
    if (!asset) return null;
    const event = schedulerRef.current.recordPlay(asset);
    pendingRef.current.push(event);
    queueAdd(event);
    persistHistory(schedulerRef.current.history);
    return event;
  }, []);

  // Push an override asset to the front of the scheduler's priority queue so it
  // plays before anything else regardless of hourly/daily limits or pacing rules.
  const injectOverride = useCallback((asset) => {
    schedulerRef.current.injectOverride(asset);
  }, []);

  // Clear the scheduler's override queue when cancelled by the server
  const cancelOverride = useCallback(() => {
    schedulerRef.current.overrideQueue = [];
  }, []);

  // Drop events the server has durably confirmed (called by the sync engine).
  const dropSynced = useCallback((ids) => {
    if (!ids || ids.length === 0) return;
    const set = new Set(ids);
    pendingRef.current = pendingRef.current.filter(
      (e) => !set.has(e.client_event_id),
    );
  }, []);

  return { pickNext, recordPlay, dropSynced, injectOverride, cancelOverride };
}
