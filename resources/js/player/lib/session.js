import { metaGet, metaSet, metaDel } from "./db";

// The billboard session that lets the board cold-boot and play offline: the API
// base, the billboard token, and the last /sync snapshot (which carries the
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

const HISTORY_KEY = "play_history";

export function persistHistory(historyArray) {
  return metaSet(HISTORY_KEY, {
    history: historyArray,
    saved_at: new Date().toISOString(),
  });
}

export async function loadHistory() {
  const data = await metaGet(HISTORY_KEY);
  if (!data || !data.history) return [];
  const savedAt = new Date(data.saved_at);
  const oneHourAgo = new Date(Date.now() - 3600000);
  if (savedAt < oneHourAgo) {
    return []; // Stale
  }
  return data.history;
}
