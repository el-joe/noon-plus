# QA + QC issue tracker

Sources:
- [qa-issues-qa.md](qa-issues-qa.md): 49 issues (3 critical, 9 major, 24 minor, 13 trivial).
- [qa-issues-qc.md](qa-issues-qc.md): 80 issues (1 critical, 27 major, 39 minor, 13 trivial).

When a fix agent closes an issue, it marks the row in the source file with `✅ fixed (wave N)` and names the test that proves it. QA re-tests each wave before the next wave starts.

| Wave | Scope | Owner | Status |
|---|---|---|---|
| 1 | **Security and money integrity.** QA-001–QA-010; QC-001 (disputes workflow); QC-017 (seller agreement gate on ops API); QC-020 (partner-panel staff permissions); Sanctum token expiry; Thawani webhook replay window; return photos moved to a private disk; review-moderation permissions seeded; the remaining QA security and money minors. | backend | ✅ (742 tests green; contract changes in storefront-api-additions.md "QA fixes") |
| 2 | **Admin back office.** QA-011; admin queue for `under_review` payments; admin-created drivers via invitation; QC-002–QC-004. Catalog (categories, brands, products), coupons (including BOGO and scopes, QC-014), gift cards and vouchers, Nawy One plans, admin users and roles, account-manager scoping (QC-019), refunds, disputes, warranty, activity log, system health; nav links that go to `#`. | backend | ✅ (778 tests green; QA-011, QA-030, QC-002, QC-003, QC-004, QC-014, QC-019, QC-029, QC-030 fixed, QC-044 partly; contract changes in storefront-api-additions.md "QA fixes (wave 2)") |
| 3 | **Platform parity.** QC-028 (Arabic backend language files and locale); QC-005/QC-006 (business-panel password reset; customer password and social sign-in need an owner decision); QC-012/QC-013 (review states, vendor replies, disputed overlay); QC-015/QC-016 (missing scheduled jobs and lifecycles); remaining backend requirement majors. | backend | ⬜ |
| 4 | **Website, apps and design.** QA-012 (real 404 status codes); QC-023 (footer payment badges per country); QC-021/QC-022 (Figma PayPal and travel-dashboard revenue); website, Flutter and design minors. **Wave 1 client changes:** join-wizard email code step, cart `merge_warnings`, `under_review` payment status, no caching of signed return photos; apps: 401 → sign in again, delivery `cancelled` assignments, vendor Seller Agreement screen and staff 403, supervisor suspension-refused message. | frontend, flutter, design | ⬜ |
| 5 | **Remaining minors and trivials**, then a full QA re-test. | all | ⬜ |
