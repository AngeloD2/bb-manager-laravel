import { useEffect, useRef } from 'react';
import Pusher from 'pusher-js';

export function usePusher({ deviceId, onCommand }) {
    const onCommandRef = useRef(onCommand);
    useEffect(() => {
      onCommandRef.current = onCommand;
    }, [onCommand]);

    useEffect(() => {
      const key    = import.meta.env.VITE_REVERB_APP_KEY;
      const host   = import.meta.env.VITE_REVERB_HOST   || '127.0.0.1';
      const port   = Number(import.meta.env.VITE_REVERB_PORT   || 8080);
      const scheme = import.meta.env.VITE_REVERB_SCHEME || 'http';

      if (!key) {
        console.warn('[usePusher] VITE_REVERB_APP_KEY is not set — WebSocket disabled.');
        return;
      }
      if (!deviceId) return;

      const pusher = new Pusher(key, {
        cluster: 'mt1',          // required by pusher-js; overridden by wsHost below
        wsHost: host,
        wsPort: port,
        wssPort: port,
        forceTLS: scheme === 'https',
        disableStats: true,
        enabledTransports: ['ws', 'wss'],
      });

      const channel = pusher.subscribe(`device.${deviceId}`);
      channel.bind('device.command', (data) => {
        // Reverb delivers the DeviceCommand payload: { command, payload }
        onCommandRef.current(data?.command ?? null, data?.payload ?? null);
      });

    return () => {
      pusher.unsubscribe(`device.${deviceId}`);
      pusher.disconnect();
    };
  }, [deviceId]);
}
