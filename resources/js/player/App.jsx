import { useState, useEffect, useCallback } from 'react';
import ConfigScreen from './screens/ConfigScreen';
import PlayerScreen from './screens/PlayerScreen';
import { loadSession, clearSession } from './lib/session';

export default function App() {
  const [session, setSession] = useState(null);
  // Why the board dropped back to pairing, so the screen can say so instead of
  // looking like it was never connected.
  const [notice, setNotice] = useState(null);
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

  // Stable identity: the connection probe and the sync engine both take this as
  // an effect dependency, so a fresh closure each render would restart their
  // intervals continuously.
  const handleAuthLost = useCallback(() => {
    clearSession();
    setNotice('This billboard was connected somewhere else. Enter its key again to resume.');
    setSession(null);
  }, []);

  if (restoring) {
    return <div style={{ position: 'fixed', inset: 0, background: '#000' }} />;
  }

  if (!session) {
    return <ConfigScreen onConnected={setSession} notice={notice} />;
  }

  return (
    <PlayerScreen
      apiUrl={session.apiUrl}
      token={session.token}
      syncData={session.syncData}
      // A board holds exactly one token, so pairing its key anywhere else
      // revokes this one. Until now the displaced board carried on showing its
      // last schedule while every sync and flush was refused -- looking alive
      // while it silently stopped counting. Drop to pairing instead, and say
      // why. Only a refused token does this; going offline must not, or a board
      // would abandon playback the moment its link dropped.
      onAuthLost={handleAuthLost}
    />
  );
}
