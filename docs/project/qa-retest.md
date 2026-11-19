# QA re-test after fix waves 1–5 (2026-11-18)

**Scope:** every QA row marked fixed, a sample of 32 QC rows that can be checked against the running system, and a regression sweep of the core flows. Testing ran against `php artisan serve` (:8000, 4 workers) and `next dev` (:3000), on the dev databases only. Customer codes were read from `storage/logs/laravel.log`, and the fake gateway and fake social driver were used. No code was changed.

## Summary

| Result | QA rows (49) | QC rows re-tested (32 of 75 fixed) | Total |
|---|---|---|---|
| VERIFIED | 45 | 31 | **76** |
| PARTIAL | 4 (QA-005, QA-010, QA-012, QA-043) | 1 (QC-008) | **5** |
| NOT FIXED | 0 | 0 | **0** |

**New regressions:** 13 (RT-001…RT-013): 2 major, 5 minor, 6 trivial.

**Top issues:**
- **RT-001 (major):** the QA-005 guard `AgentAssignments::guardSubOrderOpen()` compares the cast enum with strings, so it never fires. An open assignment on a sub-order that was already cancelled could still be confirmed as delivered (reproduced on NW-AE-1000013-1).
- **RT-002 (major):** QA-010 only caps each guest cart line at 3 units. One IP sending a few requests without `X-Cart-Token` still holds a listing's whole stock.
- **RT-003 (minor):** the agent assignment payload still leaks `address_id`. The wave 5a test asserts the wrong JSON path.
- **RT-004 (minor):** unknown sub-paths under the static website roots are still soft 404s.

**What still works:** the token scopes, the partner email proof, the carrier invitations, the COD fee split, the payment `under_review` hold, webhook duplicates, D9 sign-in (code, password, forgot/reset by code and by link, Google/Apple fake driver, web ticket and PKCE paths), coupons (percentage, BOGO, first-order, per-customer), disputes end to end, support tickets end to end, panels with no 500s, and cross-country isolation.

## Fix pass (2026-11-18)

All 5 partials (QA-005, QA-010, QA-012, QA-043, QC-008) and all 13 regressions (RT-001…RT-013) are fixed; each row below says how and which test covers it. New tests are in `tests/Feature/QaRetestFixesTest.php`; `QaWave5BackendFixesTest`, `PartnerApplicationsTest` and `QaWave3CustomerAuthTest` were corrected for RT-003, RT-006 and RT-008. Migrations: `carts.guest_ip_hash`, `partner_applications.resume_token_hashes` (run on dev with `nawy:countries:migrate`). Dev data: `nawy:delivery:close-stale-assignments --repair-delivered` repaired DA-NW-AE-1000013-1-12.

## Baseline suites

| Suite | Result |
|---|---|
| Backend `env -u CLAUDECODE -u AI_AGENT php vendor/bin/pest --compact` | **896 / 896 passed** (6,829 assertions, 484 s) |
| `flutter analyze` (7 packages) | 0 issues in all |
| `flutter test` | nawy_core 36, customer_app 148, delivery_app 43, vendor_app 36, marketer_app 20, supervisor_app 20, travel_agency_app 19: **322 / 322 passed** |
| website `npx tsc --noEmit` | exit 0 |
| website `npm run build` | exit 0, no warnings |

## Verification table

Evidence is from live calls unless it says otherwise. Paths are relative to `/api/v1/`.

### QA rows

