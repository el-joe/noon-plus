# Build guide for every build agent

Read this first, then the work package you were given in `docs/project/BUILD_QUEUE.md`.

## Sources of truth
- `MASTER_PLAN.md` (v3): decisions and ADRs. Follow them exactly: 8 countries, email sign-in for customers, admin in Oman time, admin-only media, and the delivery code-match flow.
- `old_project_overview.md` (SRS): the parity baseline. Keep its rules, states and job names.
- `docs/project/`: PRD, lifecycle catalogue, settings catalogue, role-permission matrix and country reference.
- `docs/design/figma-state.json` and `design-coverage.md`: Figma frames, file `d2MhBJmRddMrMSHfjJY8Y8`. Use the Figma MCP `get_design_context` / `get_screenshot` before building UI.
- `STATUS.md`: what is done. Update its line for your work package when you finish.

## Backend conventions (`backend/`, Laravel 13, PHP 8.3)
- Read `backend/CLAUDE.md` and the skills in `backend/.claude/skills/` (laravel-best-practices, testing-best-practices, tailwindcss-development, configuring-horizon). Follow them.
- **Layout:** domain code goes in `app/Domains/{Domain}/{Models,Enums,Support,Exceptions,Http/Controllers,Http/Requests,Http/Resources,Console}`. Shared code goes in `app/Shared`.
- **Models:** use attributes, not properties: `#[Fillable]`, `#[Hidden]`, `#[UseFactory(...)]`. Casts go in `casts()`.
- **Commands:** use `#[Signature]` / `#[Description]`, and register their folders in `bootstrap/app.php` `withCommands`.
- **Country data:**
  - Operational data lives in the 8 country databases. Put the migration in `database/migrations/country/` and add `use UsesCountryDatabase;` to the model.
  - Identity, countries, settings and other global data go in central `database/migrations/`.
  - `CountryContext` selects the country. Jobs run per country through `App\Shared\Scheduling\ForEachCountry`.
- **Money:** use `DECIMAL(19,4)` columns and `App\Shared\Money\MoneyFactory` (brick/money, half-up, 3 decimals for OMR/KWD/BHD/JOD). Never use floats. Prices include VAT.
- **State changes:** every lifecycle is a state-machine class with allowed transitions, guards, and a history row with actor. See `Listings/Support/ListingStateMachine` and `Orders/Support/SubOrderStateMachine`.
- **API:**
  - Responses use `App\Shared\Http\ApiResponse` (`{data, meta}` / `{error:{code,message,fields}}`).
  - Routes go in `routes/api.php` under `v1/{market}`, with `ResolveMarket`.
  - Customer auth is Sanctum. Use Form Requests and Resources.
- **Panels:**
  - Blade with `<x-ui.*>` components (`resources/views/components/ui`) and the `<x-layouts.panel>` layout.
  - Tailwind tokens live in `resources/css/app.css`; JS is vanilla.
  - Panels are registered in `config/nawy.php` → `panels`, with routes in `routes/web.php` per panel host.
  - Navigation is in `app/Domains/Panels/Navigation/PanelNavigation.php`.
  - Admin date-times use `<x-ui.datetime-input>` (shows the Oman time).
- **Settings:** use `App\Domains\Settings\SettingsService`, with country overrides and defaults in `config/nawy.php`.
- **Audit:** use Spatie activitylog `LogsActivity` on admin-editable central models.
- **Dependencies:** do not add composer or npm packages. Build adapters with fake/test drivers instead. If a package is truly required, stop and report it.

## Tests (must stay green)
- Use Pest feature tests in `backend/tests/Feature`, with `uses(RefreshDatabase::class)`.
- For country data, call `$this->setUpCountries(); $this->actAsCountry('uae');`.
- Helpers in `tests/Pest.php`: `sellableListing()`, `customerWithToken()`.
- When one test switches between tokens, call `app('auth')->forgetGuards()` between requests.
- Tests run on MySQL test databases (`nawy_test_*`).
  - Run the whole suite with `cd backend && php artisan test --compact`; it must pass before you finish.
  - Run `vendor/bin/pint --format agent <changed paths>` before finishing.
- Cover every decision: branches, validation, money maths, authorization and cross-country isolation.

## Frontend / apps
- **`website/`:** Next.js 16 App Router with next-intl, react-query, zod, embla and lucide. Routes are `[market]` (e.g. `uae-en`). Consume the backend API. Match the Figma frames and use Tailwind tokens mirrored from Figma. Arabic is right-to-left.
- **`customer_app/`, `delivery_app/`, and the ops apps (`vendor_app/`, `marketer_app/`, `supervisor_app/`, `travel_agency_app/`):** Flutter. Follow the Figma app pages.

## Finish checklist
1. All tests pass; Pint has run.
2. Update your line in `STATUS.md`: what is done and tested, and what is left.
3. Reply with:
   - a short summary;
   - files and areas touched;
   - the test count;
   - anything blocked or deferred, with the reason.
