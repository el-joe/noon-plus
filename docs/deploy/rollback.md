# Rollback

## Application release

- Releases are immutable directories (`releases/{timestamp}`, `current` symlink). Rolling back = point `current` at the previous release, reload PHP-FPM, `php artisan horizon:terminate`, `php artisan reverb:restart`.
- Migrations are written **additive** (new tables/columns/indexes); the previous release keeps working against the newer schema. Never run `migrate:rollback` in production unless the release notes say a migration is destructive-safe; restore from backup instead ([backups-restore.md](backups-restore.md)).
- Config caches: `php artisan optimize:clear && php artisan optimize` after switching.

## Migration cutover

The old platform's database is never written (the migration reads a snapshot through a READ ONLY connection), so the cutover can be undone at any point:

1. New platform: maintenance, stop Horizon and the scheduler.
2. Switch DNS / subdomains back to the old platform and take it out of maintenance.
3. Restore the 9 new databases from the pre-cutover backup (or drop them) — nothing written in the new system during the window is kept, so keep the window short and announce it.
4. Record what failed in `migration_quarantine` / the reconciliation report, fix, and run the dry-run cycle again.

Partial undo of a migration run is also possible: every migrated row is listed in `migration_id_map` (target database, table, id), so a step's rows can be identified exactly.
