import { metaGet, metaSet, metaDel } from "./db";

// The device session that lets the board cold-boot and play offline: the API
// base, the device token, and the last /sync snapshot (which carries the
// schedule + quota the scheduler runs on).
const SESSION_KEY = "session";

export function persistSession(session) {
  if (!session?.apiUrl || !session?.token) return Promise.resolve();
  return metaSet(SESSION_KEY, {
    apiUrl: session.apiUrl,
    token: session.token,
    syncData: session.syncData ?? null,
    saved_at: new Date().toISOString(),
  });
}

export function loadSession() {
  return metaGet(SESSION_KEY);
}

export function clearSession() {
  return metaDel(SESSION_KEY);
}
