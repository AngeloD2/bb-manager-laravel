import { useCallback, useEffect, useRef } from "react";
import { sync, flushLogs } from "../api";
import { queueUnsynced, queueMarkSynced, queuePurgeSynced } from "../lib/db";

// Opportunistic background synchronizer. Two jobs:
//
//   • flush()   — push the unsynced local play queue to /logs (idempotent), then
//                 mark/purge the confirmed events. Safe to retry: the server
//                 dedups by client_event_id so a dropped response never
//                 double-charges.
//   • refresh() — pull a fresh /sync snapshot. The server is authoritative on
//                 remaining spots; `onReconcile` re-seeds the scheduler, which
//                 re-applies any still-unsynced plays on top.
//
// Flushing fires whenever connectivity returns; refresh runs on an interval and
// on demand (e.g. the Pusher `sync` command).
export function useSyncEngine({
  apiUrl,
  token,
  isOnline,
  paused = false,
  dropSynced,
  onReconcile,
  refreshMs = 120000,
}) {
  const flushingRef = useRef(false);

  const flush = useCallback(async () => {
    if (!apiUrl || !token || flushingRef.current) return;
    flushingRef.current = true;
    try {
      const events = await queueUnsynced();
      if (events.length === 0) return;

      const res = await flushLogs(apiUrl, token, events);
      const results = res?.data?.results || [];
      // Any event the server returned a verdict for is durably handled
      // (new/duplicate/rejected). If it echoed nothing, assume the batch we
      // sent was accepted.
      const confirmed = results.map((r) => r.client_event_id).filter(Boolean);
      const ids = confirmed.length
        ? confirmed
        : events.map((e) => e.client_event_id);

      await queueMarkSynced(ids);
      dropSynced(ids);
      await queuePurgeSynced();
    } catch (err) {
      console.warn("[syncEngine] flush failed; will retry", err);
    } finally {
      flushingRef.current = false;
    }
  }, [apiUrl, token, dropSynced]);

  const refresh = useCallback(async () => {
    if (!apiUrl || !token) return;
    try {
      const data = await sync(apiUrl, token);
      if (data) onReconcile(data);
      // The fresh snapshot already excludes synced plays; clear any leftovers.
      flush();
    } catch (err) {
      console.warn("[syncEngine] refresh failed", err);
    }
  }, [apiUrl, token, onReconcile, flush]);

  // Flush as soon as the link comes back (and on first mount if already online).
  useEffect(() => {
    if (isOnline) flush();
  }, [isOnline, flush]);

  // Periodic reconcile while reachable. Suspended while paused: a frozen billboard
  // gets an empty schedule from /sync, so reconciling mid-pause would wipe the
  // in-memory queue the player needs to resume instantly. Resume issues its own
  // refresh() to reconcile.
  useEffect(() => {
    if (!isOnline || paused) return;
    const id = setInterval(refresh, refreshMs);
    return () => clearInterval(id);
  }, [isOnline, paused, refresh, refreshMs]);

  return { flush, refresh };
}
