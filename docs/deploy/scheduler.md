# Scheduler

The schedule is defined in `backend/routes/console.php` (~45 entries: payments expiry, flash-sale transitions, payouts, COD settlements, campaigns, conversions, travel holds, ads budgets, content publishing, analytics…).

- Cron on **exactly one** host: `* * * * * cd /srv/nawy/backend && php artisan schedule:run >> /dev/null 2>&1`. Every entry uses `withoutOverlapping()`; with several app hosts add `onOneServer()` (needs the Redis cache store) before scaling the scheduler.
- Daily jobs are pinned to `NAWY_ADMIN_TIMEZONE` (Oman time) — e.g. payouts, COD settlements, conversions approve (03:15) / release (03:30), campaign completion (04:00).
- Each command iterates the 8 countries through `ForEachCountry`; a failure in one country is logged and does not stop the others. Scheduled runs are recorded by `SystemHealth` (`ScheduledTaskFinished` / `ScheduledTaskFailed`) and shown on the admin dashboard; failures alert `NAWY_OPS_ALERT_EMAIL`.
- `php artisan schedule:list` shows the next run of every entry.
- During the migration cutover the scheduler is **off** until reconciliation is signed off (see [migration-runbook.md](migration-runbook.md)).