| ID | Orig. sev. | Result | Evidence |
|---|---|---|---|
| QA-001 | critical | VERIFIED | A storefront email-code token on `uae-en/delivery/me` and `delivery/assignments` gets 403 `wrong_token`. On `ops/supervisor/drivers` it gets 403 `no_workspace`. A real `delivery/auth/login` token gets 200. |
| QA-002 | critical | VERIFIED | `POST partner-applications` with the victim's email (qa1-b) mails the code to the victim. Submit before verifying gets 422 `fields.email_code`, and a wrong code gets 422. `ops/auth/login` with the attacker's password gets 422. A full wizard on a new email (APP-UAE-MGLAYJ) verifies, submits and is approved, and the new vendor signs in to ops. |
| QA-003 | critical | VERIFIED | Carrier `POST /agents` with qa2-vendor-c and `POST /supervisors` with qa3-travel-a both say "We emailed an invitation…". No `role_memberships` row is created and no stored name is shown. |
| QA-004 | major | VERIFIED | Delivery, ops vendor and ops supervisor tokens on all 33 parameterless customer GET routes get 401, except the documented `me/notifications*`. `POST addresses` with a delivery token gets 401. |
| QA-005 | major | **PARTIAL** → ✅ fixed (retest, RT-001) | A vendor cancel while out for delivery gets 422 (`can_cancel:false`). An admin force-cancel closes the assignment, and the later confirm gets 422. **But** confirm on legacy assignment DA-NW-AE-1000013-1-12 (sub-order `cancelled`) returned **200 delivered**: shipment 8 is `delivered` while the order is `cancelled`. See RT-001. |
| QA-006 | major | VERIFIED | Two-seller COD order NW-AE-1000018 (408): `cod_amount` is 209 + 199. The ledger collections are 209 and 199. |
| QA-007 | major | VERIFIED | `ops/supervisor/drivers/4/status {suspended}` with open parcels gets 422 `transition_refused` ("Reassign … before suspending"). Another carrier's driver gets 404. |
| QA-008 | major | VERIFIED | Merge with flash listing 1 over the limit returns 200 with `merge_warnings[{code: flash_sale_customer_limit, requested 3, merged 2}]`. The other guest line (listing 11 × 2) is merged and the guest cart is emptied. |
| QA-009 | major | VERIFIED | NW-AE-1000019 (899): a signed webhook with `amount 1.00` leaves the order `under_review`, with failure `amount_mismatch` and `next_action: none`. |
| QA-010 | major | **PARTIAL** → ✅ fixed (retest, RT-002) | Holds are capped at 3 per guest line, travel guest holds over 4 seats get 422, and the 3rd hold in an hour gets 429. **But** guest carts sent without a token each hold 3 more units: 5 requests took listing 14 from 15 to 0, after which another shopper got 422 `insufficient_stock`. See RT-002. |
| QA-011 | major | VERIFIED | The admin created coupons RT10, RTBOGO and RTFIRST. `membership/plans` returns 1 plan in uae and omn, and `omn-ar/categories` returns 3. |
| QA-012 | major | **PARTIAL** → ✅ fixed (retest, RT-004) | All 14 originally listed routes return 404 on uae-en, uae-ar and omn-ar. Signed-in account, return, support, dispute and booking details return 404, and existing items return 200. **But** `help/nope`, `adsupport/nope`, `account/nope`, `cart/nope`, `checkout/nope`, `deals/nope`, `membership/nope`, `sell/nope`, `products/a/b` and `live/a/b` return **200** with `NEXT_HTTP_ERROR_FALLBACK;404`. `/uae-en/property/CL-AE-DEMO1` returned 200 once under crawl load (the proxy check fails open) and 404 on retry. See RT-004. |
| QA-013 | minor | VERIFIED | An inline `address.phone:"abc"` gets 422 "Enter a valid United Arab Emirates phone number." |
| QA-014 | minor | VERIFIED | The 11th `cart/coupons` call within a minute gets 429 with `Retry-After: 60`. |
| QA-015 | minor | VERIFIED | The coupon limiter at 429 did not block cart writes from the same IP. Login limiters are per route (checked by sampling). |
| QA-016 | minor | VERIFIED | COD order NW-AE-1000023 cancelled: order `payment_status: expired`, payment `voided`. |
| QA-017 | minor | VERIFIED | Listing 1: `in_stock 8`, flash `remaining 8`. |
| QA-018 | minor | VERIFIED | A guest hold of 3 on listing 13 lowers PDP `in_stock` from 14 to 11. |
| QA-019 | minor | VERIFIED | `preferred_at "2026-12-20 10:00"` is returned as `2026-12-20T10:00:00+04:00`. |
| QA-020 | minor | VERIFIED | `contact_phone:"not-a-phone"` gets 422 with a per-country message. |
| QA-021 | minor | VERIFIED | Signing in with the email-change code gets 422 and no account is created. After a sign-in code was sent to the new address, `me/email/verify` with the change code still succeeds. |
| QA-022 | minor | VERIFIED | `From: Nawy <no-reply@nawy.test>`, and "© 2026 Nawy". See RT-013 for the header link. |
| QA-023 | minor | VERIFIED | Bands: AED under 1,000; OMR and KWD under 100; JOD under 200; EGP under 10,000. |
| QA-024 | minor | VERIFIED | Notices read "Shipment NW-AE-1000018-1 of your order … is delivered", with one notice per shipment. |
| QA-025 | minor | VERIFIED | `arrive` on an `assigned` assignment gets 422 `transition_refused`. |
| QA-026 | minor | VERIFIED | After two failures, `last_failed_at` is 08:40:35, the latest (the first was 08:39:32). |
| QA-027 | minor | VERIFIED | Admin `/documents`, `/marketers`, `/marketers/campaigns` and `/applications` with `[]` parameters, and partner `/campaigns?status[]=x`, all return 200. |
| QA-028 | minor | VERIFIED | `/orders?q[]=x` followed by `/orders` returns 200 on admin and partner, as do `/products` and `/applications`. |
| QA-029 | minor | VERIFIED | qa2-support `/reviews` returns 200. |
| QA-030 | minor | VERIFIED | The sidebar links for support (11), content (11) and finance (19) contain no 403 targets. |
| QA-031 | minor | VERIFIED | `/xyz-en/cart` and `/uae-fr` return 404. `/UAE-EN/cart` returns 308 to `/uae-en/cart`. |
| QA-032 | minor | VERIFIED | `static-pages/privacy-policy` has `placeholder:true`. The web banner shows in EN and AR. |
| QA-033 | minor | VERIFIED | `/uae-ar/classified/find/CL-AE-0000004` has Arabic labels. The only English left is the ad's own title. |
| QA-034 | minor | VERIFIED | `/uae-ar/property/CL-AE-0000004` shows "سنوي". |
| QA-035 | minor | VERIFIED | `products?q=%`, `_` and `\` return 0 results. `search/suggest?q=%%` is empty. |
| QA-036 | minor | VERIFIED | New tokens have `expires_at`: customer +90 days, delivery and ops +30 days. |
| QA-037 | trivial | VERIFIED | Booking `rooms[].unit_price` and `line_total` are `{amount,currency}`. |
| QA-038 | trivial | VERIFIED | `omn-en/gift-cards` with stripe returns "This payment method is not available in Oman." |
| QA-039 | trivial | VERIFIED | `min:1980` and `max:2027` are numbers. |
| QA-040 | trivial | VERIFIED | `price 1000.555` in AED gets 422 "must have 0-2 decimal places". |
| QA-041 | trivial | VERIFIED | `reason:"bogus"` gets 422. `other` without a note gets 422. `changed_mind` returns 200. |
| QA-042 | trivial | VERIFIED | `delivery/me` `cod_in_hand` is `{amount:"836.00",currency:"AED"}`. |
| QA-043 | trivial | **PARTIAL** → ✅ fixed (retest, RT-003) | The customer `orders` / `shipping_address` has no `address_id`. **But** `delivery/assignments/{n}` still returns `customer.address.address_id: 10`. See RT-003. |
| QA-044 | trivial | VERIFIED | Product vendor `/marketplace/ads/create` returns 403. |
| QA-045 | trivial | VERIFIED | Horizon API returns 403 for a guest and 200 for an admin. |
| QA-046 | trivial | VERIFIED | A refused gift card for 0 gets 422 and adds no `local.ERROR` line to the log. |
| QA-047 | trivial | VERIFIED | A refused first write (`listing_unavailable`, or quantity over the limit) sends no `X-Cart-Token`. |
| QA-048 | trivial | VERIFIED | Signed-in titles: "Orders \| Nawy", "Ticket TK-AE-000002 \| Nawy", AR "الطلبات \| ناوي", and so on. |
| QA-049 | trivial | VERIFIED | The footer says "Help center" with no "centre". |

### QC rows re-tested

| ID | Orig. sev. | Result | Evidence |
|---|---|---|---|
| QC-001 | critical | VERIFIED | Dispute DS-NW-AE-1000018-2-1: vendor reply in Seller Center (another vendor gets 404), admin review, then resolve with a 50.00 split. The customer sees `resolved`, the refund goes to the wallet, and the order overlay clears. |
| QC-003 | major | VERIFIED | Coupons created through Admin → Coupons. Gift card and voucher screens return 200 in the crawl. |
| QC-005 | major | VERIFIED | Password set, login, change (`current_password` required), forgot (always 202), reset by code (old tokens revoked) and by link. Google `token` links on a verified email. Apple with an unverified email gets 422 `social_email_unverified`. Web redirect → callback → `exchange`: the ticket is single use and bound to its market. Unlink returns 404 the second time. `login-history.method` is recorded. |
| QC-006 | major | VERIFIED | Partner `/forgot-password` mails a `partner.nawy.test/reset-password/…` link. |
| QC-007 | major | VERIFIED | Ticket TK-AE-000002: validation, order IDOR, a vendor-only category refused, signed attachment (unsigned 403), IDOR and other country 404, admin reply then resolve, customer rating and reopen. The vendor app ticket works before the agreement is signed. |
| QC-008 | major | **PARTIAL** → ✅ fixed (retest, RT-005) | Store profile, products, follow (idempotent), followed list and 404s work. **But** the `sellers/{slug}/products/facets` `sellers` facet is not scoped to the store. See RT-005. |
| QC-012 | major | VERIFIED | Review published by the admin. Report works; a second report and a report on your own review get 422. Vendor reply is moderated, then shown as `seller_reply`. Another vendor is refused. |
| QC-013 | major | VERIFIED | The order shows `disputed` while the dispute is open and `delivered` after it is resolved. |
| QC-014 | major | VERIFIED | RT10 at 10 % (capped at 50), `coupon_already_used` on second use. BOGO 2+1 gives 199 off, and below 3 units a warning with 0 off. RTFIRST gets `coupon_first_order_only` and, for a guest, `coupon_sign_in_required`. |
| QC-015 | major | VERIFIED | `products?sort=bestselling` returns 200. |
| QC-023 | major | VERIFIED | The uae footer shows Tabby, Tamara and cards; omn shows Thawani. No PayPal. |
| QC-024 | major | VERIFIED | Admin dashboard KPIs, and `?export=csv` returns CSV. |
| QC-026 | major | VERIFIED | `orders` tabs, counts, per-shipment `actions`, `return_until`, the invoice (409 until paid, IDOR 404), and the signed `invoice-link` (a tampered link gets 403). |
| QC-027 | major | VERIFIED | Facets include `fulfilment`, `conditions` and `deals`. `fulfilment=fbn`, `condition=` and `deals=1` filter as expected. |
| QC-028 | major | VERIFIED | `-ar` markets return Arabic errors. The panel locale switch gives `dir=rtl lang=ar`. The Arabic booking mails were sent. |
| QC-030 | minor | VERIFIED | The panel crawl found no `#` or broken sidebar links. |
| QC-032 | minor | VERIFIED | A replayed webhook returns `duplicate`, and the same outcome on a new event also returns `duplicate`. |
| QC-041 | minor | VERIFIED | Seller Center `/notifications` lists NW-AE-1000018. |
| QC-043 | minor | VERIFIED | `suggest`, `trending`, `popular`, recently viewed sync (unknown slugs dropped), FBT and compare (1 slug gets 422). |
| QC-044 | minor | VERIFIED (partial scope as documented) | Carrier `/account/security` lists sign-ins. |
| QC-046 | minor | VERIFIED | Same evidence as QA-001 and QA-004. |
| QC-047 | minor | VERIFIED | Same evidence as QA-036. |
| QC-049 | minor | VERIFIED | Return photo is a signed URL (200, `Cache-Control: private, max-age=600`). Unsigned gets 403. |
| QC-057 | minor | VERIFIED | The PDP has `model_number`, `badges` and `products/{slug}/coupons` (the RT10 chip). |
| QC-060 | minor | VERIFIED | Trip `gallery`, `stats` and `child_price_per_person`. `children` on a departure with no child price gets 422 `children_not_offered`. |
| QC-062 | minor | VERIFIED | Seller "Returns open" and the report CSV. Carrier "SLA by zone" and the dispatch exceptions table. |
| QC-063 | minor | VERIFIED | `live-streams/floating` returns `null` (no live stream). |
| QC-064 | minor | VERIFIED | `offers` lists the public coupon RT10 and the flash sale. |
| QC-071 | minor | VERIFIED | Same evidence as QA-035. |
| QC-073 | trivial | VERIFIED | A preflight from `https://evil.example` gets `Access-Control-Allow-Origin: http://localhost:3000` only. |
| QC-076 | trivial | VERIFIED | `GET /` on the API returns 302 to the storefront. |
| QC-079 | trivial | VERIFIED | Arabic pages have no English aria-labels apart from the brand and product names. |

