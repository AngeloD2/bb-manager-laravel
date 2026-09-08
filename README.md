# bb-manager-laravel

**BCC — Board Control Center** · Laravel 11 API Backend

Central source of truth for the React Native Expo billboard management app.

---

## Stack

| Layer         | Technology                                    |
|---------------|-----------------------------------------------|
| Framework     | Laravel 11                                    |
| Database      | PostgreSQL (MySQL-compatible with minor tweaks)|
| Auth          | Laravel Sanctum — long-lived billboard tokens |
| Queue         | Laravel database queue → upgrade to Redis/SQS |
| WebSockets    | Laravel Reverb (optional; polling fallback)   |
| Storage       | AWS S3 + KMS server-side encryption           |
| CDN           | AWS CloudFront (optional)                     |
| Media parsing | FFprobe via shell_exec in AssetProcessingJob  |
| Player SPA    | React 19 + Vite, served at `/player`          |

---

## Recent Adjustments

### Fallback Inventory & Loop Adjustments
- **Grouped by Date**: The fallback inventory data models logically group records by day.
- **Aggregated Counts**: Total number of Available and Sold slots are now tracked and aggregated per day and fallback loop.
- **Simplified Tracking**: Database and API structures favor aggregating slot consumption per loop/date rather than exposing verbose individual distinct slot listings.

### Vault & DB Simplification
- **Geographic Zones Removed**: The concept of "Geographic Zones" was stripped from core models (`MediaAsset` and `Billboard`), removing unnecessary filtering complexity.
- **Media Folders Hierarchy**: Self-referencing hierarchies (`parent_id`) were fixed in database migrations, correctly establishing foreign keys for robust loop-level structure.

---

## Setup

```bash
# 1. Install dependencies
composer install

# 2. Configure environment
cp .env.example .env
php artisan key:generate

# 3. Create the database
createdb bb_manager          # PostgreSQL
# or: touch database/database.sqlite   # SQLite for local dev

# 4. Run migrations
php artisan migrate

# 5. Seed sample data (creates loops, assets, and 2 billboard tokens)
php artisan db:seed

# 6. Start the server
php artisan serve            # http://localhost:8000

# 7. Start the queue worker (for AssetProcessingJob)
php artisan queue:work

# 8. (Optional) Start Reverb WebSocket server
php artisan reverb:start
```

---

## API Overview

### Billboard Endpoints (Sanctum billboard token required)

| Method | Endpoint                          | Description                                   |
|--------|-----------------------------------|-----------------------------------------------|
| GET    | `/api/v1/sync`                    | Pull folders, eligible assets, overrides      |
| POST   | `/api/v1/logs`                    | Bulk-submit playback logs (offline-first)     |
| GET    | `/api/v1/assets/{id}/download`    | Get signed S3 URL for edge caching            |

### Admin Endpoints (Sanctum admin token required)

| Method | Endpoint                                  | Description                                      |
|--------|-------------------------------------------|--------------------------------------------------|
| GET    | `/api/v1/admin/billboards`                | List all billboards                              |
| POST   | `/api/v1/admin/billboards`                | Provision a new billboard + return API token     |
| DELETE | `/api/v1/admin/billboards/{id}`           | Decommission billboard, revoke all tokens        |
| GET    | `/api/v1/admin/folders`                   | List folders                                     |
| POST   | `/api/v1/admin/folders`                   | Create folder (with optional daily token cap)    |
| PUT    | `/api/v1/admin/folders/{id}`              | Update folder                                    |
| DELETE | `/api/v1/admin/folders/{id}`              | Soft delete folder                               |
| GET    | `/api/v1/admin/assets`                    | List assets (filterable)                         |
| POST   | `/api/v1/admin/assets`                    | Create asset record                              |
| PUT    | `/api/v1/admin/assets/{id}`               | Update asset metadata / token rules              |
| DELETE | `/api/v1/admin/assets/{id}`               | Soft delete + S3 cleanup                         |
| POST   | `/api/v1/admin/assets/presigned-url`      | Get S3 PUT URL for direct upload                 |
| POST   | `/api/v1/admin/assets/{id}/confirm`       | Confirm S3 upload → dispatch FFprobe job         |
| POST   | `/api/v1/admin/overrides`                 | Push Play Next override to a billboard           |
| GET    | `/api/v1/admin/vault/links`               | List active secure share links                   |
| POST   | `/api/v1/admin/vault/links`               | Create ephemeral client proof link + PIN         |
| DELETE | `/api/v1/admin/vault/links/{id}`          | Revoke a share link                              |

### Public Vault

| Method | Endpoint                | Description                                          |
|--------|-------------------------|------------------------------------------------------|
| POST   | `/api/v1/vault/verify`  | Submit token + PIN to get delivery URL (rate-limited)|

---

## Upload Flow (Direct-to-S3)

