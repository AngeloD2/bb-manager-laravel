export async function deviceLogin(apiUrl, password) {
  const res = await fetch(`${apiUrl}/device/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ password }),
  });
  if (res.status === 401 || res.status === 422) throw new Error('AUTH');
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const json = await res.json();
  return json.data.api_token;
}

// Stable per-asset media URL used for both prefetching and playback. Keying
// the blob cache on this exact string is what makes a prefetched asset a cache
// hit when its turn to play arrives, so both paths must build it identically.
export function assetServeUrl(apiUrl, assetId) {
  return `${apiUrl}/assets/${assetId}/serve`;
}

export async function sync(apiUrl, token) {
  const res = await fetch(`${apiUrl}/sync`, {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
  });
  if (res.status === 401) throw new Error('AUTH');
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const json = await res.json();
  return json.data;
}

// Cheap reachability probe. Returns { ok, server_time } or throws when offline.
export async function ping(apiUrl, token) {
  const res = await fetch(`${apiUrl}/sync/ping`, {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
  });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  return res.json();
}

export async function reportStart(apiUrl, token, assetId) {
  const now = new Date().toISOString().replace('+00:00', 'Z');
  await fetch(`${apiUrl}/playback/start`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${token}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ asset_id: assetId, started_at: now }),
  }).catch(() => {});
}

// Flush a batch of locally-recorded play events. Each carries a client_event_id
// so the server can dedup and charge each spot exactly once, even on retry.
// Returns the parsed response: { data: { results, device_state, ... } }.
export async function flushLogs(apiUrl, token, events) {
  const res = await fetch(`${apiUrl}/logs`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${token}`,
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
    body: JSON.stringify({
      logs: events.map((e) => ({
        client_event_id: e.client_event_id,
        asset_id: e.asset_id,
        played_at: e.played_at,
        was_override: e.was_override ?? false,
      })),
    }),
  });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  return res.json();
}
