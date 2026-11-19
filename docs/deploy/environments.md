# Environments

| Environment | Purpose | Data | Notes |
|---|---|---|---|
| **local** | development | central `noon-plus` + `nawy_{cc}` dev databases, demo/e2e seed | fake payment, social and live-media drivers allowed |
| **testing** | Pest (`phpunit.xml`) | `nawy_test_central` + `nawy_test_{cc}` + `nawy_test_legacy` fixture | never the dev or live databases |
| **staging** | UAT, migration dry runs | a fresh legacy snapshot migrated into staging databases | real gateway **sandbox** credentials; `APP_ENV=staging` |
| **production** | live | central + 8 country databases | `APP_ENV=production`, `APP_DEBUG=false`; the app refuses to boot with a `fake` payment or social driver |

## Services

| Service | Process | Scale |
|---|---|---|
| Backend API + panels | PHP-FPM 8.3 behind nginx (`backend/public`) | horizontal; stateless (sessions in DB/Redis) |
| Queue workers | `php artisan horizon` (one per worker host) | see [queues-horizon.md](queues-horizon.md) |
| Scheduler | `php artisan schedule:run` every minute on **one** host | see [scheduler.md](scheduler.md) |
| Websockets | `php artisan reverb:start` | see [reverb.md](reverb.md) |
| Website | Next.js 16 (`website/`, `npm run build && npm start`) | horizontal |
| MySQL 8 | central DB + one DB per country (+ reporting) | see [country-databases.md](country-databases.md) |
| Redis | cache, queues, Horizon, Reverb scaling | one primary + replica |
| Object storage | public disk (media) and private disk (contracts, documents, proofs) | private disk is never web-served; files go out through signed URLs |

## Hosts

The panels are served by host: `admin.`, `partner.`, `marketer.`, `travel-agency.`, `carrier.`, `delivery.` + `NAWY_BASE_DOMAIN` (`config/nawy.php` → `panels`). The API is `api.` (or the backend host) under `/api/v1/{market}`. The website serves `/{cc}-{lang}/…` markets (e.g. `/uae-en`). TLS terminates at the load balancer / nginx; set `SESSION_SECURE_COOKIE=true` (the default in production) and make sure `X-Forwarded-Proto` reaches Laravel (trusted proxies) so HSTS and secure cookies apply.
