#!/usr/bin/env bash

# ─── BCC Backend Dev Server ───────────────────────────────────────────────────
# Starts all three Laravel services concurrently:
#   1. php artisan serve       — HTTP API on :8000
#   2. php artisan queue:work  — Processes queued broadcast jobs
#   3. php artisan reverb:start --debug — WebSocket server on :8080
#
# Usage: ./dev.sh
# Stop:  Ctrl+C  (all three processes are killed cleanly)
# ─────────────────────────────────────────────────────────────────────────────

set -e

# Colours
RED='\033[0;31m'
GREEN='\033[0;32m'
CYAN='\033[0;36m'
YELLOW='\033[1;33m'
RESET='\033[0m'

# Track child PIDs so Ctrl+C kills them all
PIDS=()

cleanup() {
  echo -e "\n${YELLOW}Shutting down all services...${RESET}"
  for pid in "${PIDS[@]}"; do
    kill "$pid" 2>/dev/null
  done
  wait
  echo -e "${GREEN}All services stopped. Goodbye.${RESET}"
  exit 0
}

trap cleanup SIGINT SIGTERM

echo -e "${CYAN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
echo -e "${CYAN}  BCC Billboard Manager — Dev Server${RESET}"
echo -e "${CYAN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
echo -e "${GREEN}  HTTP API    →  http://localhost:8000${RESET}"
echo -e "${GREEN}  WebSockets  →  ws://localhost:8080${RESET}"
echo -e "${GREEN}  Queue       →  database driver${RESET}"
echo -e "${CYAN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
echo -e "${YELLOW}Press Ctrl+C to stop all services.${RESET}\n"

# 1. HTTP API server
php artisan serve --host=0.0.0.0 --port=8000 &
PIDS+=($!)
echo -e "${GREEN}[✓]${RESET} HTTP server started   (PID $!)"

# 2. Queue worker (needed for queued broadcast events)
php artisan queue:work --tries=3 --sleep=2 &
PIDS+=($!)
echo -e "${GREEN}[✓]${RESET} Queue worker started  (PID $!)"

# 3. Reverb WebSocket server
php artisan reverb:start --debug &
PIDS+=($!)
echo -e "${GREEN}[✓]${RESET} Reverb WS started     (PID $!)\n"

# Wait for all background jobs (exits when any dies or Ctrl+C fires)
wait
