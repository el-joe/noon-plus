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
- **D8 requests on every vertical:** `POST classifieds/ads/{n}/viewings` takes `type: viewing|reserve` (default `viewing`) with `{name,email,phone,preferred_at,message?}`, guests too → 201 `{id,type,status}`. Allowed types per vertical come from the setting `classified.request_types` (default: viewing + reserve for general, property, motors; country override wins); 422 when the ad is not live or the type is off.
- **`GET me/viewing-requests`** (auth, `type?`, `per_page?`) → `[{id,type,status,preferred_at,scheduled_at,note,created_at,ad:{number,title,price,image,city,area,status,category}}]`, meta `{total,page,last_page,per_page}`.
- **`GET follows?type=shop|marketer&slug=`** (auth) → `{following, followers}`.
- **`GET classifieds/ads/{n}/contract`** (owner) → `{number,status,contract:{version_id,title,version,text,has_file,signed}|null}` — reopen a pending-contract ad.
- Rejected ads stay final (lifecycle §5.1): `PUT ads/{n}` on a rejected ad → 422 with a hint; **`POST classifieds/ads/{n}/duplicate`** (owner; rejected, expired, archived or sold) → 201 ad detail of a new draft with the same fields, specs, property details and media.

### Account (G-W29)
- **`GET me`** → `{id,name,email,email_verified,phone,locale,avatar_url,marketing:{email,sms,push,whatsapp},created_at}`.
- **`PATCH|PUT me`** `{name?, locale?: en|ar, marketing?: {email?,sms?,push?,whatsapp?}}` → same shape.
- **`POST me/avatar`** (multipart `avatar`, jpg/png/webp ≤ 2 MB) / **`DELETE me/avatar`** → same shape.
- **Email change:** `POST me/email {email}` → 202 `{sent,email}` (code mailed to the new address; 422 `email_taken` / `email_unchanged`); `POST me/email/verify {email, code}` → profile with the new email (422 `code_invalid` / `code_expired`, 429 `code_locked`, 422 `email_taken`).
- **Notifications:** `GET me/notifications?unread=&per_page=` → `[{id,type,title,body,link,data,read,read_at,created_at}]`, meta `{total,page,last_page,per_page,unread}`; `GET me/notifications/unread-count` → `{unread}`; `POST me/notifications/{id}/read` → the row + `unread`; `POST me/notifications/read-all {ids?}` → `{marked, unread}`. Types: `order.{confirmed|shipped|out_for_delivery|delivered|cancelled|returned|refunded}`, `travel_booking.{confirmed|rejected|cancelled|completed}`, `return.{status}`, `viewing.{status}` / `reserve.{status}`, `classified_ad.{active|rejected|expired}`, `review.{approved|rejected}`, `question.{approved|rejected}`. `link` is a storefront path without the market (`/account/orders/{n}`, `/account/returns/{n}`, `/travel/bookings/{n}`, `/account/inquiries`, `/classified/find/{n}`, `/products/{slug}`). Moves made by the customer themselves are not notified. Per country.
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