```
Expo App                        Laravel                        AWS S3
   │                               │                              │
   │  POST /admin/assets/          │                              │
   │  presigned-url                │                              │
   │  { filename, mime_type }      │                              │
   │ ─────────────────────────────>│                              │
   │                               │── generate presigned PUT ───>│
   │                               │<── { upload_url, key } ─────│
   │<─── { upload_url, key } ──────│                              │
   │                               │                              │
   │  PUT {upload_url}             │                              │
   │  (binary file direct)         │                              │
   │ ─────────────────────────────────────────────────────────────>
   │                               │                              │
   │  POST /admin/assets/{id}/     │                              │
   │  confirm                      │                              │
   │ ─────────────────────────────>│                              │
   │                               │─ dispatch AssetProcessingJob │
   │<── 200 OK ────────────────────│  (FFprobe + mark is_synced)  │
```

---

## Billboard Player SPA

The player that runs on the physical board lives in this repo at
`resources/js/player` (it was previously the standalone `bb-manager-player-react`
repository). Laravel builds it with Vite and serves it from
`resources/views/player.blade.php` at **`/player`**.

```bash
npm install
npm run dev      # Vite dev server; Laravel picks it up via public/hot
npm run build    # production assets into public/build
```

Because the SPA is now served from the same origin as the API, `VITE_API_URL`
defaults to the relative `/api/v1` — no host to rewrite when the LAN address
changes. `VITE_REVERB_*` interpolate from the `REVERB_*` values in the same
`.env`.

It remains a fully client-side, offline-first player: `lib/db.js` persists the
billboard session, schedule and quota snapshot to IndexedDB so a board cold-boots
and plays with no network, and `lib/scheduler.js` meters spots locally, emitting
play events keyed by a client UUID that `/logs` dedups on reconcile. Serving it
from Laravel changes where the bundle comes from, not how it runs.

---

## Offline Cold Boot

A running board already survives a dead network — IndexedDB holds the session,
schedule and quota; the Cache API holds the media. The gap was **cold boot**: a
board that power-cycled while the backend was unreachable could not load the HTML
and JS needed to reach that offline logic, so it sat on a dead page with a
perfectly good schedule on disk.

`public/sw.js` closes that gap. It caches the shell (`/player`) and the hashed
`/build` assets, and is registered from `lib/registerSW.js` in production builds
only — in dev it unregisters instead, so a stale worker cannot break HMR.

Two rules in it are load-bearing, and `tests/js/sw.test.mjs` holds them:

- **`/api/*` is never cached.** `useConnectionStatus` decides a board is online by
  probing `/sync/ping`; a cached 200 there would make a board with a dead backend
  believe it is online and stop queueing plays for reconcile. `/sync` carries the
  billed quota snapshot and `/assets/{id}/serve` mints a short-lived presigned
  URL per request — neither is safe to replay.
- **`bcc-edge-cache-v1` is never deleted.** Activation purges only superseded
  `bcc-player-shell-*` caches. The media cache belongs to `useEdgeCache` and is
  what keeps a board playing offline.

The shell is fetched network-first with a 3s timeout, so a healthy board picks up
a new deploy on its next restart while a board on a wedged link falls back to
cache instead of hanging on a white screen. There is no auto-update-and-reload:
swapping assets under a billboard that is mid-playback risks interrupting a paid
spot, and network-first already means the next restart is current.

> **Requires a secure context.** Service workers — like the Cache API
> `useEdgeCache` depends on — only run over **https or localhost**. A board
> pointed at a plain-http LAN address (`http://192.168.x.x:8000`) gets neither:
> it still plays, and IndexedDB still persists its schedule, but it re-fetches
> the shell from the network on every boot and so has no offline cold boot.
> Production behind Laravel Cloud's https is fine; a LAN deployment needs TLS (or
> a localhost-served player) for this to take effect.

---

## Running Tests

```bash
php artisan test

# With coverage
php artisan test --coverage

# Specific suite
php artisan test --testsuite=Feature
```

### Test Suites

| File                            | What it covers                                         |
|---------------------------------|--------------------------------------------------------|
| `TokenManagerServiceTest.php`   | Token deduction, constraint validation, concurrency    |
| `SecureShareLinkTest.php`       | PIN verification, OTP expiry, revocation, rate-limiting|
| `BillboardSyncTest.php`         | Sync payload, override delivery, heartbeat             |
| `AssetControllerTest.php`       | Presigned URL, confirm flow, duration validation       |

JS scheduler tests live in `tests/js` and run with `npm test`; `LoopParityTest.php`
and `tests/js/loop-parity.test.mjs` assert against the same
`tests/fixtures/loop_parity.json` so the PHP and JS engines cannot drift apart.

---

## Answering the Open Questions (Implementation Plan)

**1. Billboard Authentication:** Long-lived Sanctum tokens provisioned per billboard
via `POST /api/v1/admin/billboards`. The token is shown once at provision time and
stored on the physical board. Tokens carry `billboard:sync` and `billboard:log`
abilities.

**2. S3 File Uploads:** Direct-to-S3 via presigned PUT URLs. Laravel never touches
the binary payload — it only generates the URL and, after the client confirms,
dispatches `AssetProcessingJob` to run FFprobe and mark the asset as `is_synced`.

**3. Real-time vs polling:** Both supported. `OverrideDispatched` broadcasts via
Laravel Reverb on `private-billboard.{billboard_id}`. If Reverb is not configured,
overrides are queued in `timeline_overrides` and delivered on the next
`GET /api/v1/sync` poll (60-second polling is safe).
