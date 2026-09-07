// Registers the app-shell service worker (public/sw.js).
//
// Production builds only. In dev a service worker left over from a production
// build on the same origin would serve stale assets and break HMR, so we
// actively tear one down rather than just skipping registration.
//
// Note: service workers (like the Cache API useEdgeCache relies on) need a
// secure context — https, or localhost. A board pointed at a plain-http LAN
// address gets neither, and falls back to loading the shell from the network on
// every boot. See the README's "Offline cold boot" section.
export function registerServiceWorker() {
  if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) return;

  if (!import.meta.env.PROD) {
    navigator.serviceWorker
      .getRegistrations()
      .then((regs) => regs.forEach((r) => r.unregister()))
      .catch(() => {});
    return;
  }

  window.addEventListener('load', () => {
    // No update polling: the shell is fetched network-first on every boot, so a
    // running board already picks up a new deploy the next time it restarts.
    // Swapping assets under a billboard that is mid-playback would buy nothing
    // and risks interrupting a paid spot.
    navigator.serviceWorker.register('/sw.js').catch((err) => {
      // Not fatal — a board that cannot cache its shell still plays normally as
      // long as it stays up. It just loses the offline cold boot.
      console.warn('[sw] registration failed', err);
    });
  });
}