The other QC rows marked fixed are design, Figma, docs, Flutter-only or lifecycle internals. They are accepted on the green suites and were not re-run live.

## New regressions and gaps

| ID | Severity | Area | Title | Steps | Expected | Actual + evidence | Fix owner |
|---|---|---|---|---|---|---|---|
| RT-001 | major | backend | The QA-005 guard never fires: an open assignment on a cancelled sub-order can be delivered | Agent qa3-agent3 holds DA-NW-AE-1000013-1-12 (`picked_up`, from before the fix). Sub-order NW-AE-1000013-1 is `cancelled`. Call `POST uae-en/delivery/assignments/DA-NW-AE-1000013-1-12/confirm {codes_match:true, signature_data}`. | 422 "This order was cancelled. Return the parcel…" | **200 `delivered`**. Shipment 8 becomes `delivered` while the order is `cancelled`. Cause: `AgentAssignments::guardSubOrderOpen()` (`app/Domains/Delivery/Support/AgentAssignments.php:243-245`) runs `$assignment->subOrder()->value('status')`, which returns the cast **enum** (checked in tinker: `SubOrderStatus::Cancelled`), then `in_array(..., [strings], true)`, which is always false. The guard is dead code in `start` (line 69) and `confirm` (line 166). New cancels are safe only because they close the assignment. Legacy open assignments were not migrated. | backend — ✅ fixed (retest): `AgentAssignments::guardOpen()` reads the sub-order through the model and compares `SubOrderStatus` cases; start, proof and confirm refuse a cancelled assignment or a cancelled / completed / returned / refunded sub-order. One-off `nawy:delivery:close-stale-assignments [--repair-delivered] [--dry-run]` run on dev: 0 open assignments left; DA-NW-AE-1000013-1-12 repaired to `cancelled`, shipment 8 to `returning_to_origin` (history rows "Repair RT-001"). Tests: `QaRetestFixesTest` RT-001 group (enum read from the DB, HTTP confirm/start, command) |
| RT-002 | major | backend | One anonymous IP can still hold a listing's whole stock | As a guest, send `PUT uae-en/cart/items {listing_id:14, quantity:3}` 5 times **without** `X-Cart-Token` (each call creates a new cart). | A per-IP or per-visitor cap on the total guest hold, as QA-010 intended. | Listing 14 `in_stock` went 15 → 12 → 9 → 6 → 3 → 0. Another shopper then gets 422 `insufficient_stock` "Only 0 left in stock." Each hold lasts up to 60 minutes. The `cart-writes` limiter (30/min) allows about 90 units a minute from one IP. The holds were released afterwards. | backend — ✅ fixed (retest): guest carts store an HMAC of the creating IP (`carts.guest_ip_hash`); all guest carts of one IP hold at most `checkout.guest_hold_max_per_ip` (6) units of a listing (extra units stay in the cart unheld), and one IP opens at most `checkout.guest_carts_per_hour` (10) new carts (429 `too_many_carts`). Tests: `QaRetestFixesTest` RT-002 |
| RT-003 | minor | backend | The agent assignment still exposes the internal `address_id` (QA-043) | `GET uae-en/delivery/assignments/DA-NW-AE-1000018-1-15` with the agent token. | No `address_id`. | `"customer":{"address":{"area":"Marina","city":"Dubai","street":"S2","address_id":10}}`. The wave 5a test asserts `data.address.address_id` (`tests/Feature/QaWave5BackendFixesTest.php:175`), but the payload nests the address under `data.customer.address`, so the test passes without checking anything. | backend — ✅ fixed (retest): `AgentAssignmentResource` drops `address_id`; the wave 5a test now asserts `data.customer.address.*` |
| RT-004 | minor | website | Unknown sub-paths under static roots are still soft 404s, and the proxy check fails open under load | `curl -o /dev/null -w '%{http_code}' localhost:3000/uae-en/help/nope` (also `adsupport/nope`, `account/nope`, `cart/nope`, `checkout/nope`, `deals/nope`, `membership/nope`, `sell/nope`, `products/a/b`, `live/a/b`; also on uae-ar and omn-ar). | 404 | **200** with `NEXT_HTTP_ERROR_FALLBACK;404` and the generic title. `lib/missing-routes.ts` returns `null` (no lookup) for paths it does not recognise under these roots. During the crawl, `/uae-en/property/CL-AE-DEMO1` returned 200 once (fail-open on a slow API) and 404 on retry. | frontend — ✅ fixed (retest): `lib/missing-routes.ts` has a table of every page under the static roots; any other sub-path is a 404 without an API call (incl. `advertise/<x>`, `seller`). Kind checks (`property/`) fail closed and skip the shared positive cache (the cause of the one-off 200: `classified/find/CL-AE-DEMO1` had cached the same API URL). `/[market]/missing` now calls `notFound()` so the status survives `next start` (the rewrite status alone was dropped on prerendered routes, so production returned 200 for every QA-012 path). Curl on `next start`: all listed paths 404 on uae-en / uae-ar / omn-ar, real pages 200 |
| RT-005 | minor | backend | Store facets list every seller in the market | `GET uae-en/sellers/qa3-vendor-0x/products/facets` | Facets for the store's products only. | `sellers` lists Nawy Retail (3), SoundMax Official (3), QA2 Store A, QA3 Vendor A and QA3 Vendor B, which is identical to the global `products/facets`. `attributes.colour` also counts White, which comes from another seller's listing. Price, fulfilment and conditions are scoped correctly. | backend — ✅ fixed (retest): `ProductSearch` takes a `store` vendor id (set only by the storefront) that scopes products, offers, the seller facet and option values. Live: `sellers/qa3-vendor-0x/products/facets` lists only that seller, colour Black only. Test: `QaRetestFixesTest` RT-005 |
| RT-006 | minor | backend | Asking for a resume link signs the applicant out of their open wizard | Applicant APP-UAE-MGLAYJ is partway through, with token T1. Anyone calls `POST uae-en/partner-applications/resume-link {email, type}`. Then `GET partner-applications/APP-UAE-MGLAYJ` with T1. | The current session keeps working; the link adds access. | 404 with T1, because a new token replaces it. The response is 202 whether or not the email exists, so this is not an enumeration problem, but anyone who knows the email can break an applicant's session. | backend — ✅ fixed (retest): resume link adds a token (`partner_applications.resume_token_hashes`, newest 5) instead of rotating the wizard token; same for "changes requested" mails. Response stays 202 either way. Test: `PartnerApplicationsTest` resume-link case updated |
| RT-007 | minor | backend / panel | The classified contract gate and the Seller Agreement gate still cannot be exercised | Look for an admin control that sets `requires_contract` on a classified category, then check `contract_versions`. | Admin can mark a category as needing a contract, and publish versions, so that the gates can be tested. | Admin → Classifieds has only moderation and reports. There are no classified-category routes, `contract_versions` is empty, and `ops/vendor/agreement` returns `required:false`. The paths for QC-017, the classified contract gate and the checkout `customer_contract_required` were not re-run live. | backend — ✅ fixed (retest): Admin → Verticals → Classified categories (`categories.view` to see, `categories.manage` to toggle `requires_contract`, country audit row). Test-only fixtures in `QaRetestFixesTest` exercise the classified contract gate (admin toggle → pending_contract → sign → pending_review) and the Seller Agreement gate (ops 403 → Seller Center redirect → sign on the web → ops 200). Nothing published on dev |
| RT-008 | trivial | backend | A social ticket tried in the wrong market is spent | Get a `uae-ar` ticket from the callback. `POST uae-en/auth/social/exchange {ticket}`, then `POST uae-ar/auth/social/exchange {ticket}`. | The second call, in the right market, still works. | Both calls return 422 `social_ticket_invalid`. | backend — ✅ fixed (retest): exchange peeks at the ticket and refuses a wrong market without spending it; still single use. Test: `QaWave3CustomerAuthTest` web-ticket case updated |
| RT-009 | trivial | backend / i18n | Arabic `no_workspace` message contains the raw role key | Call `GET omn-ar/ops/vendor/orders` with a uae vendor token. | A localised role name. | "لا توجد لهذا الحساب مساحة عمل **vendor** في هذا البلد." | backend — ✅ fixed (retest): `OpsWorkspaces::roleLabel()` (seller / marketer / delivery supervisor / travel agency, translated). Test: `QaRetestFixesTest` RT-009 |
| RT-010 | trivial | backend | Support category label is a humanised key | `GET uae-en/support/categories` | "Returns & refunds" | `{"value":"returns_refunds","label":"Returns refunds"}` | backend — ✅ fixed (retest): `Label` override `returns_refunds` → "Returns & refunds" / "المرتجعات والمبالغ المستردة". Test: `QaRetestFixesTest` RT-010 |
| RT-011 | trivial | backend | Wrong message on confirm for a cancelled assignment | Admin force-cancels NW-AE-1000024-1 while it is out for delivery. The agent then confirms with `signature_data`. | "This delivery was cancelled." | 422 "Proof can be added while the delivery is in progress." `attachProof` runs before the status check (`DeliveryAgentApiController::confirm`). | backend — ✅ fixed (retest): covered by RT-001: confirm on a cancelled assignment says "This delivery was cancelled. Return the parcel to the origin." before any proof is stored |
| RT-012 | trivial | website | A one-off dev 500 from a JSON parse | Crawl `/uae-en` with 4 parallel requests. | 200 | One `GET /uae-en/creators` returned 500. `next.log` shows `SyntaxError: Unexpected end of JSON input` and "Failed to generate static paths for /[market]/adsupport". It did not reproduce in 40 retries, and it looks like a dev-server race. Check it in a `next start` build. | frontend — ✅ fixed (retest): not reproducible on a production build: 40 parallel `/uae-en/creators` and a 144-request 8-way crawl on `next start` all 200, no errors logged. The dev log stack has no app frame ("Failed to generate static paths … Unexpected end of JSON input"): a `next dev` race reading its own on-demand files. No app change |
| RT-013 | trivial | backend | The mail header link goes to the API host | Read any customer mail, for example the sign-in code, in laravel.log. | The header links to the storefront. | `Nawy: http://localhost:8000` (it uses `APP_URL`, not `NAWY_STOREFRONT_URL`). | backend — ✅ fixed (retest): `resources/views/vendor/mail/{html,text}/message.blade.php` link the header to `nawy.payments.storefront_url` (NAWY_STOREFRONT_URL). Test: `QaRetestFixesTest` RT-013 |

