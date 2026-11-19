# WPT final test report (2026-11-18)

The last step in BUILD_QUEUE: automated tests on every layer, then Playwright end to end over the storefront, the portal and the six Blade panels. The run used the dev databases. Every record it created is marked `e2e` and comes from `E2eSeeder`, which refuses to run in production. Issues are listed in [qa-issues.md](qa-issues.md) under "WPT final run".

## Suite counts

| Suite | Command | Result |
|---|---|---|
| Backend (Pest, feature + unit) | `cd backend && env -u CLAUDECODE -u AI_AGENT php vendor/bin/pest --compact` | ****1,254 / 1,254 passed** (8,241 assertions, 495 s; 908 before)** |
| … of which new unit tests | `tests/Unit` (24 files) | 344 (343 new). Covers `MoneyFactory`, `MoneyFormatter`, `InLocale`, `Label`, `LikeSearch`, `CountryPhone`, every lifecycle enum's transition graph (55 enums, `LifecycleGraphsTest`), full tables for sub-order, listing, payment, refund, return and payout, the `SubOrderStateMachine` guards, coupon validator rules and targets, the shipping fee calculator, billable weight and small enums. None of them touch the DB. |
| … of which new feature tests | `E2eSeederTest` (2), plus assertions added for E2E-002 and E2E-003 | included above |
| Website unit + component (Vitest + Testing Library + jsdom) | `cd website && npm run test` | **207 / 207 passed** (18 files) |
| Website type check | `npx tsc --noEmit` | exit 0 |
| Website build | `npm run build` | exit 0, no warnings |
| Website e2e (Playwright, Chromium) | `npm run e2e` | **399 passed, 14 skipped (no data, listed below), 0 failed** (3.1 min, 4 workers) |
| **Re-run after WP11** (legacy data in the dev DBs) | Pest / `npm run test` / `npm run e2e` | Pest **1,328 / 1,328** (9,062 assertions, 726 s); website **224 / 224**; e2e **404 passed, 12 skipped, 0 failed** (4.5 min) after E2E-006…008 below; `legacy:reconcile` clean |
| **Final full run** (2026-11-19, after D16 packages + last design pass) | Pest, Pint, `npm test`, `tsc`, `npm run build`, `npm run e2e`, `flutter analyze` + `flutter test` ×7 | Pest **1,330 / 1,330** (9,079 assertions); Pint clean; website **227 / 227**; tsc clean; build OK; e2e **404 passed, 12 skipped, 0 failed** (4.4 min); Flutter analyze clean in all 7; tests nawy_core 71, customer 192, delivery 60, vendor 46, marketer 26, supervisor 26, travel 38 (**459**) |
| Flutter `flutter analyze` | per package | 0 issues in all 7 |
| Flutter `flutter test` | per package | nawy_core 58, customer_app 167, delivery_app 51, vendor_app 43, marketer_app 26, supervisor_app 25, travel_agency_app 25: **395 / 395 passed** (322 before, 73 added) |

Website unit tests cover:
- `lib/`: markets, format/money (including 3-decimal OMR/KWD/BHD/JOD and negatives), sanitize, the API client envelope and errors, the zod schemas, missing-routes, merge-warnings, i18n (EN and AR have the same keys).
- Components: product card, status badge, carousel RTL direction, cart summary (totals and coupon errors), cart view (lines, quantity, free-delivery bar, measurements gate, merge banner), free-delivery bar, the page-builder renderer (unknown blocks skipped, HTML sanitised, Arabic settings, a crashing block isolated) and the address form (E2E-004).

## How to run the e2e suite

1. **One-time setup:** `cd backend && php artisan storage:link` (uploaded media — ad photos, avatars, review images — are served from `public/storage`; without the link those URLs answer 404 and the photo / avatar steps fail).
2. **Backend:** `cd backend && PHP_CLI_SERVER_WORKERS=6 php artisan serve --port=8000`. Use the dev `.env` with `APP_ENV=local`, `MAIL_MAILER=log` and `NAWY_PAYMENTS_DRIVER=fake`. `APP_ENV=local` matters for the API rate limit (`NAWY_API_RATE_PER_MINUTE`, 600/min per user or IP): every e2e request comes from 127.0.0.1, so a `local` app exempts loopback callers (other environments and addresses stay limited; `SecurityHardeningTest`).
3. **Storefront:** `cd website && npm run build && npx next start -p 3000`.
4. **Run:** `cd website && npm run e2e`. If steps 2 and 3 are not running, `webServer` in `playwright.config.ts` starts them; it does not build first.

