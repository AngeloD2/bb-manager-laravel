import { useState, useEffect, useRef } from 'react';
import { ping } from '../api';

// Connectivity for billboards is not the same as `navigator.onLine`: a board can
// have a live LAN link while its WAN/backend is unreachable. So in addition to
// the browser's online/offline events (a fast *negative* signal), we actively
// probe /sync/ping on an interval. `serverTime` from the probe lets callers
// correct local clock skew on played_at stamps.
//
// Returns { isOnline, lastReachableAt, serverTime }. Called with no args it
// degrades to the legacy navigator-only behavior.
export function useConnectionStatus({ apiUrl, token, intervalMs = 20000 } = {}) {
  const [isOnline, setIsOnline] = useState(navigator.onLine);
  const [lastReachableAt, setLastReachableAt] = useState(null);
  const [serverTime, setServerTime] = useState(null);
  const navOnlineRef = useRef(navigator.onLine);

  useEffect(() => {
    const goOnline = () => {
      navOnlineRef.current = true;
      // Don't optimistically claim reachable; let the next probe confirm.
      if (!apiUrl || !token) setIsOnline(true);
    };
    const goOffline = () => {
      navOnlineRef.current = false;
      setIsOnline(false); // hard negative — the link is physically down
    };
    window.addEventListener('online', goOnline);
    window.addEventListener('offline', goOffline);
    return () => {
      window.removeEventListener('online', goOnline);
      window.removeEventListener('offline', goOffline);
    };
  }, [apiUrl, token]);

  useEffect(() => {
    if (!apiUrl || !token) return; // no active probing without a session
    let cancelled = false;

    async function probe() {
      if (!navOnlineRef.current) {
        if (!cancelled) setIsOnline(false);
        return;
      }
      try {
        const res = await ping(apiUrl, token);
        if (cancelled) return;
        setIsOnline(true);
        setLastReachableAt(Date.now());
        if (res?.server_time) setServerTime(res.server_time);
      } catch {
        if (!cancelled) setIsOnline(false);
      }
    }

    probe();
    const id = setInterval(probe, intervalMs);
    return () => {
      cancelled = true;
      clearInterval(id);
    };
  }, [apiUrl, token, intervalMs]);

  return { isOnline, lastReachableAt, serverTime };
}