## Regression sweep: flows that passed

- **Catalogue:** browse, search, facets and validation (bad `sort`, `per_page` 999, inverted price range and `rating` 9 all get 422); PDP; suggest, popular and trending; compare; FBT; offers; seller store.
- **Cart and checkout:**
  - guest cart and merge;
  - checkout with the fake gateway for stripe, paytabs, tabby and tamara (redirect `next_action`), and COD;
  - webhook capture, duplicates and a bad signature (401);
  - coupons;
  - wallet as payment refused when the balance is short.
- **Orders:**
  - multi-seller COD;
  - vendor advance through ops;
  - admin assignment;
  - agent accept, start, arrive and confirm, with proof enforced;
  - COD ledger;
  - customer cancel before shipping (refused once shipped);
  - return with a photo (quantity limit, IDOR);
  - dispute, invoice and notifications.
- **Account:** reviews (once per item, delivered items only), Q&A (minimum length, sign-in required), addresses (phone rule, IDOR, default, delete), wishlist, notifications (IDOR on mark-read), profile validation, push tokens and devices.
- **D9 sign-in:** all paths listed under QC-005.
- **Travel D8:** guest hold, read and cancel by `X-Booking-Token` (a wrong token gets 404). Signed-in request in `uae-ar` gets the Arabic mails; the agency confirms; the payload has `payment: with_agency`.
- **Classifieds:** viewing and reserve; ad post (phone and decimals rules, photo required, `pending_review`, photo IDOR).
- **Partner applications:** email verify, steps, resume link, submit, admin approve, then ops login.
- **Support:** customer and vendor ops, plus the admin queue.
- **Ops APIs:**
  - vendor, supervisor and travel all checked, including logout;
  - marketer: dashboard, campaigns, conversions, links, logout;
  - IDOR across agencies and carriers gets 404;
  - the capacity rule holds.
