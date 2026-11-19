# Reporting database (`nawy_reporting`)

MASTER_PLAN ADR 2 / PRD FR-CTY-10, FR-ANL-2: cross-country admin reports never query the live country databases together. A nightly ETL copies daily facts from each country database into one **reporting** database, and the admin "Cross-country reports" page reads only from it.

- Connection: `reporting` (`backend/config/database.php`). Env: `DB_REPORTING_DATABASE` (default `nawy_reporting`), optional `DB_REPORTING_HOST` / `_PORT` / `_USERNAME` / `_PASSWORD` (default to the `DB_*` values). Tests use `nawy_test_reporting` (`phpunit.xml`), migrated fresh once per run by `tests/TestCase.php`.
- Migrations: `backend/database/migrations/reporting/` (not run by `php artisan migrate`).

## Provisioning

```bash
cd backend
php artisan nawy:reporting:create-database   # CREATE DATABASE nawy_reporting (MySQL, utf8mb4)
php artisan nawy:reporting:migrate           # database/migrations/reporting (idempotent; --fresh only in local/testing)
php artisan nawy:reporting:etl --from=2026-01-01 --to=2026-11-30   # optional first backfill
```

Every deploy: add `php artisan nawy:reporting:migrate` after `nawy:countries:migrate`. The application user needs read/write on `nawy_reporting`; a BI tool may get a separate read-only user on it alone.

## Tables

| Table | Grain | Contents |
|---|---|---|
| `fact_sales_daily` | country × local day × currency | orders (not cancelled), sub-orders, units, GMV, discounts, shipping, AOV, cancelled orders + value, unique and first-time buyers, COD orders, return requests, completed refunds |
| `fact_sellers_daily` | country × local day | active stores, new stores, stores with sales that day, active listings |
| `fact_marketers_daily` | country × local day × currency | active / new marketers (on the country-currency row), conversions, attributed sales, net commission + bonus, reversals, processed withdrawals |
| `fact_payouts_daily` | country × local day × currency | vendor statements generated and completed (count + amount) |
| `fact_revenue_daily` | country × local day × currency × account | net credits to the platform's `*_revenue` ledger accounts |
| `etl_runs` | country × run | dates loaded, trigger (schedule / backfill / manual), status running → succeeded / failed, rows, duration, error |

A "day" is the country's local calendar day (data is stored in UTC). Money is never summed across currencies.

## Nightly job and backfills

- `nawy:reporting:etl` runs daily at `nawy.analytics.reporting_etl_time` (01:30 admin/Oman time, settings catalogue `analytics.reporting_etl_time`) and loads **yesterday in each country's time zone**.
- Backfill: `--date=Y-m-d`, or `--from=Y-m-d --to=Y-m-d`; `--country=uae --country=omn` limits the countries.
- Reloading a day deletes and re-inserts that country's rows for the day in one transaction, so runs are idempotent and can be repeated after a fix. One country failing is recorded in `etl_runs` with the error and does not stop the others; the command exits non-zero so the scheduler log shows it.
- Super admins can reload one day for every country from Admin → Overview → Cross-country reports ("Reload now").

## Celebrity monthly report

`nawy:marketers:celebrity-reports` runs on the 1st at `nawy.analytics.celebrity_report_time` (06:00 Oman, after the 05:00 cross-promotion evaluation) per country for the previous local month (`--period=Y-m` to rerun, `--no-email` to skip mail). It writes `celebrity_monthly_reports` in the **country** database (not the reporting DB) and emails each influencer once with the PDF.
