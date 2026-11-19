# Country databases

MASTER_PLAN ADR 2: one **central** database (identity, countries, settings, roles, contract templates, migration bookkeeping) and one isolated **country** database per launched country (`nawy_uae`, `nawy_omn`, `nawy_ksa`, `nawy_egy`, `nawy_kwt`, `nawy_qat`, `nawy_bhr`, `nawy_jor`). The connection of a country is `country_{code}` (`config/database.php`), selected by `CountryContext`.

## Provisioning (new environment)

```bash
cd backend
php artisan migrate --force                     # central schema
php artisan nawy:countries:create-databases     # CREATE DATABASE nawy_{cc} for every configured country
php artisan nawy:countries:migrate              # database/migrations/country on every country DB (never --fresh outside local/testing)
php artisan nawy:reporting:create-database && php artisan nawy:reporting:migrate   # nawy_reporting (see reporting-database.md)
php artisan nawy:countries:sync                 # central `countries` rows from config/nawy.php
php artisan storage:link                        # public/storage → storage/app/public (uploaded media on the local public disk; harmless with object storage)
php artisan db:seed --class=AdminRoleSeeder --force
php artisan db:seed --class=PaymentGatewaySeeder --force
php artisan nawy:admin:create ops@nawy.com --name="Platform admin"   # first super admin
```

## Adding a country later

1. Add the country to `config/nawy.php` → `countries` (currency, timezone, weekend, tax, phone code, locales) and to `website/lib/markets.ts`.
2. `php artisan nawy:countries:create-databases && php artisan nawy:countries:migrate --country={cc} && php artisan nawy:countries:sync`.
3. Configure gateways, settings overrides, shipping zones and contracts for the country in the admin.
4. Reference data (categories, brands…) is per country: copy with the admin "copy to countries" tools.

## Every deploy

`php artisan migrate --force && php artisan nawy:countries:migrate && php artisan nawy:reporting:migrate` — all are idempotent (reporting database: [reporting-database.md](reporting-database.md)). Run them before switching traffic to the new release (migrations are additive; see [rollback.md](rollback.md)).

## Users

The application uses one MySQL user with rights on `nawy_central`, `nawy_*` and (during the migration only) **read-only** rights on `nawy_legacy_snapshot`. Backups use a separate read-only user.