- **Cross-country:**
  - uae orders, returns, disputes, products, ads, trips, flash sales and invoices all return 404 on `omn-ar`;
  - a uae cart token on omn gives an empty OMR cart;
  - a uae delivery or ops token is refused on omn;
  - bad market slugs return 404.
- **Panels:** every GET route plus link crawl.

  | Role | Pages | 500s |
  |---|---|---|
  | admin super | 216 | 0 |
  | admin support | 138 | 0 |
  | admin content | 117 | 0 |
  | admin finance | 124 | 0 |
  | partner (2 vendors) | 31 / 50 | 0 |
  | marketer | 22 | 0 |
  | travel | 22 | 0 |
  | carrier | 19 | 0 |
  | delivery | 17 | 0 |

  The 403s appear only on routes that are not in the role's sidebar.
- **Website crawl:**
  - **Pages:** uae-en 103, uae-ar 109, omn-ar 66. No raw translation keys, no error digests, and `dir` is correct on every page. There is one transient 500 (RT-012).
  - **Signed-in account pages:** 33 per market on uae-en and uae-ar. All have proper titles; real IDs return 200 and fake IDs 404.

## Could not test, and why

- **Contract gates** (Seller Agreement in the vendor app and Seller Center, the classified contract step, checkout `customer_contract_required`): there are no contract versions, and there is no admin control for classified-category `requires_contract` (RT-007). Publishing one would gate every dev account, so it was not done.
- **Nawy Credits partly spent at checkout:** `POST orders` has no credits parameter. A `wallet` payment is offered only when the balance covers the whole order.
- **3-decimal cart and checkout:** Oman still has no products (categories and plans exist, listings do not).
- **Browser-side behaviour:** hydration, console errors, the infinite-scroll and suggest UI, and the Flutter apps on a device. The built-in browser cannot reach the local dev servers, so the website was checked through HTTP and HTML only.
- **True concurrency:** `php artisan serve` with 4 workers is not a real parallel load. **Real gateways, LiveKit and FCM:** fake or not installed.
- **Not re-run live:** suspended-customer sign-in (QC-016), account-manager scoping (QC-019) and tenant staff roles (QC-020). The suite covers them.

