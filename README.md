# bb-manager-laravel

**BCC — Board Control Center** · Laravel 11 API Backend

Central source of truth for the React Native Expo billboard management app.

---

## Stack

| Layer         | Technology                                    |
|---------------|-----------------------------------------------|
| Framework     | Laravel 11                                    |
| Database      | PostgreSQL (MySQL-compatible with minor tweaks)|
| Auth          | Laravel Sanctum — long-lived device tokens    |
| Queue         | Laravel database queue → upgrade to Redis/SQS |
| WebSockets    | Laravel Reverb (optional; polling fallback)   |
| Storage       | AWS S3 + KMS server-side encryption           |
| CDN           | AWS CloudFront (optional)                     |
| Media parsing | FFprobe via shell_exec in AssetProcessingJob  |

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

# 5. Seed sample data (creates folders, assets, and 2 device tokens)
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

### Billboard Device Endpoints (Sanctum device token required)

| Method | Endpoint                          | Description                                   |
|--------|-----------------------------------|-----------------------------------------------|
| GET    | `/api/v1/sync`                    | Pull folders, eligible assets, overrides      |
| POST   | `/api/v1/logs`                    | Bulk-submit playback logs (offline-first)     |
| GET    | `/api/v1/assets/{id}/download`    | Get signed S3 URL for edge caching            |

### Admin Endpoints (Sanctum admin token required)

| Method | Endpoint                                  | Description                                      |
|--------|-------------------------------------------|--------------------------------------------------|
| GET    | `/api/v1/admin/devices`                   | List all billboard devices                       |
| POST   | `/api/v1/admin/devices`                   | Provision a new device + return API token        |
| DELETE | `/api/v1/admin/devices/{id}`              | Decommission device, revoke all tokens           |
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
| POST   | `/api/v1/admin/overrides`                 | Push Play Next override to a device              |
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
| `DeviceSyncTest.php`            | Sync payload, override delivery, heartbeat             |
| `AssetControllerTest.php`       | Presigned URL, confirm flow, duration validation       |

---

## Answering the Open Questions (Implementation Plan)

**1. Device Authentication:** Long-lived Sanctum tokens provisioned per device via
`POST /api/v1/admin/devices`. The token is shown once at provision time and stored
on the physical board. Tokens carry `device:sync` and `device:log` abilities.

**2. S3 File Uploads:** Direct-to-S3 via presigned PUT URLs. Laravel never touches
the binary payload — it only generates the URL and, after the client confirms,
dispatches `AssetProcessingJob` to run FFprobe and mark the asset as `is_synced`.

**3. Real-time vs polling:** Both supported. `OverrideDispatched` broadcasts via
Laravel Reverb on `private-device.{device_id}`. If Reverb is not configured,
overrides are queued in `timeline_overrides` and delivered on the next
`GET /api/v1/sync` poll (60-second polling is safe).