Notes:
- **Panel hosts:** Chromium maps `*.nawy.test` to 127.0.0.1 with `--host-resolver-rules`, so `/etc/hosts` needs no entries.
- **Global setup** (`e2e/support/global-setup.ts`):
  - runs `php artisan db:seed --class=E2eSeeder` (no `--env`, never migrates); set `E2E_SKIP_SEED=1` to skip it;
  - signs the e2e customer in through the API for the signed-in crawl. A stored session (`e2e/.auth/customer.json`) whose token still works is reused without a sign-in; the password sign-in (30 an hour, 5 a minute per account) runs only when there is none, and on a 429 the stored session is kept if it still works (QA-C07). `E2E_FRESH_LOGIN=1` forces a new sign-in.
  - `E2E_NO_GLOBAL_SETUP=1` skips global setup altogether (no seed, no sign-in) and reuses the files of the last run (`e2e/.generated/*`, `e2e/.auth/*.json`). Use it to re-run one area without touching the sign-in limit: `E2E_NO_GLOBAL_SETUP=1 npx playwright test --project=areas --no-deps [file]` (`--no-deps` also skips the `panel-auth` project, so the panel sessions of the last run are used). The admin specs' own `e2e/specs/areas/admin/.qa.config.ts` does the same (`npx playwright test -c e2e/specs/areas/admin/.qa.config.ts …`). Switches: `e2e/support/setup-options.ts` (Vitest `tests/lib/e2e-setup-options.test.ts`).
- **Mail codes** (customer sign-in, panel 2FA, partner application, password reset) are read from `backend/storage/logs/laravel.log` (`e2e/support/mail.ts`).
- **Fake gateway:** its page (`https://pay.fake.test/…`) is intercepted. The test scripts the outcome with `FakeGateway::willReport` through `artisan tinker`, then follows Laravel's return URL.
- **Output** is all git-ignored:
  - `e2e/screenshots/`
  - `e2e/report/` (HTML report)
  - `e2e/results.json`
  - `e2e/test-results/` (traces of failures)

## Pages covered

The route list comes from `website/app/[market]/**/page.tsx` (95 patterns, not counting the internal `missing`). Dynamic segments get real slugs from the API at run time (`e2e/support/routes.ts`). Account pages and the shopper's own travel bookings use the signed-in e2e customer.

Each page is checked for:
- the HTTP status (200, or 404 for the bogus paths);
- no console errors and no uncaught page errors;
- no raw next-intl keys (exact match against every key path in `messages/`);
- `lang` and `dir`;
- no "Application error" or digest text;
- not bounced to `/login` when signed in;
- a full-page screenshot;
- its internal links, collected for the link check.

| Market | Pages visited | Skipped (no record in the dev DB) |
|---|---|---|
| uae-en | 92 | `brands/[slug]` (no brands), `account/disputes/[number]`, `account/warranty/[number]` (no dispute or warranty claim for the e2e customer) |
| uae-ar | 92 | same 3 |
| omn-ar, omn-en, ksa-ar, egy-en, kwt-ar, qat-en, bhr-ar, jor-en | 18 each (subset of 19: home, search, cart, deals, help, category, travel, classified, property, membership, gift cards, login, sell, join, static page, blog, account, account/orders) | `products/[slug]` in each (only uae has products) |
| **Real 404s** (uae-en, uae-ar, omn-ar) | 12 bogus paths each, plus `/xyz-en/cart` | none |