## Accounts and data created (dev DBs)

- **Orders:**
  - NW-AE-1000018: B, COD, two sellers, delivered; return RT-NW-AE-1000018-1-2; dispute DS-NW-AE-1000018-2-1, resolved as a split refund of 50.
  - NW-AE-1000019: A, stripe, `under_review`.
  - NW-AE-1000020: paytabs, captured.
  - NW-AE-1000021 and -1000022: tabby and tamara, pending.
  - NW-AE-1000023: COD, cancelled.
  - NW-AE-1000024: COD, admin force-cancelled.
  - NW-AE-1000025: RT10 coupon, captured.
- **Legacy and existing records changed:**
  - DA-NW-AE-1000013-1-12 is now `delivered` (RT-001 evidence; the order is still cancelled).
  - Shipment SH-NW-AE-1000012-1 has failed twice (attempts 2 of 3).
- **Coupons:** RT10 (public), RTBOGO, RTFIRST.
- **Support tickets:** TK-AE-000002 (customer, reopened) and TK-AE-000003 (vendor).
- **Reviews and Q&A:** review 2 (published, with a seller reply and a report); question 2.
- **Classified ad:** CL-AE-0000007 (`pending_review`).
- **Viewing request:** id 2.
- **Bookings:** TB-AE-0000009 and -0000010 (guest, cancelled); TB-AE-0000011 (confirmed, then cancelled).
- **Application:** APP-UAE-MGLAYJ (approved) and the new vendor `qa-rt-vendor@example.test` (`RtVendorPass1!`). APP-UAE-RDLSSL (draft, victim email).
- **Accounts:**
  - `qa1-race` now has the email `qa-rt-race2@example.test`, the password `RtPassw0rd3!`, and Google linked then unlinked.
  - New social account `qa-rt-social-new@example.test`.
- **Invitations:** carrier invitations for qa2-vendor-c, qa3-travel-a and qa-rt-newdriver; a supervisor invitation for qa1-c.
- **Other:** B's wallet holds 50 AED; seller follow and unfollow; push token registered and removed.
