# QA + QC issue tracker

Sources:
- [qa-issues-qa.md](qa-issues-qa.md): 49 issues (3 critical, 9 major, 24 minor, 13 trivial).
- [qa-issues-qc.md](qa-issues-qc.md): 80 issues (1 critical, 27 major, 39 minor, 13 trivial).

When a fix agent closes an issue, it marks the row in the source file with `✅ fixed (wave N)` and names the test that proves it. QA re-tests each wave before the next wave starts.

| Wave | Scope | Owner | Status |
|---|---|---|---|
| 1 | **Security and money integrity.** QA-001–QA-010; QC-001 (disputes workflow); QC-017 (seller agreement gate on ops API); QC-020 (partner-panel staff permissions); Sanctum token expiry; Thawani webhook replay window; return photos moved to a private disk; review-moderation permissions seeded; the remaining QA security and money minors. | backend | ✅ (742 tests green; contract changes in storefront-api-additions.md "QA fixes") |
| 2 | **Admin back office.** QA-011; admin queue for `under_review` payments; admin-created drivers via invitation; QC-002–QC-004. Catalog (categories, brands, products), coupons (including BOGO and scopes, QC-014), gift cards and vouchers, Nawy One plans, admin users and roles, account-manager scoping (QC-019), refunds, disputes, warranty, activity log, system health; nav links that go to `#`. | backend | ✅ (778 tests green; QA-011, QA-030, QC-002, QC-003, QC-004, QC-014, QC-019, QC-029, QC-030 fixed, QC-044 partly; contract changes in storefront-api-additions.md "QA fixes (wave 2)") |
| 3 | **Platform parity.** QC-028 (Arabic backend language files and locale); QC-005/QC-006 (business-panel password reset; customer password and social sign-in need an owner decision); QC-012/QC-013 (review states, vendor replies, disputed overlay); QC-015/QC-016 (missing scheduled jobs and lifecycles); remaining backend requirement majors. | backend | ✅ (820 tests green; QC-005, QC-006, QC-009, QC-010, QC-012, QC-013, QC-015, QC-016, QC-018, QC-024, QC-025 partly, QC-028, QC-045, QA-045 fixed; QC-011 deferred for an owner decision; contract changes and wave 4 client list in storefront-api-additions.md "QA fixes (wave 3)") |
| 4 | **Website, apps and design.** QA-012 (real 404 status codes); QC-023 (footer payment badges per country); QC-021/QC-022 (Figma PayPal and travel-dashboard revenue); website, Flutter and design minors. **Wave 1 client changes:** join-wizard email code step, cart `merge_warnings`, `under_review` payment status, no caching of signed return photos; apps: 401 → sign in again, delivery `cancelled` assignments, vendor Seller Agreement screen and staff 403, supervisor suspension-refused message. | frontend, flutter, design | ✅ (4a website, 4b Flutter, 4c Figma; 822 backend tests, all app suites green) |
| 5 | **Remaining minors and trivials**, then a full QA re-test. 5a backend: every open backend row plus the feature-sized APIs (QC-007 support tickets, QC-008 storefront, QC-026 orders, QC-027 facets, QC-040 live comments, QC-043 discovery, QC-057/060/062/064, G-A1–G-A8). 5b: the client screens listed in storefront-api-additions.md "QA fixes (wave 5a)". | backend (5a), frontend + flutter (5b) | 🔄 5a ✅ (backend; QC-011 owner decision, QC-044 domains/OpenAPI and QC-052 legacy sweep deferred); 5b pending |

## WPT final run (2026-11-18)

Playwright end-to-end run (`website/e2e`, production build against `php artisan serve` on the dev databases). Evidence paths are relative to `website/`. Full report: [test-report.md](test-report.md).

| ID | Severity | Page / flow | Evidence | Owner | Status |
|---|---|---|---|---|---|
| E2E-001 | trivial | Every storefront 404 page (`/uae-en/cart/nope`, `/omn-ar/products/…`, 36 pages) | Console: `Failed to load resource: the server responded with a status of 404 (Not Found) [http://localhost:3000/uae-ar/cart/nope?_rsc=…]`. The header language link prefetched the other-language page, which is also a 404. Screenshot `e2e/screenshots/uae-en/cart__nope.png`. | website | ✅ fixed: `LanguageLink` uses `prefetch={false}` (`components/layout/header.tsx`); the other language is a different root layout, so the prefetch never helped. Covered by `pages.spec.ts` "real 404s" (console clean). |
| E2E-002 | minor | Creator Studio → Links & codes (`marketer.nawy.test/links`) | The page listed codes and URLs but had no copy control; only the overview had "Copy link". The "copy a link" key action could not be done on the links page. Screenshot `e2e/screenshots/panels/marketer/links.png`. | backend (panel) | ✅ fixed: a "Copy link" button (`data-copy-text`) on every row of `panels/marketer/links.blade.php`. Test: `MarketerPanelsAndDocumentsTest` "offers a copy button for each referral link…"; e2e `panels.spec.ts` "copies a referral link". |
| E2E-003 | major | Account → My reviews → Write review → Submit (`/uae-en/account/reviews`) | The review was saved, but the modal showed "The server returned an unexpected response.". `POST reviews` returned `"helpful_count": null` for the new review, and the storefront schema requires a number. | backend | ✅ fixed: `ReviewResource` casts `helpful_count` to int. Test: `ReviewsAndQuestionsTest` asserts `data.helpful_count = 0`; e2e `shopping.spec.ts` "write a review…". |
| E2E-004 | major | Account → Addresses → Add address (any market without delivery areas; uae today) | "The city field is required." after typing a city. With no `delivery-areas` the free-text city field is shown, but the form still sent the (empty) list value. No address could be saved from the address book. | website | ✅ fixed: `features/account/address-form.tsx` sends `city_free` whenever the list is not shown. Tests: `tests/features/address-form.test.tsx` (2); e2e `shopping.spec.ts` "address book…". |
| E2E-005 | minor | Customer app: page-builder text blocks and static pages | Found while filling Flutter gaps: `stripHtml` (`customer_app/lib/features/page_builder/blocks.dart`) and `plainText` (`customer_app/lib/screens/account/static_page_screen.dart`) decoded `&amp;` before `&lt;`/`&gt;`, so the text "&amp;lt;b&amp;gt;" showed as `<b>`. `stripHtml` also left `&#39;` undecoded. | flutter | ✅ fixed: `&amp;` is decoded last in both, and `stripHtml` decodes `&#39;`. Test: `customer_app/test/unit/gaps_test.dart` "decode an escaped entity only once" (was skipped, now runs). |

**Harness notes (not product defects):**
- Plain-http panel hosts are not a secure context, so the browser has no `navigator.clipboard` there. The marketer copy test stands in a minimal clipboard; on https the real one is used.
- The Laravel log mailer writes bodies unencoded. An early version of the e2e mail reader decoded `=XX` and corrupted reset tokens (`token=85…`). It now undoes soft line breaks only.

**Needs owner input or data (not failures; the pages are skipped and reported):**
- No brands exist in the uae dev DB, so `/brands/{slug}` has no real slug.
- Only uae has products, so `products/{slug}` is skipped in the other 6 countries' subset.
- There are no disputes or warranty claims for the e2e customer, so their detail pages are skipped.