In total:
- **365 page visits**, all green.
- **Link check:** 1,118 distinct internal links from those pages, each requested once with the signed-in session. None was 404 or 5xx.
- **Panels:** every sidebar link was visited after signing in with the emailed 2FA code. Each got a 200, no 500 text, no raw lang keys, no console errors, and a screenshot:
  - admin 46
  - Seller Center 16
  - Travel Partner 8
  - Shipping (carrier) 11
  - Delivery 6
  - Creator Studio 11

## Flows covered (all green)

| Flow | Spec |
|---|---|
| Email-code sign-in (code from the mail log); a new account by code in Arabic | `flows/auth.spec.ts` |
| Password sign-in; wrong password refused | `flows/auth.spec.ts` |
| Forgot password: the emailed link sets a password and signs in, then password sign-in | `flows/auth.spec.ts` |
| Locale switch EN ↔ AR keeps the page, `dir` and `lang` | `flows/auth.spec.ts` |
| Home → category PLP → filter ("Shipped by seller", Apply) → PDP → add to cart as a guest → checkout asks to sign in → guest cart merged → address → card (fake gateway) → payment return → "Thank you" → order detail → cancel the shipment with a reason | `flows/shopping.spec.ts` |
| Return request on a delivered shipment (refund to Nawy Credits) | `flows/shopping.spec.ts` |
| Review of a delivered item; wishlist add and remove; address book add, set default and delete | `flows/shopping.spec.ts` |
| D8 guest travel request → "request sent" → booking status "Requested" → Travel Partner panel confirms → status "Confirmed" | `flows/travel.spec.ts` |
| D8 signed-in travel request → listed under My bookings | `flows/travel.spec.ts` |
| Partner application wizard (travel agency): account → emailed code → agency (logo upload) → agreement → submitted | `flows/portal-and-support.spec.ts` |
| Support ticket opened | `flows/portal-and-support.spec.ts` |
| Classified (property) viewing request | `flows/portal-and-support.spec.ts` |
| Compare two products from their PDPs | `flows/portal-and-support.spec.ts` |
| Sign-in to all 6 panels with email 2FA | `panel-auth.setup.ts` |
| Admin approves a pending listing | `panels.spec.ts` |
| Seller confirms a placed sub-order | `panels.spec.ts` |
| Carrier assigns a driver, then the driver: accept → picked up → arrived. The customer's order page shows the same 4-digit code as the driver's screen. The driver signs and taps "Codes match — delivered", typing nothing (ADR 15). | `panels.spec.ts` |
| Marketer copies a referral link | `panels.spec.ts` |
| Travel agency confirms a booking request | `flows/travel.spec.ts` |

## Screenshot index

All paths are under `website/e2e/screenshots/`. There are about 500 PNGs (about 67 MB) per run.

| Folder | Content |
|---|---|
| `uae-en/`, `uae-ar/` | One per page. Static routes use the path with `__` for `/` (`account__orders.png`). Dynamic routes use `{pattern}--{slug}` (`products__slug--pulse-smart-watch-5.png`). 404 pages use the bogus path (`cart__nope.png`). |
| `omn-ar/`, `omn-en/`, `ksa-ar/`, `egy-en/`, `kwt-ar/`, `qat-en/`, `bhr-ar/`, `jor-en/` | The subset pages; `omn-ar` also has the 404 pages. |
| `panels/{admin,partner,travel-agency,carrier,delivery,marketer}/` | One per sidebar link (`dashboard.png`, `orders.png`, `approvals.png`, …). |
| `panels/actions/` | `admin-approve-listing`, `seller-confirm-sub-order`, `carrier-assign-driver`, `delivery-code-match-confirm`, `marketer-copy-link`, `travel-confirm-booking`. |
| `flows/` | `auth-email-code-account`, `auth-reset-password-done`, `locale-switch-ar`, `shop-01-plp-filtered` … `shop-07-cancelled`, `order-detail-delivery-code`, `account-return-requested`, `account-review-submitted`, `account-wishlist`, `account-address-added`, `travel-book-form-{guest,signed-in}`, `travel-guest-{sent,status-requested,status-confirmed}`, `travel-signed-in-my-bookings`, `partner-01-account` … `partner-submitted`, `support-ticket-opened`, `classified-viewing-request`, `compare`. |

## Fixes made

