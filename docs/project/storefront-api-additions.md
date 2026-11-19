# Storefront API additions (gaps G-W1 to G-W31 closed, 686 tests)

All endpoints are under `/api/v1/{market}/` and use the `{data, meta}` / `{error:{code,message,fields}}` envelope.

## Catalogue
- **Product cards** (`GET products`, wishlist):
  - `image`: `{id,url,alt,alt_en,alt_ar,variant_sku,width,height,sort_order}` or null.
  - `rating`: `{average:"4.50"|null,count}`.
- **`GET products` filters:**
  - `brand`, `seller`: comma-separated slugs.
  - `rating`: minimum stars, 1–5.
  - `attr[colour]=Red,Blue`.
  - `sort=rating`.
  - `meta`: `{total,page,last_page,per_page}`.
  - The category filter includes descendant categories.
- **`GET products/facets`** (same filters):
  - Returns `{brands:[{slug,name,count}], sellers:[{slug,name,rating,count}], price:{min:{amount,currency}|null,max}, ratings:[{min,count}], attributes:[{key,values:[{value,count}]}]}`.
- **`GET products/{slug}`** adds:
  - `description`, `highlights[]`, `specifications[{label,value}]`.
  - `content:{en,ar}`.
  - `images[]`, `rating`, `breadcrumbs[{slug,name}]`, `brand_slug`.
  - `rating_count` on the buy-box seller.
- **`GET brands?category=&q=`**: `[{slug,name,logo,product_count}]`.
- **`GET categories/tree`**: `[{slug,name,image,product_count,children}]`.
- **`GET categories/{slug}`**: `{slug,name,image,product_count,breadcrumbs,children}`.
- **Cart and order lines** gain `image` and `product_slug`.

## Reviews & Q&A
- **`GET products/{slug}/reviews`** and **`GET sellers/{slug}/reviews`**:
  - Query: `sort=recent|helpful|rating_desc|rating_asc`, `rating`, `with_photos`, `per_page`.
  - Returns `{summary:{average,count,histogram}, reviews:[{id,rating,title,body,author,verified_purchase,photos,helpful_count,voted_helpful,created_at}]}`.
- **`POST reviews`** (auth, multipart): `order_item_id`, `rating`, `title?`, `body?`, `photos[]` (at most 5).
  - Returns 201, status pending.
  - Returns 422 `review_not_allowed` when the customer cannot review that item.
- **`GET me/reviews`** and **`GET me/reviewable-items`**.
- **`POST`/`DELETE reviews/{id}/helpful`**.
- **`GET`/`POST products/{slug}/questions`**:
  - Body: `{body}`, 5–500 characters.
  - Answers carry `by: vendor|admin`.

## Account
- **Addresses:**
  - Endpoints: `GET`/`POST addresses`; `GET`/`PUT`/`PATCH`/`DELETE addresses/{id}`; `POST addresses/{id}/default`.
  - Shape: `{id,label,name,phone(E.164),city,area,street,building,apartment,landmark,zone,location,is_default}`.
- **`GET delivery-areas`**.
- **Checkout:** `POST orders` accepts `address_id` instead of `address`. Sending both returns 422.
- **Wishlist:**
  - `GET wishlist`: returns cards with `in_stock` and `saved_at`.
  - `POST wishlist {product}`: 201 if added, 200 if it was already there.
  - `DELETE wishlist/{slug}`.
- **`POST auth/logout`**: revokes the current token.

## Platform
- **CORS** exposes `X-Cart-Token`, `X-Booking-Token` and `Retry-After`.
- **Guest cart:** `GET cart` with no token returns an empty cart and creates no row.
- **Error envelope for `api/*`:**
  - `unauthenticated` 401, `forbidden` 403, `not_found` 404, `method_not_allowed` 405.
  - `validation_failed` 422, with the per-field errors in `error.fields`.
  - `too_many_requests` 429.
- **Newsletter:**
  - `POST newsletter/subscribe {email,source?}` always returns 202.
  - Then `POST newsletter/confirm {token}` and `POST newsletter/unsubscribe {token}`.
  - The confirmation email links to `{storefront}/{market}/newsletter/confirm?token=…`. The storefront must build this page.

## Round 2 (gaps G-W14 to G-W31 closed)

Same envelope and base path. "Card" below means the product card shape `{listing_id, slug, title, brand, price:{amount,currency}, image:{id,url,alt,alt_en,alt_ar,variant_sku,width,height,sort_order}|null, rating:{average,count}}`. Every image field is now a full public URL (absolute URLs stored in the database pass through unchanged). Every money field is `{amount, currency}` rounded to the currency (2 decimals; 3 for OMR/KWD/BHD/JOD).

### Images and money (G-W14, G-W18, G-W19, G-W26)
- **Page-builder** `product_carousel` / `product_grid` blocks (`GET pages/{slug}`): `data.products[]` are cards.
- **Flash sales** (`GET flash-sales`, `GET flash-sales/{id|slug}`): events gain `slug` (`"{name}-{id}"`); `deals[].product` is the card without `listing_id`/`price` (`{slug,title,brand,image,rating}`); `price` / `was_price` unchanged.
- **Live streams** `GET live-streams/{slug}`: `products[]` are cards.
- **Creators** `GET creators`, `GET creators/{slug}`: `avatar_url`, `cover_url` are URLs. `picks[]` are cards plus `product_slug`, `referral_code`. **Breaking:** `picks[].price` is now `{amount,currency}` (the separate `currency` field is gone).
- **Travel** `GET travel/packages[/{slug}]`: cards gain `cover_url` and `image:{url,alt}|null`; **breaking:** `from_price` is `{amount,currency}|null`, `departures[].rooms[].price_per_person` is `{amount,currency}`. Bookings: `quoted_total` is rounded (`"4000.00"`).
- **Classifieds**: card/detail `price`, `sold.price` and packages `price` rounded; map `properties.price` rounded string. `POST ads/{n}/sold` → `sold_price:{amount,currency}`; `POST ads/{n}/packages` → `purchase.amount:{amount,currency}`.
- **Categories** (`categories`, `categories/tree`, `categories/{slug}` incl. children): gain `id`; `image` is a URL.

### Travel guest booking (G-W20)
- `POST travel/departures/{id}/bookings` → 201 with response header **`X-Booking-Token`** (exposed by CORS); body `access_token` kept.
- Confirmation (and every later status) email has a "View your booking" button: `{NAWY_STOREFRONT_URL}/{market}/travel/bookings/{number}?token={access_token}`.
- **`POST travel/bookings/resend-link`** `{email}` → always 202 `{sent:true}` (throttle 3/min). Mails the status links of up to 10 bookings made with that email in the market's country; nothing is sent (and nothing is revealed) when none exist.

