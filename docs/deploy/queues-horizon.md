# Queues and Horizon

All queued work runs on Redis through Laravel Horizon (`backend/config/horizon.php`).

| Supervisor | Queues | Production processes | Why |
|---|---|---|---|
| `supervisor-critical` | `payments`, `webhooks` | up to 6 | money and gateway webhooks never wait behind mail (ADR 6, 10) |
| `supervisor-default` | `default`, `scheduled`, `notifications`, `broadcasts` | up to 12 | everything else |
| `supervisor-reports` | `reports`, `migration` | 2 | long exports and legacy-migration batches |

## Running

- Process manager (systemd or supervisord): `php artisan horizon`, `stopwaitsecs=3600`, restart always.
- Every deploy: `php artisan horizon:terminate` after the code switch (workers restart with the new code).
- `horizon:snapshot` runs every 5 minutes from the scheduler (metrics).
- The dashboard is at `/horizon` on the admin host; access is gated to admins (`viewHorizon`: an admin membership).

## Failed jobs

Failed jobs stay in `failed_jobs` (central). Retry with `php artisan queue:retry {id|all}` after fixing the cause; payment webhooks are idempotent (`payment_webhook_events`), so a retry never double-posts.

## Country context

Jobs that touch country data run inside `CountryContext` (`App\Shared\Scheduling\ForEachCountry`); a job dispatched for one country carries the country code and never reads another country's database.