| ID | Fix | Test |
|---|---|---|
| E2E-001 (trivial, website) | The header language link no longer prefetches. On every 404 page, the prefetched other-language 404 was a console error. | `pages.spec.ts` "real 404s" |
| E2E-002 (minor, panel) | Creator Studio → Links & codes has a "Copy link" button per row. | `MarketerPanelsAndDocumentsTest`, `panels.spec.ts` |
| E2E-003 (major, backend) | `ReviewResource` returns `helpful_count` as a number. A new review was saved, but the storefront showed "unexpected response". | `ReviewsAndQuestionsTest`, `shopping.spec.ts` |
| E2E-004 (major, website) | The address book sends the typed city when the market has no delivery areas. Before, no address could be saved in uae. | `tests/features/address-form.test.tsx`, `shopping.spec.ts` |
| E2E-005 (minor, Flutter) | `stripHtml` and `plainText` decode `&amp;` last, and `stripHtml` decodes `&#39;`. | `customer_app/test/unit/gaps_test.dart` |
| E2E-006 (major, backend, after WP11) | The default API limit (600/min per user or IP) answered 164 × 429 in the e2e run, where every request comes from 127.0.0.1. Only an `APP_ENV=local` app now exempts loopback callers; every other environment and address keeps the limit. | `SecurityHardeningTest` "spares loopback callers … only in a local app" |
| E2E-007 (major, migration) | `/{market}/electronics/accessories_el` and `/smart_home` were 404 in every market. The storefront's category segment is `[a-z0-9-]`, and WP11 kept 17 legacy slugs with underscores. `CatalogStep::categorySlug()` normalises them (`smart-home`; `mens_clothing` → `mens-clothing-legacy`, because a seeded `mens-clothing` holds the slug), the dev DBs were updated by `legacy:migrate catalog` (136 rows renamed in place, a second run changes nothing), and `legacy:redirects` maps the old URLs. | `LegacyMigrationTest` "category slugs become storefront URL segments …", `links.spec.ts` |
| E2E-008 (major, migration) | Seller "confirms a sub-order" was sent to "Sign the updated Seller Agreement": WP11 imported the legacy global Seller Agreement ("French · v1") as published, so it applied to every store in every country. Legacy template versions are now imported as drafts for admin review (dev data updated by `legacy:migrate contracts`; publishing a draft keeps the migrated signatures of that version), and `E2eSeeder` signs whatever Seller Agreement is in force for the e2e stores. | `LegacyMigrationTest` "legacy contract templates arrive as drafts …", `E2eSeederTest` "signs the Seller Agreement …", `panels.spec.ts` |

Test tooling added:
- `website`: devDependencies `@playwright/test`, `vitest`, `@testing-library/react`, `@testing-library/dom` (peer of the former) and `jsdom`. Chromium was downloaded with `npx playwright install chromium`; no system packages were needed.
- Scripts: `test`, `test:watch`, `e2e`, `e2e:report`.
- Files: `vitest.config.mts`, `playwright.config.ts`, `website/tests/**`, `website/e2e/**`.
- Backend: `database/seeders/E2eSeeder.php` and `tests/Feature/E2eSeederTest.php`.

## Remaining items

- **Data the dev DB does not have:**
  - brands, so `/brands/{slug}` has no real slug;
  - products outside uae, so 3-decimal checkout in the browser is still covered only by unit tests (OMR/KWD/BHD/JOD formatting on the website, `MoneyFactory` in the backend, Flutter money);
  - disputes or warranty claims for the e2e customer.

  Seeding these is an owner or content decision; the pages are reported as skipped, not failed.
- **Not exercised in a browser:**
  - real gateways, LiveKit, FCM and Google/Apple (fake drivers only);
  - the seller-agreement and classified contract gates (there are no contract versions; see RT-007);
  - true concurrency.
- **Dev DB growth:** each e2e run adds test records (orders `NW-AE-E2E…`, a return, a review, a ticket, travel bookings, one partner application, new `e2e-new-…@nawy.test` / `e2e-traveller-…` / `e2e-partner-…` emails). They are all marked e2e and can be removed with a cleanup query when needed.
