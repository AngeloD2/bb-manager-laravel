import { useState } from "react";
import { deviceLogin, sync } from "../api";
import { persistSession } from "../lib/session";

const s = {
  root: {
    display: "flex",
    alignItems: "center",
    justifyContent: "center",
    minHeight: "100vh",
    background: "#0a0a0a",
  },
  card: {
    background: "#141414",
    border: "1px solid #2a2a2a",
    borderRadius: 12,
    padding: "40px 48px",
    width: 420,
    maxWidth: "90vw",
  },
  title: {
    color: "#fff",
    fontSize: 22,
    fontWeight: 700,
    marginBottom: 6,
    fontFamily: "system-ui, sans-serif",
  },
  subtitle: {
    color: "#666",
    fontSize: 13,
    marginBottom: 32,
    fontFamily: "system-ui, sans-serif",
  },
  label: {
    display: "block",
    color: "#aaa",
    fontSize: 12,
    fontWeight: 600,
    textTransform: "uppercase",
    letterSpacing: "0.06em",
    marginBottom: 6,
    fontFamily: "system-ui, sans-serif",
  },
  input: {
    width: "100%",
    background: "#0a0a0a",
    border: "1px solid #333",
    borderRadius: 6,
    padding: "10px 12px",
    color: "#fff",
    fontSize: 14,
    fontFamily: "monospace",
    boxSizing: "border-box",
    outline: "none",
  },
  group: { marginBottom: 20 },
  button: {
    width: "100%",
    padding: "11px 0",
    background: "#2563eb",
    border: "none",
    borderRadius: 6,
    color: "#fff",
    fontSize: 14,
    fontWeight: 600,
    cursor: "pointer",
    marginTop: 8,
    fontFamily: "system-ui, sans-serif",
  },
  buttonDisabled: { opacity: 0.5, cursor: "not-allowed" },
  error: {
    background: "#2a0a0a",
    border: "1px solid #5a1a1a",
    borderRadius: 6,
    padding: "10px 12px",
    color: "#f87171",
    fontSize: 13,
    marginBottom: 20,
    fontFamily: "system-ui, sans-serif",
  },
};

export default function ConfigScreen({ onConnected }) {
  const apiUrl = (
    import.meta.env.VITE_API_URL || "http://127.0.0.1:8000/api/v1"
  ).trim();
  const [password, setPassword] = useState("");
  const [error, setError] = useState(null);
  const [loading, setLoading] = useState(false);

  async function handleSubmit(e) {
    e.preventDefault();
    if (!password.trim()) {
      setError("A Billboard Key is required.");
      return;
    }
    setError(null);
    setLoading(true);
    const trimmedPassword = password.trim();
    try {
      const token = await deviceLogin(apiUrl, trimmedPassword);
      const data = await sync(apiUrl, token);
      localStorage.setItem("bb_device_token", token);
      // Persist the full session so the board can cold-boot offline next time.
      await persistSession({ apiUrl, token, syncData: data });
      onConnected({ apiUrl, token, syncData: data });
    } catch (err) {
      setError(
        err.message === "AUTH"
          ? "Wrong password — no billboard matches it."
          : `Could not reach the server: ${err.message}`,
      );
    } finally {
      setLoading(false);
    }
  }

  return (
    <div style={s.root}>
      <div style={s.card}>
        <div style={s.title}>Billboard Player</div>
        <div style={s.subtitle}>
          Connect to your billboard backend to start playback.
        </div>
        {error && <div style={s.error}>{error}</div>}
        <form onSubmit={handleSubmit}>
          <div style={s.group}>
            <label style={s.label}>Billboard Key</label>
            <input
              style={s.input}
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder="e.g. blue-tiger-42"
              autoComplete="current-password"
            />
          </div>
          <button
            type="submit"
            style={loading ? { ...s.button, ...s.buttonDisabled } : s.button}
            disabled={loading}
          >
            {loading ? "Connecting..." : "Connect & Start Player"}
          </button>
        </form>
      </div>
    </div>
  );
}
