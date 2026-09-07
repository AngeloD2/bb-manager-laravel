import { useState, useEffect } from 'react';
import ConfigScreen from './screens/ConfigScreen';
import PlayerScreen from './screens/PlayerScreen';
import { loadSession } from './lib/session';

export default function App() {
  const [session, setSession] = useState(null);
  // `null` while we check IndexedDB for a saved session on boot. This is what
  // lets a rebooted billboard resume playing offline instead of falling back to
  // the login screen.
  const [restoring, setRestoring] = useState(true);

  useEffect(() => {
    let active = true;
    loadSession()
      .then((saved) => {
        if (active && saved?.apiUrl && saved?.token && saved?.syncData) {
          setSession(saved);
        }
      })
      .finally(() => {
        if (active) setRestoring(false);
      });
    return () => {
      active = false;
    };
  }, []);

  if (restoring) {
    return <div style={{ position: 'fixed', inset: 0, background: '#000' }} />;
  }

  if (!session) {
    return <ConfigScreen onConnected={setSession} />;
  }

  return (
    <PlayerScreen
      apiUrl={session.apiUrl}
      token={session.token}
      syncData={session.syncData}
    />
  );
}
