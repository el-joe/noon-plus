# Environment variables per service

Values come from the environment only; never commit real values. `backend/.env.example` and `website/.env.example` list every key with safe defaults.

## Backend (`backend/.env`)

| Variable | Production value / note |
|---|---|
| `APP_ENV` | `production` |
| `APP_KEY` | `php artisan key:generate --show`, stored in the secret manager; rotating it invalidates sessions and encrypted settings |
| `APP_DEBUG` | `false` (panels would show stack traces otherwise; the API never does) |
| `APP_URL` | backend origin, e.g. `https://api.nawy.com` |
| `LOG_CHANNEL` / `LOG_LEVEL` | `stack` / `warning` |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | central database (`nawy_central`) |
| `NAWY_COUNTRY_DB_PREFIX` | `nawy_` → country databases `nawy_uae`, `nawy_omn`, … (same host and credentials as `DB_*`) |
| `NAWY_BASE_DOMAIN` | e.g. `nawy.com` (panel hosts are `admin.nawy.com`, …) |
| `NAWY_ADMIN_TIMEZONE` | `Asia/Muscat` (admin date-times and the scheduler) |
| `SESSION_DRIVER` / `SESSION_SECURE_COOKIE` / `SESSION_ENCRYPT` | `database` (or `redis`) / `true` (default in production) / `true` recommended |
| `CACHE_STORE`, `QUEUE_CONNECTION` | `redis`, `redis` |
| `REDIS_HOST`, `REDIS_PASSWORD`, `REDIS_PORT` | Redis primary |
| `BROADCAST_CONNECTION` | `reverb` |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` | see [reverb.md](reverb.md) |
| `VITE_REVERB_ENABLED`, `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, `VITE_REVERB_SCHEME` | **build time** (`npm run build` in `backend/`): the panels' live dispatch board (D16). `VITE_REVERB_ENABLED=true` once Reverb runs; otherwise no socket is opened and the board says it refreshes on reload |
| `MAIL_*` | the transactional mail provider (customers get codes and confirmations by email, D9) |
| `AWS_*` / `FILESYSTEM_DISK` | object storage for the public and private disks |
| `NAWY_PAYMENTS_DRIVER` | `http` (real gateways; `fake` is refused in production) — gateway credentials are entered per country in Admin → Payment gateways, never in env |
| `NAWY_STOREFRONT_URL` | website origin (gateway returns, referral and newsletter links) |
| `NAWY_EXCHANGE_RATES_DRIVER` / `NAWY_EXCHANGE_RATES_APP_ID` | `manual` (default, admins enter rates) or `openexchangerates` + the owner's App ID — see [exchange-rates.md](exchange-rates.md) |
| `NAWY_DOMAINS_STOREFRONT_TARGET` / `NAWY_DOMAINS_BACKEND_TARGET` | DNS targets shown in Admin → Domains — see [domains.md](domains.md) |
| `NAWY_CORS_ORIGINS` | comma-separated website / app origins allowed by CORS |
| `NAWY_API_RATE_PER_MINUTE` | default API limit per user or IP (600); raise for load tests, never remove. Only an `APP_ENV=local` app exempts loopback callers (127.0.0.1 / ::1: the storefront's server render and the Playwright e2e run on one machine); every other environment and address is limited |
| `NAWY_SOCIAL_AUTH_DRIVER` | `live` (`fake` is refused in production) + `GOOGLE_*`, `APPLE_*` |
| `NAWY_LIVE_MEDIA_DRIVER` | `livekit` + `LIVEKIT_*` once provided (Q-W1); `fake` keeps streams off. Only `livekit` sets `playback.webrtc=true`, which turns on the website's browser viewer (livekit-client, D16); `LIVEKIT_WS_URL` (or `LIVEKIT_URL`) must be reachable from browsers |
| `NAWY_OPS_ALERT_EMAIL` | where scheduler / health alerts go |
| `DB_LEGACY_DATABASE`, `DB_LEGACY_*`, `LEGACY_STORAGE_ROOT` | **migration only**: the read-only old-platform snapshot and old storage root. Remove after cutover + 30 days |

## Website (`website/.env`)

| Variable | Value |
|---|---|
| `NEXT_PUBLIC_API_URL` | `https://api.nawy.com/api/v1` |
| `NEXT_PUBLIC_DEFAULT_MARKET` | `uae-en` |
| `NEXT_PUBLIC_REVERB_APP_KEY` | **build time**: the backend's `REVERB_APP_KEY` (public). Empty = no websocket; live chat, the notification bell and orders keep polling (D16) |
| `NEXT_PUBLIC_REVERB_HOST`, `NEXT_PUBLIC_REVERB_PORT`, `NEXT_PUBLIC_REVERB_SCHEME` | `ws.nawy.com`, `443`, `https` (defaults: the page's host, 443, https). Private channels authorise through the website's `/api/nawy/{market}/broadcasting/auth` proxy |

## Flutter apps

Build-time `--dart-define`s: the API base URL (`API_BASE_URL`) and the default market. Push (`firebase_messaging`), Google/Apple sign-in client ids and a map key are pending owner accounts (D16).
