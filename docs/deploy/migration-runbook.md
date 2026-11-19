# Old-platform data migration runbook (WP11)

The plan and the rules are in `docs/project/data-migration-plan.md`; the last report is `docs/project/migration/reconciliation-report.md`.

## Tools

| Command | What it does |
|---|---|
| `php artisan legacy:migrate {step\|all} [--country=uae ...] [--dry-run] [--since=YYYY-MM-DD]` | runs the step pipeline `reference → identity → catalog → listings → inventory → carts → orders → payments → finance → marketing → marketers → contracts → classifieds → travel → delivery → content → streams → files`. Idempotent: an unchanged legacy row is never rewritten; a re-run is a no-op. `--dry-run` resolves everything and writes nothing. |
| `php artisan legacy:reconcile` | counts, money per currency, ledger balance, wallet projections, statuses + order roll-up, identity merge, signatures, files, orphans → `docs/project/migration/reconciliation-report.md` (PII masked). Exit code 1 while any difference is unexplained. |
| `php artisan legacy:redirects` | rebuilds `legacy_redirects` (301 map), exports `storage/app/private/migration/legacy-redirects.{json,map}` and clears the cached redirect lookups |
| `php artisan legacy:migrate {step} --row=table:id [--dry-run]` | re-runs one legacy row of its table (what Admin → Data migration → Quarantine → "Re-try" does); its quarantine entry resolves when the row migrates |

Bookkeeping (central DB): `migration_runs` (metrics per step), `migration_id_map` (legacy id → target row per database), `migration_quarantine` (rows that could not be resolved, with reason; resolved automatically when a later run migrates them), `legacy_records` (central and per country: rows archived as they were).

## Prerequisites

- A snapshot of the old database restored as `nawy_legacy_snapshot` (never the live DB). The `legacy` connection opens every session `READ ONLY`.
- `LEGACY_STORAGE_ROOT` pointing at the old `storage/app` (read only; files are copied, never moved).
- Backups of the 9 target databases ([backups-restore.md](backups-restore.md)).

## 1. Dry run (T-21, T-10)

```bash
php artisan legacy:migrate all --dry-run      # read the metrics: read / written / quarantined per table
php artisan legacy:migrate all                # into staging
php artisan legacy:reconcile                  # must exit 0
php artisan legacy:redirects
```

Review the quarantine with the business owners in **Admin → Data migration** (super admin or Ops, `data_migration.*`): filter the queue by step / table / country / reason, open an entry (personal data masked; "Reveal" is logged), then per row either

- fix the data in the old system and **mark resolved** (note required), then re-try: "Preview re-try (dry run)" → "Apply re-try" for the row, or "Re-try a step" (dry run on the `migration` queue, then Apply) for a whole step in one country; entries the run migrates leave the queue on their own; or
- **waive** it with a note (accepted as not migrated — it stays counted as quarantined in the report).

Every decision, reveal and re-try is in the activity log (`data_migration`). "Run reconcile" on the Reconciliation page queues `legacy:reconcile` and shows the new report. The Horizon supervisor that serves the `reports, migration` queues must be running for queued re-tries, reconcile and "Rebuild map". On the final snapshot, fix data at the source and take a new snapshot rather than editing rows by hand.

## 2. Cutover (T-0)

1. Old platform: maintenance mode (read only), scheduler and queues stopped. Process approved payouts first (plan §9).
2. Final snapshot → restore as `nawy_legacy_snapshot`.
3. New platform: maintenance, Horizon and scheduler stopped; back up the 9 databases.
4. `php artisan legacy:migrate all` (target ≤ 4 h; the dev run of 12.5k rows takes ~2 minutes, a re-run seconds).
5. `php artisan legacy:reconcile` → exit 0; sign-off by BA, Finance and QC on the report.
6. `php artisan legacy:redirects` (or Admin → Data migration → Legacy redirects → "Rebuild map"). The platform serves the map itself — nothing to load into nginx:
   - the website (`proxy.ts`) 301s an old storefront URL that would otherwise 404, and un-prefixed old-portal paths, via `GET /api/v1/legacy-redirects?path=…` (cached in Laravel per path until the next rebuild, and 10 minutes in the website process);
   - Laravel 301s old portal paths that reach a panel or API host (`/{cc}/helpcenter/…`, `/blog/…`, `/join`, …) to `NAWY_STOREFRONT_URL` + the new path before its 404.
   Spot-check: `curl -sI https://{storefront}/uae-en/live-streams` → `301` `location: /uae-en/live`.
   *Optional* (only if the old domain is served by an nginx that does not reach the platform, or to save the lookups): `map $uri $legacy_redirect { include legacy-redirects.map; }` + `if ($legacy_redirect) { return 301 $legacy_redirect; }` with the exported `.map` (downloadable from the Legacy redirects page).
7. DNS / subdomain switch, start Horizon and the scheduler, `php artisan up`, smoke test each panel.
8. Search Console: submit the new sitemaps.

## 3. After cutover

- Keep the old system read-only for 30 days, then archive it.
- Remove `DB_LEGACY_*` and `LEGACY_STORAGE_ROOT` from the environment.

## Rollback

See [rollback.md](rollback.md): the old database is never written to, so switching DNS back loses nothing.
