# Nawy — deployment docs

The documents below describe how the Nawy platform runs outside a developer machine. Read them in order the first time.

| Document | What it covers |
|---|---|
| [environments.md](environments.md) | local, staging, production; hosts and subdomains; what runs where |
| [env-variables.md](env-variables.md) | every environment variable per service (backend, website, Flutter apps) |
| [queues-horizon.md](queues-horizon.md) | Redis queues, Horizon supervisors, failed jobs |
| [scheduler.md](scheduler.md) | the Laravel scheduler (cron, timezone, the per-country jobs) |
| [reverb.md](reverb.md) | the Reverb websocket server and its proxy |
| [country-databases.md](country-databases.md) | provisioning a country database (central + 8 countries) |
| [reporting-database.md](reporting-database.md) | the `nawy_reporting` database, nightly ETL, backfills, celebrity monthly report job |
| [exchange-rates.md](exchange-rates.md) | central exchange rates, PayPal charged in USD, the optional rate provider key |
| [domains.md](domains.md) | custom domains / subdomains → market, panel alias, portal; DNS + TLS runbook |
| [backups-restore.md](backups-restore.md) | backups, retention and restore drills |
| [migration-runbook.md](migration-runbook.md) | the old-platform data migration: dry run → run → reconcile → cutover with redirects |
| [rollback.md](rollback.md) | rolling back an application release and the migration cutover |
| [security-checklist.md](security-checklist.md) | the production hardening checklist (WP11 security review) |

Sources of truth: `MASTER_PLAN.md` (ADRs), `docs/project/data-migration-plan.md`, `backend/config/*.php`.
