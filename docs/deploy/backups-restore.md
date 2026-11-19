# Backups and restore

## What is backed up

| Data | How | Retention |
|---|---|---|
| Central + 8 country databases | nightly `mysqldump --single-transaction --routines --triggers --databases …` per database, gzip, encrypted, copied off-host; binary logs for point-in-time recovery | 30 daily, 12 monthly |
| Private disk (contracts, signed copies, documents, delivery proofs) | object-storage versioning + nightly sync to the backup bucket | 7 years (contracts and invoices are legal records) |
| Public disk (media) | object-storage versioning | 90 days of versions |
| Redis | not backed up (cache, queues); failed jobs live in MySQL | — |

All 9 databases are dumped **in the same window**: cross-database references (central users ↔ country rows) must come from the same moment.

Example (what WP11 used on dev before the migration run, `backend/storage/app/private/backups/`, git-ignored):

```bash
D=backups/$(date +%Y%m%d-%H%M%S); mkdir -p "$D"
for db in nawy_central nawy_uae nawy_omn nawy_ksa nawy_egy nawy_kwt nawy_qat nawy_bhr nawy_jor; do
  mysqldump --single-transaction --routines --triggers --databases "$db" | gzip > "$D/$db.sql.gz"
done
chmod 600 "$D"/*   # check each file ends with "-- Dump completed"
```

## Restore

1. Put the app in maintenance (`php artisan down`), stop Horizon and the scheduler.
2. Restore all 9 databases from the same backup set: `zcat nawy_uae.sql.gz | mysql` (the dump recreates the database).
3. For point-in-time: replay binary logs to the target time on every database.
4. `php artisan migrate --force && php artisan nawy:countries:migrate` (only if the restored set is older than the code), `php artisan cache:clear`.
5. Start Horizon and the scheduler, `php artisan up`.

## Drills

Restore the latest backup into staging every month and run `php artisan test`-style smoke checks plus `legacy:reconcile` (until the old platform is archived). Record the time taken; the target is under 1 hour.