### Partner applications (G-W28)
- `POST partner-applications` → header **`X-Application-Token`** (CORS-exposed) in addition to `meta.access_token`.
- Resume and changes-requested emails link to `{NAWY_STOREFRONT_URL}/{market}/join/resume?number={n}&token={t}` (market = country + the application's language), with a button.
- `POST partner-applications/{n}/steps/documents` with nothing missing → 200 and marks `documents` complete (also when the country asks for no documents); 422 `documents_missing` otherwise.
- The store step's `main_category_id` takes the `id` now on `GET categories/tree`.
- `GET join-options`: each section gains `roles:[{role,label,type,job_types}]`; marketer → `influencer` (type `marketer`, job_types `["influencer"]`), `affiliate` (type `marketer`, `["affiliate"]`), `broker` (type `broker`).
- **`GET advertise-requests/options`** → `{advertiser_types[], interests[], budget_bands:[{key,label,min,max,currency}]}` (keys `under_1000`, `1000_5000`, `5000_20000`, `20000_50000`, `over_50000`, `not_sure`). `POST advertise-requests` accepts `monthly_budget_band` (one of the keys); free-text `monthly_budget` still accepted.

### Classifieds (G-W21, G-W22, G-W24)
- `GET classifieds/ads` (and `map`) filters: `vertical` (inherited from the category tree: `general`, `property`, …), `bathrooms_min`, `min_area`, `max_area` (m²).
- Cards gain `property:{purpose,property_type,bedrooms,bathrooms,area_sqm,furnishing,rent_period}|null`.
- `GET classifieds/categories`: `vertical` and `section` are now the **inherited** values; the category's own values are `own_vertical` / `own_section`; each node has `request_types[]`.
- Ad detail gains `vertical`, `request_types[]` (`viewing`, `reserve`; empty when the ad is not live), `seller.member_since`, `seller.shop.{rating,rating_count,member_since}`, `seller.broker.member_since`.
- **D8 requests on every vertical:** `POST classifieds/ads/{n}/viewings` takes `type: viewing|reserve` (default `viewing`) with `{name,email,phone,preferred_at,message?}`, guests too → 201 `{id,type,created_at}` (D12: no `status`). Allowed types per vertical come from the setting `classified.request_types` (default: viewing + reserve for general, property, motors; country override wins); 422 when the ad is not live or the type is off.
- **`GET me/viewing-requests`** (auth, `type?`, `per_page?`) → `[{id,type,preferred_at,note,created_at,ad:{number,title,price,image,city,area,status,category}}]` (D12: no `status` / `scheduled_at`), meta `{total,page,last_page,per_page}`.
- **`GET follows?type=shop|marketer&slug=`** (auth) → `{following, followers}`.
- **`GET classifieds/ads/{n}/contract`** (owner) → `{number,status,contract:{version_id,title,version,text,has_file,signed}|null}` — reopen a pending-contract ad.
- Rejected ads stay final (lifecycle §5.1): `PUT ads/{n}` on a rejected ad → 422 with a hint; **`POST classifieds/ads/{n}/duplicate`** (owner; rejected, expired, archived or sold) → 201 ad detail of a new draft with the same fields, specs, property details and media.

### Account (G-W29)
- **`GET me`** → `{id,name,email,email_verified,phone,locale,avatar_url,marketing:{email,sms,push,whatsapp},created_at}`.
- **`PATCH|PUT me`** `{name?, locale?: en|ar, marketing?: {email?,sms?,push?,whatsapp?}}` → same shape.
- **`POST me/avatar`** (multipart `avatar`, jpg/png/webp ≤ 2 MB) / **`DELETE me/avatar`** → same shape.
- **Email change:** `POST me/email {email}` → 202 `{sent,email}` (code mailed to the new address; 422 `email_taken` / `email_unchanged`); `POST me/email/verify {email, code}` → profile with the new email (422 `code_invalid` / `code_expired`, 429 `code_locked`, 422 `email_taken`).
- **Notifications:** `GET me/notifications?unread=&per_page=` → `[{id,type,title,body,link,data,read,read_at,created_at}]`, meta `{total,page,last_page,per_page,unread}`; `GET me/notifications/unread-count` → `{unread}`; `POST me/notifications/{id}/read` → the row + `unread`; `POST me/notifications/read-all {ids?}` → `{marked, unread}`. Types: `order.{confirmed|shipped|out_for_delivery|delivered|cancelled|returned|refunded}`, `travel_booking.{confirmed|rejected|cancelled|completed}`, `return.{status}`, ~~`viewing.{status}` / `reserve.{status}`~~ (removed by D12), `classified_ad.{active|rejected|expired}`, `review.{approved|rejected}`, `question.{approved|rejected}`. `link` is a storefront path without the market (`/account/orders/{n}`, `/account/returns/{n}`, `/travel/bookings/{n}`, `/account/inquiries`, `/classified/find/{n}`, `/products/{slug}`). Moves made by the customer themselves are not notified. Per country.
- **`GET me/contracts/signed`** → `[{id,kind,title,version_id,version,status(active|superseded|revoked),method,purpose,subject,full_name,locale,sha256,signed_at,superseded_at,url}]` with pagination meta.

### Content (G-W25)
- `GET static-pages/{slug}` gains `seo:{title,description}` (admin-set per language; falls back to the title and the first 160 characters of the body). Admin → Content → Static pages has the SEO fields.
- `GET help/{hub}/articles/{slug}` gains `collection_detail:{slug,title,audience}`, `helpful:{yes,no}`, `updated_at` (`collection` slug kept); summaries gain `helpful_count`.
- **`POST help/{hub}/articles/{slug}/votes`** `{helpful: bool}` → `{helpful:{yes,no}, my_vote}`; one vote per signed-in user, else per `X-Visitor-Token`, else per IP; voting again switches the vote (throttle 20/min).
- `GET blog` meta gains `page`, `per_page`; `GET blog/categories` items gain `post_count`, and `meta.tree` is the nested tree (`children`).

### Shopping extras (G-W15, G-W16, G-W17, G-W30)
- Cart lines gain `measurement_fields[{key,label,unit,min,max,required}]` (made-to-order products), `measurements_required`, `measurements_missing`. Checkout already refuses a made-to-order line without measurements (409 `measurements_required`).
- **`GET brands/{slug}`** → `{slug,name,logo,product_count,categories:[{id,slug,name,count}],top_products:[product cards],page:string|null,products_url}`; `page` is the slug of a published page-builder page named after the brand (`{slug}` or `brand-{slug}`) to render instead.
- `GET products/{slug}/size-guide`: `columns` stay plain row keys; new `column_defs:[{key,label,unit|null,is_label}]`; rows are objects keyed by `columns`.
- **`GET gift-cards/designs`** (public) → `[{key,name}]`; `POST gift-cards` takes `design` (default `classic`); gift cards return `design`.
- `GET membership/plans` is public (no sign-in).
- `GET referrals/{code}`: `destination` is a card (+ `product_slug`); new `picks[]` (the creator's other live picks: cards + `product_slug`, `referral_code`).

### Payments for gift cards and Nawy One (G-W31)
- Gateway return/cancel for non-order payments now redirects to `{storefront}/{market}/checkout/payment?payment={reference}&kind={gift_card|membership_invoice|classified_package}&status={status}` (orders keep `?order=…`).
- **`GET payments/{reference}`** and **`POST payments/{reference}/verify`** (auth, the payer only; verify throttle 30/min) → `{payment: PaymentResource, purpose: order|gift_card|membership_invoice|classified_package, subject}`; `subject` is `{number,status,masked_code,amount,recipient_email}` for a gift card, `{number,status,amount,membership:{status,plan}}` for a membership invoice, `{number,status,amount,applied_until}` for a package, `{number,status,payment_status}` for an order.

### Live and checkout (G-W27, referral)
- `GET live-streams/{slug}` `playback` gains `hls_url`: for a live LiveKit room, `LIVEKIT_HLS_URL_TEMPLATE` with `{room}` replaced (egress/CDN), else `null`; recorded channels keep `media_url` (`hls_url` null). Room URL + viewer token unchanged.
- `POST orders` accepts `referral_code` (a creator's code, any case) and credits it as `direct` attribution through the Marketers attribution, ahead of click attribution.

## QA fixes (wave 1: security and money integrity)

API contract changes made by the wave 1 fixes. Paths are relative to `/api/v1/{market}/`.

### Tokens (QA-001, QA-004, QC-047)
- `POST auth/email-code/verify` now issues a token with the ability `customer` (was `*`) that expires after `nawy.tokens.customer_days` (90). Delivery tokens (`delivery/auth/login`) expire after 30 days, ops tokens (`ops/auth/login`) after 30 days. The response shape is unchanged.
- A delivery or ops token on a customer route → **401** `unauthenticated`. A customer (or legacy `*`) token on `delivery/*` → **403** `wrong_token`; on `ops/{role}/*` → **403** `no_workspace`. `me/notifications*` still accepts every app's token.
- Legacy tokens without `expires_at` stop working once older than their type's lifetime. Expired tokens are pruned daily (`sanctum:prune-expired --hours=24`, 04:10 Oman).
- Clients must treat a 401 as "sign in again" (the web BFF and every Flutter app already do).

### Cart and holds (QA-008, QA-010, QA-014)
- `POST cart/merge` no longer fails: the line over a flash-sale per-customer limit (or over stock) is clipped or skipped. The response is the merged cart, with `meta.merge_warnings: [{listing_id, code (flash_sale_customer_limit | insufficient_stock | listing_unavailable | …), message, requested, merged}]`.
- A guest cart line holds at most `checkout.guest_hold_max_quantity` (3) units of stock. A hold is renewed for 15 minutes on each change, but never past 60 minutes after it was first taken. Guest `PUT cart/items` is limited to 30 per minute per IP (named limiter `cart-writes`).
- `POST cart/coupons` is limited to 10 per minute per user or IP (limiter `coupon-apply`) → 429.
- `POST travel/departures/{id}/bookings` with `submit:false` as a guest: at most 4 seats (`travel.guest_hold_max_seats`) → 422 `not_allowed`, and at most 3 holds per IP per hour → 429.
- Unexpected API errors are always `500 {error:{code:"server_error"}}`, with no trace (whatever `APP_DEBUG` says). A `CartRejected` thrown anywhere renders as its own `error.code`.

### Checkout and payments (QA-009, QA-013)
- `POST orders` validates `address.phone` with the country's phone rule (422 `address.phone`) and stores it normalised (`+971…`).
- New payment status **`under_review`**: when the gateway reports a capture for a different amount than the attempt, the payment and the order's `payment_status` become `under_review` (`failure_code: amount_mismatch`, `captured_amount` = the reported amount). The order is not paid and is not auto-expired; an admin captures, refunds or fails it. Clients should show "Payment under review".
- New payment status **`voided`** (QA-016): when every parcel of a COD order is cancelled before anything was collected, its pending COD payment becomes `voided` (the cancelled order's `payment_status` reads `expired`, as for any cancelled unpaid order). When some parcels were delivered and the rest cancelled, the payment is captured for the cash collected.
- Emailed codes are scoped by purpose (QA-021): an email-change code no longer signs in, and a sign-in code no longer replaces a pending email-change code. The API is unchanged.

### Delivery (QA-005, QA-006, QA-007)
- New assignment status **`cancelled`**: when a sub-order is cancelled, its open assignments become `cancelled` and drop out of the agent's open lists. The shipment becomes `cancelled` (before pickup) or `returning_to_origin` (after pickup). `start` / `confirm` on a cancelled sub-order → 422 `not_allowed`.
- A seller cannot cancel a sub-order that is out for delivery (`ops/vendor/orders/{id}` shows `can_cancel:false`; cancel → 422).
- `cod_amount`: the COD fee is on the order's first open parcel only, so the parcels add up to the order total.
- `ops/supervisor/drivers/{id}/status {status:suspended}` with open assignments → 422 `not_allowed` ("reassign first").

### Vendor app Seller Agreement gate (QC-017)
- Every `ops/vendor/*` route returns **403** `seller_agreement_required` while the store has not signed the current Seller Agreement.
- **`GET ops/vendor/agreement`** → `{required, signed, can_sign, version:{id,title,text}|null}`.
- **`POST ops/vendor/agreement/sign`** `{version_id, full_name, accept:true}` (store owner only; otherwise 403 `owner_only`; an outdated version → 409 `version_outdated`) → `{signed:true}`.

### Ops staff permissions (QC-020)
- Ops routes check the member's staff role, as the panels do (owners pass). A refused change → **403** `forbidden`.

### Partner applications (QA-002)
- `POST partner-applications` (and an email change through `steps/account`) mails a 6-digit code to the application email. The application has a new field `email_verified` (bool).
- **`POST partner-applications/{number}/email/verify`** `{code}` (header `X-Application-Token`, 10/min) → the application (`email_verified: true`); a wrong code → 422 `validation_failed` `fields.code`. After 5 wrong codes, or 30 minutes, ask for a new code.
- **`POST partner-applications/{number}/email/resend`** (3/min) → 202 `{sent}`.
- `POST partner-applications/{number}/submit` before verification → 422 `validation_failed` `fields.email_code`. Admin approval of an unverified application is refused.
- If the email already has a Nawy account, the code is the owner's consent. On approval the workspace is added to that account, the wizard password is dropped (it never replaces theirs), and the welcome mail carries a signed 3-day link to set a panel password.

### Returns (QC-049)
- `photos[]` in return resources are now **signed links** that expire after 30 minutes (`GET return-photos/{id}/{index}?signature=…`). Before, they were public storage URLs. Photos are stored after the return is accepted.

### Throttles (QA-015)
- Every `throttle:N,1` counter is now per route and per market, so routes and markets no longer share one counter. Sign-in routes (panels, `ops/auth/login`, `delivery/auth/login`) are limited to 6 per minute per email + IP and 30 per minute per IP.

### Client changes needed (wave 4)
- **Website (Next.js):**
  - Join wizard: after the account step, add a "Confirm your email" step: a code input, `email/verify`, and "Resend" (`email/resend`). Block submit until `email_verified`.
  - Show `meta.merge_warnings` after sign-in.
  - Show the `under_review` payment status (`voided` appears on payment rows only).
  - Return photos are signed URLs: render them as they are and do not cache them.
- **customer_app (Flutter):** handle `under_review`, `merge_warnings` and 401 → re-login (token expiry).
- **delivery_app:** tolerate the assignment status `cancelled`, and handle 403 `wrong_token` by signing in again.
- **vendor_app:**
  - Add the Seller Agreement screen (`GET/POST ops/vendor/agreement`). Show it on 403 `seller_agreement_required`.
  - Handle 403 `forbidden` (staff role).
- **supervisor_app:** show the 422 message when a suspension is refused.
- **All ops apps:** re-login on 401 after 30 days.

## QA fixes (wave 2: admin back office)

API contract changes made by the wave 2 fixes. Paths are relative to `/api/v1/{market}/`. The response shapes are unchanged.

### Coupons (QC-014)
- Coupons can now be **BOGO** (buy X get Y: the cheapest eligible units get the coupon's % off, 100 = free), and can target **categories (with sub-categories), products, brands or sellers** instead of the whole order. A targeted coupon discounts only the eligible items; `data.coupons[].discount` and each shipment's `discount` show the amounts as before.
- New `POST cart/coupons` refusals (422, `error.code`):
  - `coupon_no_eligible_items`: none of the cart items is covered by the coupon's targets.
  - `coupon_sign_in_required`: a first-order or customer-segment coupon on a guest cart.
  - `coupon_first_order_only`: the customer already has an order (not cancelled).
  - `coupon_segment`: the customer is outside the coupon's segment (new customers, returning customers, Nawy One members).
- A coupon applied earlier that the customer is no longer eligible for stays on the cart but is not applied; `data.warnings` says so. A BOGO coupon without enough eligible units shows a warning and a 0 discount.
- Clients: show the new error messages as returned (they are localised by the API).

### Nawy One and catalogue (QA-011)
- `GET membership/plans` and `GET categories` now return data in every market once the admin creates plans or copies categories (dev: `DevCatalogSeeder`). No contract change.

### Delivery app (wave 2)
- Drivers added by an admin are no longer created with an emailed password: they receive a team invitation link, choose their password (or keep their existing account), then sign in to `delivery/auth/login` as before.

## QA fixes (wave 3)

API contract changes made by the wave 3 fixes. Paths are relative to `/api/v1/{market}/` unless they start with `/api/v1/`. Every response keeps the `{data, meta}` / `{error:{code,message,fields}}` envelope.

### Customer sign-in: password, Google and Apple (D9, QC-005)
The email code (`auth/email-code/send` + `verify`) stays the default sign-in. Every sign-in response is now `{token, user: {id, name, email, has_password}}` (`has_password` is new). Every sign-in is recorded in `GET me/login-history`, which has a new `method` field: `email_code`, `password`, `google` or `apple`.

**Optional password**

| Method | Path | Body | Answer |
|---|---|---|---|
| POST | `auth/password/login` | `email`, `password`, `device_name` | 200 token. Errors: 422 `invalid_credentials` (also when the account has no password yet; offer the email code), 403 `account_suspended`. Limited to 5 per minute per email + IP. |
| POST | `auth/password/forgot` | `email` | Always 202 `{sent:true}`, so it does not reveal whether an account exists. Sends one email with a link to `{storefront}/{market}/reset-password?email=…&token=…` (valid 60 min) and a 6-digit code for the apps (valid 10 min). Limited to 3 per minute per email. |
| POST | `auth/password/reset` | `email`, `token` **or** `code`, `password`, `password_confirmation`, optional `device_name` | 200 `{reset:true}`. With `device_name` the answer also has `token` and `user` (signed in). Every older token is revoked. Errors: 422 `reset_token_invalid`, `code_invalid`, `code_expired`; 429 `code_locked`. Minimum 8 characters. |
| POST | `me/password` (auth) | `password`, `password_confirmation`, plus `current_password` when the account already has one | 200 `{has_password:true}`. Every other device is signed out. 422 `fields.current_password`. Limited to 5 per minute. |

`GET me` now includes `has_password` (bool) and `social_accounts` (for example `["google"]`).

**Google and Apple: apps**
- `POST auth/social/{google|apple}/token` with `id_token` (from the Google or Apple SDK), `device_name`, and optionally `nonce` (the raw nonce you passed to the SDK) and `name` (Apple gives the name to the app only the first time).
- The server verifies the token's signature against the provider's keys, and checks its issuer, audience (our web client id and the app client or bundle ids), expiry and nonce.
- Answer: 200 token + user.
- Errors:
  - 422 `social_token_invalid`.
  - 422 `social_email_unverified`: the provider has no verified email. Offer the email code.
  - 403 `account_suspended`.
  - 503 `social_provider_unavailable`: keys are not configured.

**Google and Apple: website (OAuth)**
1. Send the browser to `GET auth/social/{google|apple}/redirect?return_to=/cart`. Ask with `Accept: application/json` to get `{url}` instead of a 302.
2. The provider returns to `/api/v1/auth/social/{provider}/callback`. This is the one URL to register with the provider; Apple posts the form to it.
3. The API then redirects to `{storefront}/{market}/auth/social/complete?ticket=…&return_to=…`. On failure it redirects to `{storefront}/{market}/login?social_error={code}`.
4. The page posts `auth/social/exchange` with `ticket` and `device_name` and gets the token. A ticket works once, within 2 minutes, and only in the same market. A bad ticket returns 422 `social_ticket_invalid`.

**Account linking**
- A Google or Apple identity is linked to the account with the same **verified** email. If no account has that email, a verified, passwordless account is created.
- Later sign-ins match the provider subject, even if the email at the provider changes.
- `DELETE me/social-accounts/{provider}` unlinks a provider. 404 `social_account_not_linked`.

**Config:** `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_APP_CLIENT_IDS`, `APPLE_CLIENT_ID` (Services ID), `APPLE_TEAM_ID`, `APPLE_KEY_ID`, `APPLE_PRIVATE_KEY_PATH` (.p8), `APPLE_APP_IDS`. Set `NAWY_SOCIAL_AUTH_DRIVER=fake` for tests and for local work without keys. The fake driver accepts the tokens built by `FakeSocialProvider::token([...])`.

### Language (QC-028)
- API messages, validation errors and emails are now translated into Arabic. A `-ar` market answers in Arabic. Routes outside a market follow `Accept-Language` (`ar` or `en`). Every API answer carries `Content-Language`.
- Emails and in-app notifications use the **recipient's** language, from `PATCH me {locale}`. Travel booking emails use the language the booking was made in.
- Clients: send `PATCH me {locale}` when the customer changes language, and send `Accept-Language` on calls outside a market.

### Reviews (QC-012)
- Review states follow the lifecycle catalogue: `pending`, `published`, `flagged`, `auto_flagged`, `hidden`, `rejected`.
  - `GET me/reviews` shows the author only `pending`, `published`, `hidden` or `rejected`. `approved` is now **`published`**; existing data was migrated.
  - Reviews whose text matches the settings word list or contains a link are created `auto_flagged`; the author sees `pending`.
- Review lists now carry `seller_reply: {body, created_at} | null`, the store's reply once Nawy has published it.
- New: `POST reviews/{id}/report` (auth) with `reason` (`spam`, `offensive`, `fake`, `irrelevant`, `personal_data` or `other`) and an optional `note`. Answers 201.
  - Errors: 422 `report_not_allowed` (your own review, or already reported); 404 if the review is not public.
  - A reported review stays public until a moderator decides.
- The in-app notification type for a published review is now `review.published` (was `review.approved`). New types: `review.hidden`, `review.rejected`.

### Orders (QC-013)
- `status` on orders can now be **`disputed`**. It is an overlay while any dispute on the order is open, and it returns to the derived status (for example `delivered` or `completed`) when the dispute is resolved or closed.
- Clients: show a "Dispute open" state and do not treat `disputed` as final.

### Catalogue (QC-015)
- `GET products?sort=bestselling` sorts by the weekly bestseller rank (units sold in the last 30 days). Unranked products come last.

### Accounts (QC-016)
- Suspended or banned customers are signed out everywhere. Every sign-in method returns 403 `account_suspended`; the email code returns the same code.

### Client changes needed (wave 4)
- **website (Next.js):**
  - **Sign-in screen:** keep "email me a code" as the first option. Add "Sign in with password", "Forgot password?", "Continue with Google" and "Continue with Apple" (a link to `auth/social/{provider}/redirect?return_to=…`).
  - **New pages:**
    - `/[market]/auth/social/complete`: exchange the ticket, store the token, then go to `return_to`.
    - `/[market]/reset-password`: read `email` and `token` from the query and post `auth/password/reset`.
    - `/[market]/login?social_error=…`: show the error.
  - **Account → Security:** set or change the password (`me/password`), show and unlink linked Google / Apple (`social_accounts`), and show `method` in the login history.
  - **Locale:**
    - Send `PATCH me {locale}` when the language switch changes.
    - Send `Accept-Language` on calls outside a market.
    - Show API error text as it is returned, since it is now localised.
  - **Orders:** handle the `disputed` order status.
  - **Reviews:** a "Report" action on each review, show `seller_reply`, and the `published` status name.
  - **Catalogue:** add a "Bestselling" sort option.
- **customer_app (Flutter):**
  - The same sign-in options. Native Google and Apple sign-in post the identity token to `auth/social/{provider}/token`; pass `nonce`, and for Apple the name the first time.
  - Forgot password by **code**: `auth/password/forgot`, then `auth/password/reset` with `code` and `device_name`.
  - Password settings, locale sync (`PATCH me {locale}` plus `Accept-Language`), `disputed` orders, and review report / seller reply.
- **Business panels:** none needed. They already have forgot / reset password, the language switch and RTL.

## QA fixes (wave 4b: Flutter apps)

Two small API additions for the apps. Paths are relative to `/api/v1/{market}/`.

### Google and Apple sign-in in the customer app without the native SDKs (D9)
- `GET auth/social/{google|apple}/redirect` takes an optional **`code_challenge`** (PKCE S256: `base64url(sha256(code_verifier))`, 43 characters). With it, the callback returns to the app instead of the storefront: `nawy://auth/social/complete?ticket=…&market=…`, or `nawy://auth/social/complete?social_error={code}` on failure (`NAWY_APP_SOCIAL_REDIRECT_URI`, config `nawy.customer_auth.app_redirect_uri`).
- `POST auth/social/exchange` takes **`code_verifier`**. A ticket issued for a `code_challenge` needs the matching verifier; otherwise 422 `social_ticket_invalid` (the ticket is spent either way). Web tickets (no challenge) are unchanged.
- The native-SDK flow (`auth/social/{provider}/token`) is unchanged; the app switches to it once `google_sign_in` / `sign_in_with_apple` are approved (owner-review P13, P14).

### Supervisor app: invite a driver (QA-003 rules)
- **`POST ops/supervisor/drivers`** `{name, email, phone?, vehicle_type? (bike|car|van|truck), vehicle_plate?}` → **202** `{invited:true, email, message}`. It sends the same team invitation as the Shipping panel; nothing is attached and no stored name is revealed until the driver accepts. Staff permission `carrier,agents`; 20 per minute.

### Client behaviour (all Flutter apps, `nawy_core` and `customer_app/lib/core`)
- Every call sends `Accept-Language` (the UI language) and reads `Content-Language`.
- 401 (token expired after 90 / 30 days, or revoked) and 403 `wrong_token` / `no_workspace` / `account_suspended` sign out locally and show why on the sign-in screen (customer app: a snack with "Sign in"), with the email prefilled.
- 429: the `Retry-After` wait is shown, and the same call is not sent again before it ends.
- 403 `forbidden` (staff role) keeps the session and says the role does not allow the action.

## QA fixes (wave 5a)

API contract changes and new endpoints from the wave 5a backend fixes. Paths are relative to `/api/v1/{market}/` unless they start with `/api/v1/`. Every response keeps the `{data, meta}` / `{error:{code,message,fields}}` envelope; money is `{amount, currency}`.

### Support tickets (QC-007, lifecycle §9.3)
Paths are relative to `/api/v1/{market}/`. Customer endpoints need the customer token.
- **States:** `open` → `in_progress` (assigned, or support's first public reply) → `waiting_customer` ⇄ `in_progress` → `resolved` → `closed` (terminal).
  - `resolved` → `open` when the requester reopens within `support.reopen_days` (7).
  - Resolved tickets close automatically after `support.auto_close_days` (7). The hourly job `nawy:support:auto-close` does this per country.
  - Every move writes a history row (subject `support_ticket`) with its actor. Numbers look like `TK-AE-000001`.
- **SLA:** `support.first_response_hours` (urgent 2 · high 4 · normal 12 · low 24) and `support.resolution_hours` (24 · 48 · 72 · 120). Both are country-overridable and count wall-clock hours. A priority change recomputes both.
- **Attachments:** jpg / jpeg / png / webp / pdf, 5 MB each, up to 5 per message. They sit on a private disk and are served only through signed links that expire after 30 minutes. Each file is stored with its detected extension.

| Method | Path | Body / query | Answer |
|---|---|---|---|
| GET | `support/categories` | — | `{categories:[{value,label}], priorities:[low,normal,high], max_attachments, attachment_types, attachment_max_kb}`. Customers get order, payment, delivery, returns_refunds, account, product, technical and other. The vendor app also gets selling and payouts. |
| GET | `support/tickets` | `status?`, `per_page?` (1–50), `page` | Tickets, newest activity first. `meta {total, page, per_page, last_page}`. |
| POST | `support/tickets` (multipart) | `category`, `subject` (3–160), `message` (10–5000), `priority?` (low · normal · high; urgent is staff-only), `order?`, `shipment?`, `attachments[]?` | 201 ticket. 422 `fields.order` / `fields.shipment` when it is not yours. 422 `not_allowed` for a category not offered to you. 10/min. |
| GET | `support/tickets/{number}` | — | The ticket with `messages[]` and `history[]`. Internal notes are never sent. Another requester's or another country's ticket → 404. |
| POST | `support/tickets/{number}/messages` (multipart) | `body` (2–5000), `attachments[]?` | 201. From `waiting_customer` the ticket moves to `in_progress`. 422 `not_allowed` when resolved ("reopen to reply") or closed. 30/min. |
| POST | `support/tickets/{number}/reopen` | `message?` | 200, status `open`. 422 `not_allowed` when it is not resolved or the reopen window has passed. |
| POST | `support/tickets/{number}/rating` | `rating` (1–5), `comment?` | 200. 422 `not_allowed` before it is resolved, or when it is already rated. |
| GET | `support/attachments/{message}/{index}` | signed URL | The file. 403 without a valid signature. |

- **Ticket shape:**
  - Fields: `number`, `category`, `category_label`, `subject`, `priority`, `status`, `status_label`, `order`, `shipment`, `assigned`, `reply_expected_by`, `first_responded_at`, `resolved_at`, `closed_at`, `last_message_at`, `can_reply`, `can_reopen`, `reopen_until`, `can_rate`, `rating {score, comment, rated_at}|null` and `created_at`.
  - Detail only: `messages[{id, from: requester|support, mine, body, attachments[{name, mime, size, url}], created_at}]` and `history[{from, to, by, at}]`.
- **Vendor app:** `ops/vendor/support/categories`, `GET/POST ops/vendor/support/tickets`, `GET ops/vendor/support/tickets/{number}`, and `POST …/{number}/messages`, `…/reopen` and `…/rating`.
  - Bodies and answers are the same as above.
  - Access needs `ops:vendor` and the staff permission `partner,support`, and works before the Seller Agreement is signed.
  - The whole store team shares its tickets. Refusals are 422 `transition_refused`.
- **Notifications:**
  - Customers get in-app notices `support_ticket.reply`, `support_ticket.waiting_customer` and `support_ticket.resolved`, linking to `/account/support/{number}`.
  - Business requesters get them in their panel bell.
- **Panels:**
  - **Admin → Support tickets** (`tickets.view`; acting needs `tickets.manage`). The queue filters by status, priority, category, requester type, assignee (me / unassigned / one person), overdue and search, with counts per status plus overdue and unassigned. A ticket can be replied to, given internal notes, assigned to an admin of the country, re-prioritised and moved through its states.
  - `tickets.view` is held by Support, Operations, Content and Account manager. `tickets.manage` is held by Support and Account manager; account managers see only their vendors' tickets.
  - Seller Center, Travel Partner, Shipping and Creator Studio each have a Support page (list, new, detail, reply, reopen, rate) behind the staff permission `{panel},support`.

### Seller storefront (QC-008)
| Method | Path | Notes |
|---|---|---|
| GET | `sellers/{slug}` | Optional token. Returns the store profile (see below). 404 unless it is an active product store in this market. |
| GET | `sellers/{slug}/products` | Same filters, sorts and paging as `products`, limited to this store. |
| GET | `sellers/{slug}/products/facets` | Facets for the store's products. |
| POST · DELETE | `sellers/{slug}/follow` | Auth, 60/min. `{following, followers}`. Idempotent. |
| GET | `me/followed-sellers` | Auth, `per_page`. `[{slug, name, logo, rating{average,count}, followed_at}]` with paged meta. |

`GET sellers/{slug}` returns:
- `slug`, `name`, `logo`, `banner`, `description`, `city`, `joined_at`
- `rating {average, count, histogram{5..1}, positive_percent}`
- `product_count`, `followers`, `following`
- `badges` (`top_rated`, `fulfilled_by_nawy`, `new_seller`)
- `policies {returns{window_days, note}, shipping{fee, free_over, fulfilled_by_nawy}, warranty{note}}`
- `reviews_url`

Legal details (CR number, tax id, street) are never sent.

### Search and discovery (QC-043, G-A1)
- `GET products?q=` on page 1 counts the normalised query per day. Only counts are kept: no user or IP.
- **`GET search/popular?limit=1..20`** → `[{query}]`: queries with at least 3 hits in 30 days that found products.
- **`GET search/trending?limit=`** → `[{type: query|category, label, query, category}]`. `meta.source` is either:
  - `searches`: the last 3 days against the 14 before;
  - `categories`: the fallback, the root categories with the most products.
- **`GET search/suggest?q=`** (2–100 characters, `limit` 1–10, 120/min) → `{queries[], products[{slug,title,image,price}], brands[{slug,name,logo}], categories[{slug,name}]}`. `%`, `_` and `\` match literally.
- **Recently viewed** (auth):
  - `GET me/recently-viewed` → product cards plus `in_stock` and `viewed_at`, newest first.
  - `POST me/recently-viewed` `{items:[{slug, viewed_at?}]}` (1–50, 60/min) merges the device list. The newest view wins and the list is capped at 50.
  - `DELETE me/recently-viewed` and `DELETE me/recently-viewed/{slug}`.
- **`GET products/{slug}/frequently-bought-together`** → `{product{slug, listing_id}, items[card + listing_id + bought_together_orders] (≤4), bundle_total|null}`.
  - It counts co-occurrence in orders from the last 90 days that were paid or delivered; cancelled, returned and refunded shipments are excluded.
  - A product needs at least 2 shared orders and a sellable offer.
- **`GET products/compare?slugs=a,b[,c,d]`** (60/min) → `{products[card + model_number, in_stock, listing_id, seller, options], specifications[{label, values[]}]}`. 422 `fields.slugs` for fewer than 2 or more than 4.

### Offers and PDP (QC-064, QC-057, QC-027, QC-060)
- **`GET offers`** (optional token) → `{coupons[], flash_sales[{id, slug, name, state, starts_at, ends_at, deals_count}]}`.
  - Each coupon is `{code, label, description, type, percent, amount, buy_quantity, get_quantity, min_order, max_discount, scope, seller, applies_to{type, items}, first_order_only, requires_sign_in, ends_at}`.
  - Only coupons marked **public** (a new checkbox on the admin coupon form; existing coupons are not public) that the shopper can still use are listed. One-use coupons, used-up coupons and coupons outside the customer's segment are never listed.
- **`GET products/{slug}/coupons`** returns coupon chips: the public coupons whose targets cover the product.
- **`GET products/{slug}`** adds `model_number` and `badges[{key,label}]` (`bestseller`, `new`, `top_rated`, `deal`, `fulfilled_by_nawy`). Product cards add `badges` (`bestseller`, `new`, `top_rated`).
- **PLP rail:**
  - `products` and `products/facets` take `fulfilment=fbm,fbn`, `condition=new,refurbished` and `deals=1`.
  - Facets add `fulfilment[{value,count}]`, `conditions[{value,count}]` and `deals`.
  - Size and colour stay in `attributes` (`attr[size]=M`).
  - "Arrives by" has no stored source (the promise is computed per offer), so it is not offered.
- **Trips:**
  - `GET travel/packages/{slug}` adds `gallery[{url, alt}]`, `stats{rating:null, travellers, bookings, agency_trips}` and `departures[].child_price_per_person`.
  - `POST travel/departures/{id}/bookings` takes `children` (0–19). 422 `children_not_offered` when the departure has no child price.
  - Bookings return `adults` and `children`. D8 is unchanged.

### Orders (QC-026, QC-050, QA-041, QA-043, QC-054, G-A4, G-A6)
- **`GET orders`** takes `tab` (`all` · `in_progress` · `delivered` · `cancelled` · `returns`), `q` (order or shipment number, item title, seller; `%` and `_` match literally), `page` and `per_page` (max 50; default 20). 422 for an unknown tab.
  - `meta`: `{total, page, per_page, last_page, tab, counts: {all, in_progress, delivered, cancelled, returns}}`. The counts respect `q`.
  - Each shipment now carries:
    - `actions: {track, invoice, cancel, return, review, buy_again, dispute}` (booleans; the buttons to show)
    - `delivered_at`
    - `return_until` (the last day to start a return)
  - Each item carries `listing_id`, so "Buy again" calls `PUT cart/items`.
  - Each order carries `invoice_available`.
  - The list loads in a fixed number of queries, whatever the page size (QC-050).
- **`POST orders/{n}/invoice-link`** (auth, 30/min, wave 5b apps) → `{url, expires_at}`: a signed 30-minute link to `orders/{n}/invoice/signed` that serves the same HTML invoice without a token, so the app opens it in the system browser. 409 `invoice_not_ready` as above; a tampered link answers 403.
- **`GET orders/cancel-reasons`** → `[{code, label, needs_note}]`. The codes are `changed_mind`, `ordered_by_mistake`, `found_better_price`, `delivery_too_slow`, `wrong_address`, `payment_issue` and `other`.
- **`POST orders/{n}/shipments/{s}/cancel`** takes an optional `reason`. When sent, it must be one of those codes, otherwise 422 `fields.reason`. `other` requires `note` (max 255). **Clients that send free text today must switch to the codes.**
- **`GET orders/{n}/invoice`** (auth, 30/min) returns a printable **HTML** tax invoice: shipments, lines, totals and VAT included. It answers 409 `invoice_not_ready` until the order is paid (online) or a COD shipment is delivered. No PDF library is installed, so the browser or app prints or saves it as PDF. A server-side PDF needs an approved package (e.g. `barryvdh/laravel-dompdf`).
- `shipping_address` (orders) and the agent's `address` no longer include the internal `address_id` (QA-043).
- The 409 `customer_contract_required` on `POST orders` is now built by `ApiResponse`. The shape is unchanged (`error.contracts`).
- `GET wallet/transactions`, `returns` and `classifieds/mine` now also send `meta.per_page` and `meta.last_page` (G-A4).

### Cart (G-A5, QA-047)
- `GET/PUT cart…` responses gain `data.free_delivery`: `{threshold: Money, remaining: Money, progress_percent: 0–100, qualified: bool}`, or null when the market has no threshold. This drives the "Spend X more for free delivery" bar. `remaining` is measured against the items' subtotal, as checkout does.
- A refused **first** write by a guest (e.g. `insufficient_stock`) no longer creates a cart row and no longer sends `X-Cart-Token`.

### Product page stock (QA-017, QA-018)
- `buy_box.in_stock` / `other_offers[].in_stock` are now net of other shoppers' live cart holds.
- `flash_sale.remaining` is never higher than `in_stock`.

### Saved cards (G-A6)
- **`GET me/cards`** → `[{id, gateway, brand, last4, exp_month, exp_year, expired, is_default}]` (current market). It holds gateway tokens only. Nawy never stores or returns card numbers, and the token is encrypted at rest and never returned.
- **`POST me/cards/{id}/default`** and **`DELETE me/cards/{id}`**. Another customer's card answers 404.
- A card is saved when the gateway reports a reusable token on capture (`GatewayOutcome::$card`). The Fake gateway supports this today. The Stripe, PayTabs and Thawani adapters do not request tokenisation yet, so "save this card" at checkout remains a gateway task.

### Push device tokens (G-A2)
- **`POST me/push-tokens`** `{token (16–512), platform: ios|android|web, app?: customer|delivery|vendor|marketer|supervisor|travel_agency}` → 201 on first registration, 200 when refreshed. Re-registering a token moves it to the signed-in account.
- **`DELETE me/push-tokens`** `{token}` → 200.
- Signing out (`auth/logout`) or revoking a device drops that device's token. Tokens are stored only: there is no FCM package, so nothing is sent yet.

### Countries (G-A8)
- `GET /api/v1/countries` and `{market}/context` add `names: {en, ar}`. `name` still follows the request language.

### Live streams (QC-040, QC-063)
- **`GET live-streams/{slug}/comments?after_id=`** returns visible comments, oldest first (the last 50, or up to 100 newer than `after_id` for polling). `meta: {viewers, likes}`.
- **`POST live-streams/{slug}/comments`** (auth, 10/min) `{body ≤ 300}`:
  - 201 `{id, author, body, created_at, status}`.
  - 422 `not_allowed` unless the stream is live.
  - A comment matching the review word list or containing a link is created `held`: only an admin can show it.
- **`POST live-streams/{slug}/presence`** `{viewer_id?}` (guest or signed in, 12/min) is a heartbeat; send it every ~30 s while watching. It returns `{viewers}`, the number watching in the last 60 s (0 when the stream is not live). The stream keeps its peak and unique viewer totals.
- Stream summaries (`live-streams`, `live-streams/{slug}`) add `viewers`.
- **`GET live-streams/floating`** returns the stream for the home floating mini-player, or `null`. This is a country setting, not a page block (QC-063). An admin sets it under Admin → Content → Live & videos to off, "the stream that is live now" (default) or one stream.
- Admin → Live & videos → stream: comments list with Hide / Show, and "Viewers now".

### Static pages (QA-032)
- Seven global pages exist: `contact-us`, `terms-and-conditions`, `privacy-policy`, `return-policy`, `shipping-policy`, `refund-policy` and `cookie-policy`.
  - They are **placeholders**: neutral EN/AR text saying the page has not been published yet. No terms were written.
  - `GET static-pages/{slug}` adds `placeholder: true` for them.
  - Saving a page in Admin → Content → Static pages clears the flag. The admin list marks them "Placeholder text: replace before launch". **Legal must replace them before launch.**

### Other contract changes
- Delivery app (QA-025): refused steps answer 422 `transition_refused`, not `not_allowed`.
- Delivery app (QA-042): `GET delivery/me` `cod_in_hand` is now a Money object `{amount, currency}`, not a string.
- Travel (QA-037): booking `rooms[]` is `{room_type, travellers, unit_price: Money, line_total: Money}`.
- Real estate (QA-019): `preferred_at` without an offset is read as the market's local time. `me/viewing-requests` returns times with the market's offset.
- Classifieds:
  - QA-020: `contact_phone` is validated per country and stored as E.164.
  - QA-040: `price` allows at most the currency's decimals (422 `fields.price`).
  - QA-039: attribute `min` / `max` are JSON numbers.
- Advertise (QA-023): `advertise-requests/options` band `min`, `max` and `label` are scaled per currency (OMR / KWD / BHD ÷10, JOD ÷5, EGP ×10). The keys are unchanged and are now opaque ids.
- Gift cards (QA-038): a method switched off in the country answers "This payment method is not available in {country}."
- Notifications (QA-024): a multi-shipment order's notices name the shipment ("Shipment … of your order … is delivered").
- Search (QA-035, QC-071): `%`, `_` and `\` match literally in every LIKE search (products, brands, help, blog, creators, travel destination, panels).
- CORS (QC-073): browsers may call the API only from `NAWY_STOREFRONT_URL` plus `NAWY_CORS_ORIGINS` (comma-separated). Add Flutter web origins there.
- `GET /` on the API host redirects to the storefront (QC-076).

### Lifecycles (QC-031…QC-037, QC-053)
- Shipments: `cancelled` can now follow `delivered`, as an admin force-cancel with a reason. A refund follows and no stock goes back. Show it as cancelled.
- Status history can carry the actors `agency` (travel agency team) and `gateway` (payment webhook).
- Marketer conversions have a new status, `paid`: a processed withdrawal covers it. It sits between `released` and `reversed` / `clawback_pending`. Label it "Paid".
- Campaigns can be `completed`, for imported legacy campaigns. It is terminal and behaves like `done`.
- Listings can be archived from `pending_review`.
- Documents: re-uploading a rejected or expired document returns the same document, back in `pending`.
- Payment webhooks (server to server):
  - Events go `received → verified → processed | duplicate | failed`; `ignored` is kept for signed events that need no action.
  - `data.result` is `duplicate` when the payment already had that outcome.
- Order auto-complete reads `order.auto_complete_time` from the settings (country override) and runs once a day per country (QC-038).
- The SRS job names are traced in lifecycle-catalogue Appendix C, with aliases `promotion:expire-items` and `coupons:close-expired-participation-invitations` (QC-039).

### Panels (no API)
- **Notification bell** in every panel (QC-041), with a page (`/notifications`) to open or mark read.
  - The store owner hears about confirmed and cancelled orders, return requests and new disputes.
  - Business requesters hear about support ticket replies.
  - Panel notices are kept apart from the same person's customer notices (`audience`).
- **Sign-in history** for the signed-in person (`/account/security`, QC-044), linked from the user chip.
- **Seller Center dashboard** (QC-062): a "Returns open" KPI replaces "Active listings", plus a **Download report** CSV of the store's shipments (`/dashboard/report?from=&to=`, last 30 days by default).
- **Shipping panel** (QC-062):
  - The overview has an **SLA by zone (30 d)** table.
  - Live dispatch has an **Unassigned & exceptions** table.
- **Creator Studio overview** (QC-062): Copy link, WhatsApp, X and email share for the referral link. A QR code needs a QR library, which is not installed.
- **Admin → Live & videos:** comment moderation, viewers now, and the home floating player setting.
- **Admin → Support tickets:** the queue described above.

### Screens wave 5b must build
- **website (Next.js):**
  - **Account → Orders** (Figma `33:658`):
    - tabs with counts, search, infinite or paged list;
    - per-shipment buttons from `actions` (Track, Invoice opens `orders/{n}/invoice`, Cancel with the reason list, Return, Review, Buy again via `cart/items`, Dispute).
  - **Account → Support:** list with a status filter; new ticket with category, order or shipment and attachments; detail with thread, reply, reopen and rating. Notification links point to `/[market]/account/support/{number}`.
  - **Seller storefront** `/[market]/seller/[slug]`: header, rating, policies, badges, follow, product grid with the filter rail, and a reviews tab. Also **Account → Followed stores**.
  - **Search:** suggest dropdown, popular and trending chips, and recently viewed synced after sign-in.
  - **PDP** (`24:2`): frequently bought together with "add all", coupon chips, badges and model number; the delivery and seller cards move to the right rail.
  - **`/[market]/compare`**.
  - **Home:** "Offers for you" and the floating mini-player from `live-streams/floating`.
  - **PLP** (`40:4438`): Fulfilled by, Condition, Deals, Size and Colour in the rail, plus quick chips and infinite scroll.
  - **Cart:** the "spend X more for free delivery" bar.
  - **Live player:** comments with polling and a box to post, and a viewer count with a presence heartbeat.
  - **Trip detail** (`69:6093`): 1+2 gallery, stats line, adults / children split with the child price.
  - **Static pages:** show a "placeholder" banner while `placeholder` is true.
  - **Cancel dialog:** use `orders/cancel-reasons`.
- **customer_app (Flutter):**
  - The same Orders tabs, actions and invoice, Support (list, new, detail), seller storefront and follow, suggest, popular and trending (replaces the G-A1 workaround), recently viewed sync, FBT, compare, offers and coupon chips, the free-delivery bar, live comments and viewers, and the trip gallery and children.
  - **Saved cards** screen (Figma "40 Cards"): `me/cards`.
  - Register the push token on sign-in and unregister it on sign-out (`me/push-tokens`).
  - Use `next page` from `meta.last_page`.
  - Show Arabic country names from `names.ar`.
- **delivery_app:** `cod_in_hand` is now a Money object; refused steps return `transition_refused`.
- **vendor_app:** Support list, new and detail (`ops/vendor/support/*`).
- **marketer_app / panels:** label the conversion status `paid`.

### Deferred (wave 5a)
- ~~**QC-011:** owner decision on the commission basis for travel-agency campaigns~~ — closed by owner decision D11 (see below).
- **QC-044:**
  - Domain / subdomain mapping needs an infra and DNS decision.
  - A full OpenAPI spec and typed client (ADR 13) is a separate tooling task, with no generator package installed.
  - Panel sign-in history is done.
- **QC-052:** new endpoints use Form Requests and Resources. The older ~70 controllers with inline validation stay as a refactor backlog: it is a no-behaviour-change sweep that is too wide for a QA wave.
- **QC-062:** the creator QR code needs a QR library.
- **G-A6:**
  - A server-side invoice **PDF** needs a PDF package; a printable HTML invoice ships now.
  - Real-gateway card tokenisation ("save this card" at checkout) needs work in each adapter. Storage, listing and the capture hook are ready.
- **G-A2:** a push sender needs an FCM package; only token storage ships now.
- **Support tickets:** staff alerts for new tickets, ticket APIs for the marketer, supervisor and travel apps (their panels have Support pages), business-hours SLA, canned replies and a CSAT report.
- **Discovery:**
  - Public wishlist groups.
  - "Arrives by" filter: there is no stored source for it.
  - Uploads for the travel gallery and the store banner (the columns and API are ready).
  - `is_public` on the Seller Center coupon form.
- **Module-missing lifecycles** (wave 3 list): only the support ticket machine is built. FBN (inbound, storage fees, packaging), inventory transfers, vendor subscriptions, product and change requests, acquisition commissions and flash-sale marketer invites stay deferred until their modules are scheduled.

## Owner decisions D11 / D12 (2026-11-18)

### D11: travel has no commission
- Travel agencies (`vendors.type = travel`) pay **no commission and no marketer-campaign fees**. Paid ad packages / slot bookings still apply and are charged as before (`ad_booking` statement lines).
- **Campaigns:** `CampaignWizard` refuses origin B (`travel_agency`) and any campaign (vendor A, platform C, marketer request D, peer E) on a travel agency's listing → `CampaignRefused` with code **`travel_no_campaigns`** (422 on the API / panel forms). So no campaign fee, sample commission or marketer commission can ever be booked for a travel agency, and no marketer conversion can come from travel.
- **Settlement:** `SettlementCalculator` gives a travel agency commission 0, VAT on commission 0, fixed fee 0 and FBN fee 0 (a guard: travel bookings take no payment under D8 and never settle).
- **Settings / admin:** the proposed `travel.platform_commission_percent` key is withdrawn and the `vertical` Marketing Fees row covers classifieds only (`settings-catalogue.md`). Admin → Vendors → a travel agency shows "no commission and no marketer-campaign fees" instead of the commission figures. The Travel Partner panel and `travel_agency_app` never had commission or campaign screens.
- QC-011 is closed by this decision.

### D12: Open Souq requests are records only
- A viewing / reserve request stores the customer's name, email, phone, preferred time and message, plus `created_at`. There is **no status, no state machine and no actions** (no confirm, reject, schedule, complete or cancel) anywhere: API, panels or apps. The old `Viewings::confirm()` / `move()` and the `ViewingStatus` enum are gone; no state-transition rows are written.
- `POST classifieds/ads/{n}/viewings` → 201 `{id, type, created_at}` (no `status`). The only mail is the advertiser's "new request" mail (`viewing.requested` / `reserve.requested`, in the advertiser's language). The customer's `viewing.received` / `reserve.received` mail and the `viewing.{status}` / `reserve.{status}` in-app notifications are removed; the client shows "Request sent".
- `GET me/viewing-requests` (auth, `type?`, `per_page?`) → `[{id, type, preferred_at, note, created_at, ad:{…}}]` — no `status`, no `scheduled_at`.
- **New `GET classifieds/mine/requests`** (auth, `type?`, `per_page?`): requests received on the signed-in user's own ads → the same record plus `contact:{name,email,phone}`, newest first; meta `{total,page,per_page,last_page}`. Used by the website ("Account → Inquiries → Requests → On my ads") and the customer app ("My requests → On my ads").
- **Panels:** Seller Center → Marketplace → **Viewing & reserve requests** (`partner.marketplace.requests`, classified shops) and Creator Studio → Broker → **Viewing & reserve requests** (`marketer.broker.requests`): read-only lists (ad, type, customer name / email / phone, preferred time, message, received) filtered by type. No buttons.
- Any confirm / reject / schedule / complete / status URL under `me/viewing-requests/{id}`, `classifieds/ads/{n}/viewings/{id}`, `classifieds/mine/requests/{id}`, `marketplace/requests/{id}` or `broker/requests/{id}` is 404; `PATCH me/viewing-requests` is 405.
- **Broker leads:** a request on a broker's ad still opens a `viewing` lead in the broker's own lead pipeline (a separate module with its own `new → contacted → viewing_scheduled → …` states). The request no longer moves the lead; only the broker does.
- **Data:** the `property_viewings.status` and `scheduled_at` columns are kept but frozen (new rows keep the `requested` default; nothing writes, reads or serialises them), so earlier rows lose nothing. No migration.
- **Figma follow-ups (design agent):** these frames still show a request status or seller-confirmation flow and need the D12 copy ("Request sent", seller contacts you directly, no status, no "cancel before the seller confirms"): `153:2881` App "60 Souq — Request sent" (Status row + "Requested — awaiting seller confirmation" + "What happens next" steps 1–2), `151:10399` web "Viewing & reserve request sent" modal (Status chip, "awaiting seller confirmation", steps 1–2, "Account › My bookings" hint), `169:11387` its AR sibling, `153:2795` App "59 Souq request" ("The seller confirms the time"), `151:10194` web request form ("confirms the time", "can be cancelled any time before the seller confirms") and its AR sibling `169:11200`, `70:6945` property viewing form copy. New screens without a frame: account → inquiries → requests ("Sent" / "On my ads" record list, frame `50:2352` only draws the Chats tab), the app "My requests" list, Seller Center → Marketplace → Requests and Creator Studio → Broker → Requests (read-only tables).

## Owner decision D14 (2026-11-18): travel free listing, paid placement

Agencies list trips for free. A **travel promotion** puts one of the agency's live trips first in travel lists, travel search and page-builder travel blocks (website and app), always labelled **"Sponsored" / "مُموَّل"**. The agency pays Nawy by card on the country gateway through the same payable flow as gift cards and Nawy One. Customer bookings stay payment-free (D8); there is no commission or other fee (D11).

### Model (Advertising domain, country databases)
- **Placement:** an `ad_slots` row `code = travel_sponsored`, `placement = travel` (`AdSlot::PLACEMENT_TRAVEL`). `max_concurrent` is the **cap** of promoted trips that lead one list; `is_active` switches the placement on or off for the country. The slot is created on first use with `travel.promotion_slots` (default 3). It is excluded from vendor / marketer banner booking (`AdSlot::bookable()`, `AdBookings::draft` refuses it).
- **Products** `travel_promotion_products` (admin): `code`, `name_en/ar`, `duration_days`, `price` (VAT included, country currency, 2 or 3 decimals), `is_active`. Prices are per country because each country database has its own rows.
- **Promotions** `travel_promotions`: `number` (`TP-AE-000001`), `vendor_id`, `travel_package_id`, product, slot, `duration_days`, `currency`, `amount`, `vat_amount`, `status`, `bought_by_user_id`, `paid_at`, `starts_at`, `ends_at`, `impressions`, `cancel_reason`. History is kept in `state_transitions` (`subject_type = travel_promotion`).
- **State machine** (`TravelPromotionStatus`): `pending_payment → active | cancelled`; `active → expired | cancelled | refunded`; `expired → refunded`.

### Flow (`TravelPromotions` service)
1. **Buy:** Travel Partner panel → `POST promotions {package_id, product_id}`. Only an agency's own trip that is `active` (published, bookable) with an active agency, and an active product on an active travel slot. This creates a `pending_payment` promotion and a payment `payable_type = travel_promotion` on the country's recommended **card** gateway (`PaymentAttempts::startForPayable`; COD, Nawy Credits, gift cards, BNPL and PayPal are not offered). The panel redirects to the gateway.
2. **Return / webhook:** the gateway return URL `payments/{reference}/return` verifies with the gateway and, for this kind, redirects to the **panel** `promotions/return?payment=…&status=…` (not the storefront). The panel verifies again and shows the result. A signed webhook or `POST payments/{reference}/verify` work as for other payables; `GET payments/{reference}` returns `purpose: travel_promotion`, subject `{number, status, amount, starts_at, ends_at}`.
3. **On capture** (`PaymentCaptured` → `TravelPromotions::paid`): ledger `travel-promotion:{id}`: Dr gateway clearing / Cr `ad_revenue` (net) + Cr `vat_payable` (VAT inside the price at the country rate, half-up, at the currency scale). This is the same booking as classified packages. Then the promotion goes `active` with `starts_at = now` (or the end of a live promotion of the same trip, so windows queue) and `ends_at = starts_at + duration_days`. No vendor statement line or vendor posting is made.
4. **Retry:** `POST promotions/{id}/pay` opens a new card attempt for a `pending_payment` promotion. Any pending attempt is verified first.
5. **Job** `nawy:travel:expire-promotions` (every 5 min, per country through `ForEachCountry`): `active` past `ends_at` → `expired`. A `pending_payment` promotion older than `travel.promotion_payment_minutes` (default 60) is verified once more, then → `cancelled` ("Payment not completed").
6. **Admin:**
   - **Cancel** (no money back): `active` stops at once.
   - **Refund:** full gateway refund through `PaymentRefunds` (key `travel-promotion:{id}:refund`), reverse posting Dr ad revenue + VAT payable / Cr clearing, then → `refunded`.

### Ranking (`TravelSponsoredRanker`, `Travel\Support\TravelCatalog`)
- **Eligible:** an `active` promotion inside its window, for a trip that matches the list's filters, is `active` (not sold out, expired, draft or rejected) and whose agency is active. One entry per trip.
- **Cap and rotation:** at most the slot cap lead the list. When more are live, they rotate fairly: a window of `cap` over the live promotions (fixed order) moves one step every `travel.promotion_rotation_minutes` (default 15) and wraps around, so each gets the same time at the top. Each served promotion counts an `impression` (page 1 only).
- **Order:** promoted trips come first on page 1 and are removed from the organic pages (no duplicates). The rest keep the normal order (newest approval first).
- **Label:** `travel.promotion_label_en` / `_ar` (default "Sponsored" / "مُموَّل").

### API
- `GET travel/packages` (`destination`, `trip_type`, `month`, `page`): every card has **`sponsored: bool`** and **`label: string|null`**. Meta is `{total, page, sponsored}`, where `sponsored` is the count of promoted cards on this page; `total` includes them.
- `GET travel/packages/{slug}`: the trip has `sponsored` + `label`, plus **`related`**: up to 4 similar trips (same destination or trip type), promoted ones first, each with `sponsored` / `label`.
- **Page builder:** `travel_packages_carousel` blocks now carry `data.packages` (the same list: settings `trip_type`, `destination`, `limit`). The website and app still fetch `travel/packages`, which has the same order.
- **Ops app:** `GET ops/travel/promotions` (`tenant.can:travel_agency,promotions`) → `[{id, number, status, status_label, package:{id,title}, product:{code,name,duration_days}, amount:{amount,currency}, starts_at, ends_at, created_at}]`, meta `{buy_url}`. `buy_url` is the panel "Promote a trip" page, which the app opens in the system browser.

### Panels and permissions
- **Travel Partner** (`travel-agency.`, group **`promotions`**):
  - Pages: Promote → **Promotions** (list with status, window, "Pay by card" for unpaid, receipt for paid) and **Promote a trip** (choose trip → option with price → pay by card).
  - **Receipt** `promotions/{id}/receipt`: a tax receipt (net, VAT, total, payment reference and method, refunds) drawn from the **payment record**, because travel bookings never settle and there is no vendor statement.
  - Access: Owner and Operations full; Finance and Viewer read only (role matrix §6).
- **Admin** Growth → **Travel promotions** (`marketing/travel-promotions`):
  - Settings: placement on/off and cap.
  - Options: name EN/AR, duration, price; price validated to the currency's decimals.
  - Promotions list with filter, impressions, cancel and refund.
  - Permissions: `travel_promotions.view` (Finance, Marketing) and `travel_promotions.manage` (Marketing); re-seed `AdminRoleSeeder`.

### Clients
- **Website:** `TripCard` shows the "Sponsored" badge (server label, fallback `common.sponsored`) on travel home, search, the carousel block and related trips. The trip page shows the badge and a "You may also like" section. `/checkout/payment?kind=travel_promotion` shows a neutral message pointing to the Travel Partner panel.
- **customer_app:** the same badge (`AdLabel`) on travel cards and the trip screen, a related-trips rail, and server order kept.
- **travel_agency_app:** a new **Promote** tab with the promotions list (status chip, trip, option, amount, window) and "Promote a trip", which opens `buy_url` in the system browser.

### Figma follow-ups (design agent)
There is no frame yet for:
- Travel Partner "Promotions" list, "Promote a trip" (trip, option, price, pay) and the promotion receipt;
- Admin "Travel promotions";
- travel_agency_app "Promote" tab;
- the "Sponsored" badge on the web and app travel cards and trip header;
- the "You may also like" related-trips row on the web and app trip page.

## Owner decision D16 (2026-11-18): backend and website packages

Installed (exact versions): backend `barryvdh/laravel-dompdf` v3.1.2 with `dompdf/dompdf` v3.1.6 (v3.1.5 and older carry security advisories), `chillerlan/php-qrcode` 6.0.1; backend Vite and website `laravel-echo` 2.6.3 + `pusher-js` 8.6.0; website `livekit-client` 2.24.1. Nothing needs a key to keep working: without Reverb or LiveKit the clients fall back to polling / HTML / the poster.

### Invoice PDF (dompdf)
- **`GET orders/{number}/invoice/pdf`** (auth, the order's customer only, `throttle:30,1`): `application/pdf`, `Content-Disposition: attachment; filename="invoice-{number}.pdf"`. 409 `invoice_not_ready` until paid (COD: once a shipment is delivered); 404 for another customer's order.
- **`POST orders/{number}/invoice-link`** now also returns `pdf_url`: the same 30-minute signed link with `format=pdf` (inside the signature).
- Template `resources/views/invoices/order-pdf.blade.php`: Inter (bundled in `backend/resources/fonts/inter`, OFL) with DejaVu Sans as the per-glyph fallback that shapes Arabic. In Arabic the columns are mirrored and right-aligned (dompdf does not reorder table columns for `direction: rtl`). Amounts go through `MoneyFormatter` (3 decimals for OMR, KWD, BHD, JOD). dompdf caches font metrics in `storage/fonts` (must be writable).
- Panels: Admin → Orders → order: **Download invoice (PDF)** (`/orders/{id}/invoice.pdf`, `orders.view`; an admin scoped to some sellers gets 403 unless every shipment is in scope). Seller Center → order: **Download invoice (PDF)** (`/orders/{subOrder}/invoice.pdf`): only that shipment's lines and totals, with the VAT they include.
- Website: the order page links **Download invoice (PDF)** through `/api/invoice/{market}/{number}?format=pdf` (the bearer token stays in the httpOnly cookie).

### Referral QR codes (chillerlan/php-qrcode)
- Encodes `share.qr_payload` (`{storefront}/r/{code}?utm_source=qr&market=…`).
- **`GET ops/marketer/links/{invitation}/qr?format=svg|png`** (marketer app token; the marketer's accepted invitations only, else 404; 422 for another format): `image/svg+xml` or `image/png`, `attachment; filename="referral-{code}.{svg|png}"`. `GET ops/marketer/links` adds `qr_url` per link.
- Creator Studio → Links & codes: a QR preview per link with **Download PNG** / **Download SVG** (`/links/{invitation}/qr`).

### Realtime (Reverb + laravel-echo)
- **`POST broadcasting/auth`** (auth:sanctum, form fields `socket_id`, `channel_name`): Pusher's `{auth}` body as is (no envelope); 403 for a channel the user may not join. The website calls it through its proxy `/api/nawy/{market}/broadcasting/auth`; the apps can call it with their token.
- Events (queue `broadcasts`; a broadcast that cannot be sent is reported, never fails the request):
  - public `live-stream.{slug}`: `comment.posted` `{id,author,body,created_at}` (visible comments only, also when an admin shows a held one) and `likes.updated` `{likes}`;
  - private `App.Models.User.{id}`: `notification.created` `{id,type,order}` for each customer notification (`order` = order number or null);
  - private `country.{code}.ops`: `delivery.dispatch` (unchanged, ADR 15).
- Website (`lib/realtime.ts`, on only with `NEXT_PUBLIC_REVERB_APP_KEY`): the live chat appends pushed comments and likes and polls every 30 s instead of 5 s while subscribed; the header bell refreshes the unread count, the notification centre and the order on `notification.created` (polling every 5 min instead of 1 min while subscribed).
- Panels (`VITE_REVERB_ENABLED=true` + `VITE_REVERB_APP_KEY`): Admin Delivery → Dispatch and the carrier Live dispatch board join `country.{code}.ops` and count changes since load with a Reload button; without Reverb they say the board refreshes on reload.

### LiveKit browser viewer (livekit-client)
- `GET live-streams/{slug}` → `playback` (type `live`) adds **`webrtc`**: true only with `NAWY_LIVE_MEDIA_DRIVER=livekit`, the stream live and a token issued. The fake driver keeps it false.
- Website watch page: with `webrtc` it loads `features/live/livekit-viewer.tsx` (dynamic import, `ssr: false`, its own chunk) and joins with the subscribe-only viewer token; "Tap for sound" when the browser blocks autoplay audio. Without it, or when joining fails / the room disconnects, the page shows the HLS player or the poster as before.

## WP11 extras (2026-11-19): legacy redirects on the platform

- **`GET /api/v1/legacy-redirects?path={old path}`** (public, no market prefix, default API throttle): the 301 target of an old-platform URL from `legacy_redirects` (built by `legacy:redirects`) → `{data: {from, to, status: 301}}`, or 404 `not_found`; 422 without `path`. The path is normalised (decoded, no query, no trailing slash). Answers, found or not, are cached per path for a day and dropped when the map is rebuilt; `Cache-Control: public, max-age=3600` (404: 300).
- Website: `proxy.ts` calls it (through `lib/legacy-redirects.ts`, 2.5 s timeout, fail-open, 10-minute in-memory cache) only for a market path that `lib/missing-routes.ts` decided is missing, and for un-prefixed old-portal paths (`/{cc}/…`, `/helpcenter`, `/adsupport`, `/blog`, `/join`, `/vendors`, `/marketers`, `/companies`, `/faq`); a hit answers **301** to the same-site path, keeping the query. Example: `/uae-en/live-streams` → `/uae-en/live`, `/uae-en/orders/{legacy-uuid}` → `/uae-en/account/orders/{number}`.
- Panels / API host: an old path that matches no route (GET/HEAD, not `api/*`) answers 301 to `NAWY_STOREFRONT_URL` + the new path instead of 404.

## Fulfilled by Nawy (FBN, 2026-11-19)

### Storefront
- `OfferResource` (product page `offers[]`, buy box) adds **`fulfilled_by_nawy`** (bool, `fulfilment === "fbn"`); `fulfilment` stays as before. FBN offers use the warehouse handling days (`fbn.handling_days`, default **0**) instead of the seller's in `delivery` (ETA), so their promise is faster.
- Website: `features/product/fbn-badge.tsx` shows a **"Fulfilled by Nawy"** chip in the buy box, the delivery card and the other-offers list (EN/AR), and FBN offers keep the express delivery promise.

### Vendor app (`/api/v1/{market}/ops/vendor/fbn/*`, token ability `ops:vendor`, Seller Agreement signed, staff permission group `inventory`)
- **`GET fbn/stock?low=1`** → `[{listing_id, sku, title, on_hand, reserved, available, damaged, low_stock, long_term, unsellable, warehouses: [{warehouse_id, code, name, bin, on_hand, reserved, available, damaged}]}]` — the store's FBN listings (low = available ≤ `fbn.low_stock_threshold`, default 5).
- **`GET fbn/warehouses`** → active warehouses `[{id, code, name, city, address}]`.
- **`GET fbn/inbound?status=`** (paginated, `meta {current_page, last_page, total}`) → `[{id, number, reference, status, warehouse{id,code,name}, lines_count, units, cartons, expected_at, submitted_at, can_submit, can_ship, can_cancel}]`.
- **`GET fbn/inbound/{id}`** → the row plus `carrier_name, tracking_number, notes, labels_url` (Seller Center PDF, after submit) and `lines[{id, listing_id, sku, title, quantity_expected, cartons, quantity_received, quantity_damaged, quantity_short}]` (`quantity_short` once received).
- **`POST fbn/inbound`** `{warehouse_id, lines: [{listing_id, quantity, cartons?}], cartons?, expected_at?, notes?, submit?: bool}` → 201 with the plan (draft, or submitted with its `FBN-IN-{CC}-000123` number when `submit`).
- **`POST fbn/inbound/{id}/submit|ship|cancel`** (`ship`: `carrier_name` required, `tracking_number` optional) → the plan.
- Refusals (state machine, product not enrolled in FBN, no active warehouse) → 422 `transition_refused`. `GET ops/vendor/stock` rows add `fulfilment`; `POST ops/vendor/stock/{listing}` on an FBN listing → 422 `fbn_stock` (warehouse stock is counted by the warehouse).
- Statuses: `draft → submitted → in_transit → arrived → receiving → received → closed`, `cancelled` from draft / submitted / in transit.
- `vendor_app`: Stock → warehouse icon → **Fulfilled by Nawy** (Warehouse stock tab, Inbound plans tab with new plan, detail with submit / ship / cancel). Carton labels are printed from Seller Center.

## Gap closure (2026-11-19): legacy phone sign-in, push, funnel, idempotency, caching

### Customer auth (legacy phone-only accounts, data-migration-plan §13.6)
- **`POST /api/v1/{market}/auth/phone-code/send`** `{phone}` (local or international) → 202 `{sent: true}` always; an SMS code goes only to a migrated account with `requires_email`. Throttle `phone-code`.
- **`POST /api/v1/{market}/auth/phone-code/verify`** `{phone, code, device_name}` → `{token, user}` like the email code. The BFF stores the token (`SIGN_IN_ENDPOINTS`).
- Sign-in answers and `GET me` add **`requires_email`** (bool). While true, signed-in routes other than `GET me`, `POST me/email`, `POST me/email/verify`, `POST auth/logout`, notifications and push tokens answer **403 `email_required`**. `POST me/email/verify` clears it.

### Orders
- **`POST /{market}/orders`** honours a client **`Idempotency-Key`** header (8–100 of `A-Za-z0-9_:.-`): the same key returns the first answer with `Idempotent-Replayed: true`; another body with the same key → 422 `idempotency_key_reused`; a retry during the first request → 409 `request_in_progress`. Kept 24 h; 5xx answers are not kept. Website checkout and the customer app send one key per order attempt (kept only when the outcome is unknown). The BFF forwards the header.

### Portal
- **`POST /{market}/portal/funnel`** `{event: view|step|submitted, page, type?, step?}` → 202; the website sends every portal view / wizard step / submit (keepalive). Admin → Operations → **Portal analytics** (`admin.portal-analytics`, `applications.view`): visitors and views per portal page, and per wizard the visitors per step and submitted (7 / 30 / 90 days). Pruned by `nawy:analytics:prune` (04:30).

### Push notifications
- Every in-app notification (`CustomerNotifications::notify`, customer centre and panel bells) is pushed (queued) to the user's devices of that app (`me/push-tokens`; `customer` → customer app, `panel:partner` → vendor app, `panel:marketer`, `panel:carrier` → supervisor, `panel:travel_agency`, `panel:delivery`), `data` = `{link, type, notification_id, …}`. Driver `NAWY_PUSH_DRIVER`: `log` (dev) or `fcm` (needs the owner's Firebase project id + service account).

### Caching and fixes
- Cached server-side and forgotten on every change: `GET countries`, `GET {market}/context`, `GET {market}/brands` (unfiltered, 5 min), `GET {market}/help/{hub}/collections`, `GET {market}/static-pages/{slug}` (also its 404), `GET {market}/blog/categories`, `GET {market}/app/contexts`. The footer was already cached through settings. Answers are unchanged.
- `GET /api/v1/legacy-redirects?path=` with an empty, blank or root path → **404** `not_found` (was 422); a missing `path` is still 422.
- `docs/api/openapi.json`: OpenAPI 3.1 of `/api/v1`, generated from the routes by `php artisan nawy:openapi`.

## Delivery agent documents, payslips and wallet; parcel barcode; classified photo limits (2026-11-19)

All delivery routes are under `/api/v1/{market}/delivery/` with a `delivery` token (agents with status `pending_documents` can sign in to upload). Money is `{amount, currency}`.

**Documents** (reuses the Documents domain: pending → approved | rejected; approved → expired by the daily `nawy:documents:expire`; a rejected or expired type goes back to pending on re-upload; Nawy reviews in Admin → Document review).
- `GET me/documents` → `{requirements: [{id, code, name, mandatory, has_expiry, max_mb}], uploads: [{id, document_type_id, type, status, valid_until, expires_in_days, expiring_soon (approved and ≤ 30 days), rejection_reason, original_name, mime, size, uploaded_at}], missing: [document_type_id]}`.
- `POST me/documents` (multipart, 20/min): `document_type_id` (a delivery-agent type of the country), `file` (pdf, jpg, jpeg, png, webp; ≤ the type's `max_mb`, at most 20 MB), `valid_until` (`Y-m-d`, after today; required when `has_expiry`) → 201 upload.
- `GET me/documents/{id}/file` → the agent's own file (download). The delivery web panel takes the same rules. The shipping panel shows its drivers' status and expiry at `/driver-documents` (no files).

**Payslips.** `nawy:delivery:issue-payslips` (daily 02:30 admin time, per country) issues one payslip per agent per closed pay period (`delivery.payslip_period` weekly Mon–Sun | monthly, country dates) for every delivered or failed assignment not on a payslip yet, at `delivery.pay_per_stop` / `delivery.pay_per_failed_attempt`; work finished late in a paid period gets a supplementary payslip. No `pay_per_stop` = no payslips. Issuing posts Dr `delivery_pay_expense` / Cr `agent_payable:agent:{user}:spendable`.
- `GET me/payslips` → `[{number, period_start, period_end, delivered, failed, per_stop, per_failed, total, issued_at}]` (newest first, paginated 30).
- `GET me/payslips/{number}` → same + `lines: [{assignment_number, sub_order_number, outcome: delivered|failed, finished_at, amount}]`.
- `GET me/payslips/{number}/pdf` → `application/pdf` with `Content-Disposition: attachment; filename="payslip-….pdf"` (exposed through CORS). Delivery panel: Earnings page, `/payslips/{number}` (HTML) and `/payslips/{number}/pdf`.

**Wallet and withdrawals** (pending → approved → paid; pending | approved → rejected; pending → cancelled by the agent).
- `GET me/wallet` → `{available, reserved, paid_out, minimum, can_withdraw, paid_by: nawy|carrier, carrier, last_payout: {account_holder, bank_name, iban_last4}|null, withdrawals: [withdrawal]}`; withdrawal = `{number, amount, status, reason, payout: {account_holder, bank_name, iban_last4}, requested_at, approved_at, paid_at, reference}`.
- `POST me/withdrawals` `{amount, account_holder, bank_name, iban}` (10/min; IBAN checked with ISO 13616 mod-97; bank details stored encrypted) → 201 withdrawal; 422 `transition_refused` below `delivery.withdrawal_minimum` (default 50), above the available balance, or while another withdrawal is open. Moves spendable → reserved.
- `POST me/withdrawals/{number}/cancel` → withdrawal (pending only; money back to spendable).
- Review: Nawy fleet drivers in Admin → Delivery & logistics → Agent withdrawals (`payouts.view`; approve/reject need `payouts.approve`, mark paid needs `payouts.process` and a bank reference); a carrier's drivers in the shipping panel → Agent withdrawals (tenant group `payouts`, supervisors F). Four-eyes (`delivery.withdrawal_four_eyes`, default on): the reviewer who approved cannot mark it paid. Paid posts Dr agent reserved / Cr `bank_clearing` (fleet) or Cr `carrier_receivable` (the carrier paid its driver for Nawy and sets it off against what it owes).

**Parcel label barcode.** The label of a shipment (A6; Admin `delivery/shipments/{id}/label[.pdf]`, shipping panel `shipments/{id}/label[.pdf]`, Seller Center `orders/{subOrder}/label[.pdf]` once the delivery is booked) carries a **Code 39** barcode of `<shipment number>.<C>`: the shipment (AWB) number is `SH-<sub-order number>` (e.g. `SH-NW-AE-1000123-1`) and `C` is its Code 39 **mod-43** check character over `0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ-. $/+%` (sum of the character values mod 43; `SH-NW-AE-1000123-1` → 276 mod 43 = 18 → `I`, payload `SH-NW-AE-1000123-1.I`). Readers normalise to upper case, drop spaces and the `*` start/stop characters, split at the last `.`, and reject a wrong check character as a misread. A check character of `.` gives `X..`; a space check character may be trimmed by the scanner, leaving `X.` — both are accepted. A code without `.` is a number typed by hand and matches the shipment, sub-order, order or assignment number. The assignment JSON has `barcode` (the payload); `POST assignments/{number}/scan {code}` → `{matches: true, shipment_number}` or 422 `bad_label` / `wrong_parcel`. Implementations: `App\Domains\Delivery\Support\ParcelBarcode` and `delivery_app/lib/data/models.dart` (`parcelCheckChar`, `parseParcelBarcode`, `matchParcelCode`).

**Location pings.** `POST me/location` also takes `accuracy` (m), `heading` (0–360), `speed` (m/s) and `recorded_at` (ISO 8601) from the app's background tracker (throttle 120/min).

**Classified photo limits.** Every node of `GET classifieds/categories` and `GET classifieds/categories/{slug}/attributes` has `max_photos` (the category's own value, else `classified.max_photos` 20) and `photo_max_kb` (nearest value up the tree, else `classified.photo_max_kb` 8192; capped at 20480). The attributes response also has `allows_video`, `video_max_seconds` and `video_max_kb`. `POST classifieds/ads/{n}/photos` and the Seller Center marketplace form enforce the category's limits. Admins edit both per category in Admin → Classified categories (empty = inherit / default). `GET classifieds/map` features now also carry `image`, `city`, `area` and `property` for map popups.

## Custom domains and PayPal in USD (2026-11-19)

- **`GET /api/v1/domains/resolve?host={host}`** (QC-044): what a request host serves → `{host, kind: storefront|panel|portal|api, country, locale, market, panel, is_primary, redirect_to, canonical_host}`; 404 `domain_not_mapped` when the host is not mapped. Cached 5 minutes (`Cache-Control: public, max-age=300`). The website proxy (`website/lib/domain-market.ts`) uses it: a `redirect_to` host answers 301; on a storefront host, un-prefixed URLs open its market instead of the cookie / default market.
- **`GET /api/v1/{market}/context`** gains `meta.storefront_host` — the market's primary storefront host (null = the default website host); use it for canonical / hreflang links.
- **`GET /api/v1/{market}/checkout/payment-methods`**: each method has `charge` — `{currency, amount, rate}` when the provider charges another currency (PayPal in USD), else `null`. Show it under PayPal ("You'll be charged USD 27.23 · 1 AED = 0.2723 USD"). PayPal is listed only in countries where an admin added a rate.
- **Payment resource** (order detail `payment`, gift card / Nawy One / subscription payments) gains `charge: {amount: {amount, currency}, rate} | null`. All other amounts stay in the market currency; refunds are shown in the market currency.
- **Apps:** no other change (subscriptions, acquisition commissions, reports, product / change requests, packaging and flash-sale invites are panel-only).


## Ops-app documents, vendor FBN enrolment & labels, in-app travel promotion, acquirer referral, plan auto-renew (2026-11-19)

**Ops-app documents** (`ops:{role}` token; same Documents domain rules as Seller Center and the delivery app):
- `GET ops/{vendor|marketer|supervisor|travel}/documents` → `{requirements: [{id, code, name, mandatory, has_expiry, max_mb}], uploads: [{id, document_type_id, type, status: pending|approved|rejected|expired, valid_until, expires_in_days, expiring_soon (approved and ≤ 30 days), rejection_reason, can_reupload, original_name, mime, size, uploaded_at}], missing: [type ids]}`, `meta: {business_type, mimes, expiring_soon_days}`. Types per workspace: product store → `vendor`, Open Souq store → `classified`, travel agency → `agency`, marketer → `marketer` (broker-only profile → `broker`), shipping company → `carrier`.
- `POST …/documents` (multipart, 20/min): `document_type_id` (a type of that business type), `file` (pdf / jpg / jpeg / png / webp, ≤ the type's `max_mb`, 20 MB cap), `valid_until` (`Y-m-d`, after today; required when `has_expiry`) → 201 upload (pending). A rejected or expired type is re-uploaded on the same row (back to pending, history kept).
- `GET …/documents/{id}/file` → the workspace's own file (404 for anyone else's).
- Permission groups: vendor `partner,documents` (reachable before the Seller Agreement is signed), marketer `marketer,profile`, supervisor `carrier,agents`, travel `travel_agency,packages`. Delivery documents additionally return `can_reupload`.
- Apps: Settings → Documents in `vendor_app`, `marketer_app`, `supervisor_app`, `travel_agency_app` (shared `PartnerDocumentsScreen` in `nawy_core`).

**Vendor app FBN** (`ops/vendor/fbn/*`, group `partner,inventory`):
- `GET listings?fulfilment=fbn|fbm` → `[{listing_id, sku, title, status, fulfilment: fbn|fbm, warehouse_units, can_enrol, can_leave}]`, `meta: {current_page, last_page, total, has_warehouses}` (50 per page).
- `POST listings/{id}/enrol` / `POST listings/{id}/leave` → the row; 422 `transition_refused` (leave with warehouse units, an open inbound plan or removal; archived listing).
- `GET inbound/{id}/labels.pdf` → `application/pdf` (A6 carton labels, as Seller Center); 409 `transition_refused` before the plan is submitted. Inbound detail gains `labels_path` (`ops/vendor/fbn/inbound/{id}/labels.pdf`, null before submit).

**Travel agency app — in-app promotion purchase** (`ops/travel/promotions/*`, group `travel_agency,promotions`):
- `GET options` → `{packages: [{id, title}] (active, promotable), products: [{id, code, name, duration_days, price, vat}], vat_rate}`.
- `POST /` `{package_id, product_id}` (10/min) → 201 `{promotion, payment: {reference, status, method, redirect_url (pending only), failure_message}, result}`; 422 `transition_refused` (trip not live, option not on sale, card unavailable).
- `GET {id}`, `POST {id}/pay` (pay again, 201), `POST {id}/verify {payment?}` (asks the gateway, never trusts the return) → same shape. `result`: `active` | `scheduled` (paid, starts after the running one) | `pending` | `failed` | `paid_inactive` | `closed`. Promotion rows gain `can_pay`; `GET promotions` meta gains `in_app: true` (`buy_url` kept for the web panel).
- The payment remembers `payments.return_url` = `nawy.payments.app_return_urls.travel_agency` (`NAWY_TRAVEL_APP_RETURN_URL`, default `nawytravel://promotions/return`); the gateway return (`payments/{ref}/return|cancel`) then redirects to `{return_url}?payment={ref}&status={status}` instead of the web panel.

**Partner applications — acquirer (FR-FIN-6):** `POST partner-applications` accepts optional `ref` (an account manager's code `AM{userId}-{8-char HMAC}`, from `{storefront}/{market}/join?ref=…`; the website keeps it 30 days in `localStorage` `nawy_join_ref`). A genuine code of an active admin of the country is stored as the application's acquirer; unknown codes are ignored. Admin can set / clear it on the application (`POST applications/{id}/acquirer`, or `acquired_by_user_id` with `decision=approve`); approval sets `vendors.acquired_by_user_id` / `acquired_at`. Each admin's link is on My acquisition commissions.

**Vendor plan auto-renew and PayPal USD (Seller Center only, no API change):** `POST subscription/auto-renew {enabled, saved_payment_card_id}` — the store charges each card invoice to a saved card token (merchant-initiated; `ChargesSavedCards` gateway capability: fake driver and Stripe PaymentIntents `off_session`), retries a decline `subscription.auto_charge_retries` (3) times every `subscription.auto_charge_retry_hours` (24) h, then the normal manual pay → past_due flow. Invoices record `payment_id`, `paid_amount` and, for PayPal in USD, `charge_currency` / `charge_amount` / `exchange_rate` (shown in Seller Center and on the PDF); the ledger stays in the country currency like order payments.

## Saved cards screen and classified ad status on the storefront (2026-11-19)

**Account → Saved cards (QA-C05):** the website now uses the existing `me/cards` API (no API change): `GET me/cards` → `[{id, gateway, brand, last4, exp_month, exp_year, expired, is_default}]` (default first), `POST me/cards/{id}/default` → the card, `DELETE me/cards/{id}` → `{removed: true}` (the next most recently used card becomes the default). Page `/{market}/account/cards` (`features/account/saved-cards.tsx`, client `lib/api/saved-cards.ts`): brand, `•••• last4`, expiry `MM/YY`, Default / Expired badges, "Set as default", "Remove" behind a confirm, empty state; EN/AR (`accountPlus.cards`), the last 4 and expiry are LTR-isolated in RTL. Cards are only ever saved as gateway tokens at checkout ("Save this card"); the website never collects or shows a card number. Tests: Vitest `tests/features/saved-cards.test.tsx`, browser CUS-08 (`e2e/specs/areas/customer/account.spec.ts`).

**Archived / paused / sold classified ads answer 404 at once (BT-SOQ-04):** the ad page's data fetch is `cache: "no-store"` (no Next data cache, no `"use cache"`), and the real HTTP status comes from the proxy lookup (`lib/missing-routes.ts`, QA-012), which asks `GET classifieds/ads/{number}` (404 unless the ad is public). That lookup used to keep a positive answer for 60 s per process, so an ad rendered while live kept answering 200 (with the not-found body) right after it was archived. Classified ad lookups (`/classified/find/{number}`) are now `fresh`: checked on every full page load and never answered from that cache (`/property/{number}` already was, as a kind-checked lookup). With no cached layer left for ad detail, status changes need no revalidation webhook from the backend. Tests: Vitest `tests/lib/missing-routes.test.ts` ("never caches classified ad lookups"), browser SOQ-18 (`e2e/specs/areas/souq/post.spec.ts` now visits the live ad, archives it and expects HTTP 404).
