# Panel navigation (sidebar)

Source of truth: `backend/app/Domains/Panels/Navigation/PanelNavigation.php` (`PanelNavigation::structure()` = the order below,
`PanelNavigation::for()` = what one signed-in user sees). Rendered by `resources/views/components/layouts/panel.blade.php`
→ `components/ui/sidebar-nav.blade.php` / `sidebar-item.blade.php`, behaviour in `resources/js/panel-sidebar.js`,
icons in `components/icon.blade.php` (`<x-icon name="…" />`).

## Model

- A panel sidebar is an ordered list of **entries**. An entry is either a **top-level link** (dashboard, support, a
  single high-traffic page) or a **group** (collapsible dropdown with an icon, a label and 2–7 leaf links).
- Groups are task-oriented: most-used work first (orders, catalog, …), money and reports after, account /
  settings / system last. Support is a top-level link at the end of every partner panel.
- Every entry has an outline icon (`x-icon` name in the tables). Leaf links have no icon (uncluttered; the group
  icon carries the meaning).
- **Permissions**: an item is shown only if the user passes every `can:` (admin permission, Spatie, per country)
  and `tenant.can:` (team-role permission group, read) middleware of its route. A group whose items are all hidden
  is not rendered. No item is ever shown disabled ("Soon" placeholders no longer exist).
- **Active**: the single most specific route matching the current page is active (e.g. on
  `admin.marketing.travel-promotions` only *Travel promotions* is active, not *Ads review*). Its group is rendered
  open and marked active.
- **Counts**: items may carry a badge of work waiting on the user (today: admin *Approvals queue* = listings pending
  review; Seller Center *Orders* = sub-orders in `placed`). A group shows the sum of its items' counts while it is
  collapsed.
- Labels are translated with `__()`; Arabic strings live in `backend/lang/ar.json`.

## Behaviour (all panels)

| Area | Behaviour |
|---|---|
| Group header | `<button aria-expanded aria-controls>` with group icon, label, aggregated count (collapsed only) and a chevron that rotates 180° when open. Enter / Space toggles. The group holding the current page keeps a brand accent bar on its header while collapsed. |
| Open / close | Smooth height transition (grid rows 0fr → 1fr, ~200 ms); no animation with `prefers-reduced-motion`. Closed lists are `visibility: hidden` so their links are not focusable. |
| Accordion | Only the group containing the current page is open on load; every other group is collapsed. Opening a group closes the one that was open (one open group at a time); the open group can also be closed. Open state is **not** remembered between pages. Without JavaScript every group is open. |
| Keyboard | ↑ / ↓ move between visible group headers and links (↓ from the search box enters the list), Home / End jump to first / last, → opens a group (closing the other) or moves into it, ← closes it or returns to its header (mirrored in RTL), `/` focuses the search box. |
| Search | "Search menu" box at the top (panels with ≥ 12 links: admin, Seller Center, Creator Studio). Filters links by label (case- and Arabic-diacritic-insensitive; a group-label match shows the whole group), temporarily opens every matching group (the one-open-group rule is suspended while searching), hides the rest and shows "No pages match" when nothing matches. Enter opens the first match, Esc clears. Clearing the search returns to "only the active group open". |
| Active link | Highlighted background plus a 3 px accent bar on the inline-start edge (left in LTR, right in RTL); `aria-current="page"`. |
| Icon rail (desktop ≥ 1024 px) | Toggle button next to the logo collapses the sidebar to a 72 px icon rail (the only remembered state: `localStorage` `nawy.nav.rail`, try/catch, applied before first paint). Top-level links show a tooltip; groups open a flyout with the group title and its links on hover / focus / click; Esc closes it. Search and labels are hidden. |
| Mobile (< 1024 px) | Unchanged off-canvas drawer from the topbar menu button (Esc closes); always the full sidebar (no rail). |
| RTL | Uses logical properties only (`start`/`end`, `ps`/`pe`), so the drawer, accent bar, flyout and chevrons mirror automatically in Arabic. |
| Theme | Sidebar keeps the dark navy panel sidebar (`bg-sidebar`, `bg-sidebar-active`, `text-fg-sidebar`, brand yellow badges) in light and dark page themes. |

## Structure per panel

Permission column: `can:x` = admin permission; `tenant:x` = team-role permission group (read) of that panel; — = any
member of the panel.

### Admin (`admin`)

| # | Entry | Icon | Item | Route | Permission |
|---|---|---|---|---|---|
| 1 | **Dashboard** (link) | `layout-dashboard` | — | `admin.dashboard` | — |
| 2 | **Approvals queue** (link) | `clipboard-check` | — | `admin.approvals` | `can:vendor_listings.view` |
| 3 | **Orders & fulfilment** (group) | `shopping-bag` | Orders | `admin.orders` | `can:orders.view` |
|  |  |  | Returns | `admin.returns` | `can:returns.view` |
|  |  |  | Refunds | `admin.refunds` | `can:orders.refund` |
|  |  |  | Disputes | `admin.disputes` | `can:disputes.view` |
|  |  |  | Warranty claims | `admin.warranty-claims` | `can:warranty_claims.view` |
| 4 | **Catalog** (group) | `tag` | Catalog | `admin.catalog` | `can:categories.view` |
|  |  |  | Product requests | `admin.product-requests` | `can:product_requests.view` |
| 5 | **Sellers & partners** (group) | `store` | Users & vendors | `admin.users` | `can:vendors.view` |
|  |  |  | Applications | `admin.applications` | `can:applications.view` |
|  |  |  | Profile change requests | `admin.change-requests` | `can:change_requests.view` |
|  |  |  | Contracts | `admin.contracts` | `can:contract_templates.view` |
|  |  |  | Document review | `admin.documents` | `can:documents.view` |
|  |  |  | Join options | `admin.join-options` | `can:join_options.configure` |
| 6 | **Customers & support** (group) | `users` | Customers | `admin.customers` | `can:customers.view` |
|  |  |  | Support tickets | `admin.support` | `can:tickets.view` |
|  |  |  | Reviews & Q&A | `admin.reviews` | `can:reviews.view` |
|  |  |  | Nawy One | `admin.membership` | `can:membership.view` |
| 7 | **Delivery** (group) | `truck` | Delivery & logistics | `admin.delivery` | `can:shipping_zones.view` |
|  |  |  | Dispatch | `admin.delivery.assignments` | `can:delivery_assignments.view` |
|  |  |  | Agent withdrawals | `admin.delivery.withdrawals` | `can:payouts.view` |
| 8 | **Inventory & FBN** (group) | `warehouse` | Inventory & fulfilment | `admin.inventory` | `can:fbn.view` |
|  |  |  | Packaging | `admin.packaging` | `can:packaging.view` |
| 9 | **Marketing & promotions** (group) | `megaphone` | Ads review | `admin.marketing` | `can:ads.review` |
|  |  |  | Coupons | `admin.coupons` | `can:coupons.view` |
|  |  |  | Coupon participation | `admin.marketing.coupon-participation` | `can:coupon_participation.manage` |
|  |  |  | Flash sales | `admin.flash-sales` | `can:flash_sales.view` |
|  |  |  | Gift cards & vouchers | `admin.gift-cards` | `can:gift_cards.view` |
|  |  |  | Advertise requests | `admin.advertise-requests` | `can:advertise_inquiries.view` |
| 10 | **Creators** (group) | `sparkles` | Marketers | `admin.marketers` | `can:marketers.view` |
|  |  |  | Marketer campaigns | `admin.marketers.campaigns` | `can:marketer_campaigns.view` |
|  |  |  | Celebrity monthly report | `admin.marketers.celebrity-reports` | `can:celebrity_reports.view` |
|  |  |  | Marketer settings | `admin.marketers.settings` | `can:marketer_settings.configure` |
| 11 | **Open Souq & classifieds** (group) | `layout-grid` | Classifieds review | `admin.classifieds` | `can:classifieds.review` |
|  |  |  | Abuse reports | `admin.classifieds.reports` | `can:classifieds.review` |
|  |  |  | Classified categories | `admin.classifieds.categories` | `can:categories.view` |
|  |  |  | Real estate | `admin.real-estate` | `can:marketers.view` |
| 12 | **Travel** (group) | `plane` | Travel packages | `admin.travel` | `can:travel_packages.review` |
|  |  |  | Travel promotions | `admin.marketing.travel-promotions` | `can:travel_promotions.view` |
| 13 | **Content & pages** (group) | `file-text` | Pages & page builder | `admin.pages` | `can:pages.view` |
|  |  |  | Blog & help centre | `admin.cms` | `can:blog.manage` |
|  |  |  | Live & videos | `admin.media` | `can:live_media.view` |
|  |  |  | App contexts | `admin.app-contexts` | `can:app_contexts.manage` |
|  |  |  | Notification templates | `admin.notification-templates` | `can:notification_templates.manage` |
| 14 | **Finance** (group) | `banknote` | Finance | `admin.finance` | `can:payouts.view` |
|  |  |  | Ledger | `admin.finance.ledger` | `can:ledger.view` |
|  |  |  | Payments under review | `admin.finance.payments-review` | `can:payments.review` |
|  |  |  | Vendor subscriptions | `admin.finance.subscriptions` | `can:vendor_subscriptions.view` |
|  |  |  | Acquisition commissions | `admin.finance.acquisition-commissions` | `can:acquisition_commissions.view` |
|  |  |  | My acquisition commissions | `admin.finance.my-acquisition-commissions` | — |
| 15 | **Reports & analytics** (group) | `bar-chart` | Cross-country reports | `admin.reports.cross-country` | `can:reports.view` |
|  |  |  | Portal analytics | `admin.portal-analytics` | `can:applications.view` |
| 16 | **Countries & settings** (group) | `globe` | Countries & gateways | `admin.countries` | `can:payment_gateways.view` |
|  |  |  | Exchange rates | `admin.countries.exchange-rates` | `can:exchange_rates.view` |
|  |  |  | Domains | `admin.countries.domains` | `can:domains.view` |
|  |  |  | Platform settings | `admin.settings` | — |
| 17 | **Staff & security** (group) | `shield` | Staff | `admin.staff` | `can:admins.view` |
|  |  |  | Roles & permissions | `admin.roles` | `can:roles.view` |
|  |  |  | Activity log | `admin.activity` | `can:activity_log.view` |
| 18 | **System & data migration** (group) | `server` | System health | `admin.health` | `can:system_health.view` |
|  |  |  | Email log | `admin.email-log` | `can:system_health.view` |
|  |  |  | Migration runs | `admin.migration.runs` | `can:data_migration.view` |
|  |  |  | Quarantine queue | `admin.migration.quarantine` | `can:data_migration.view` |
|  |  |  | Legacy records | `admin.migration.records` | `can:data_migration.view` |
|  |  |  | Legacy redirects | `admin.migration.redirects` | `can:data_migration.view` |
|  |  |  | Reconciliation | `admin.migration.reconciliation` | `can:data_migration.view` |

### Seller Center — store layout (`partner`, product vendors)

| # | Entry | Icon | Item | Route | Permission |
|---|---|---|---|---|---|
| 1 | **Dashboard** (link) | `layout-dashboard` | — | `partner.dashboard` | — |
| 2 | **Orders** (group) | `shopping-bag` | Orders | `partner.orders` | `tenant:orders` |
|  |  |  | Returns | `partner.returns` | `tenant:returns` |
|  |  |  | Disputes | `partner.disputes` | `tenant:returns` |
| 3 | **Catalog** (group) | `tag` | Products | `partner.products` | `tenant:listings` |
|  |  |  | Product requests | `partner.product-requests` | `tenant:listings` |
|  |  |  | Reviews | `partner.reviews` | `tenant:reviews` |
| 4 | **Inventory & FBN** (group) | `warehouse` | Inventory & warehouses | `partner.inventory` | `tenant:inventory` |
|  |  |  | Packaging supplies | `partner.packaging` | `tenant:inventory` |
| 5 | **Marketing** (group) | `megaphone` | Coupons | `partner.coupons` | `tenant:marketing` |
|  |  |  | Flash sales | `partner.flash-sales` | `tenant:marketing` |
|  |  |  | Marketer campaigns | `partner.campaigns` | `tenant:campaigns` |
|  |  |  | Advertising | `partner.advertising` | `tenant:marketing` |
| 6 | **Finance** (group) | `banknote` | Earnings | `partner.earnings` | `tenant:finance` |
|  |  |  | Statements & invoices | `partner.statements` | `tenant:finance` |
|  |  |  | Payouts | `partner.payouts` | `tenant:finance` |
|  |  |  | Subscription | `partner.subscription` | `tenant:finance` |
| 7 | **Store & team** (group) | `store` | Store settings | `partner.store` | `tenant:profile` |
|  |  |  | Team & permissions | `partner.team` | `tenant:team` |
|  |  |  | Change requests | `partner.change-requests` | `tenant:profile` |
|  |  |  | Contracts | `partner.contracts` | `tenant:legal` |
|  |  |  | Documents | `partner.documents` | `tenant:documents` |
| 8 | **Support** (link) | `life-buoy` | — | `partner.support` | `tenant:support` |

### Seller Center — classified shop layout (`partner`, vendors of type `classified`)

| # | Entry | Icon | Item | Route | Permission |
|---|---|---|---|---|---|
| 1 | **Dashboard** (link) | `layout-dashboard` | — | `partner.dashboard` | — |
| 2 | **Listings** (group) | `layers` | My listings | `partner.marketplace.ads` | `tenant:classifieds` |
|  |  |  | Post a listing | `partner.marketplace.ads.create` | `tenant:classifieds` |
|  |  |  | Ads & boosts | `partner.advertising` | `tenant:marketing` |
| 3 | **Buyers** (group) | `message-square` | Inquiries | `partner.marketplace.inquiries` | `tenant:classifieds` |
|  |  |  | Viewing & reserve requests | `partner.marketplace.requests` | `tenant:classifieds` |
| 4 | **Shop & account** (group) | `store` | Profile | `partner.store` | `tenant:profile` |
|  |  |  | Change requests | `partner.change-requests` | `tenant:profile` |
|  |  |  | Contracts | `partner.contracts` | `tenant:legal` |
|  |  |  | Documents | `partner.documents` | `tenant:documents` |
| 5 | **Support** (link) | `life-buoy` | — | `partner.support` | `tenant:support` |

### Creator Studio (`marketer`; the Broker group only for marketers with the `broker` job type)

| # | Entry | Icon | Item | Route | Permission |
|---|---|---|---|---|---|
| 1 | **Overview** (link) | `layout-dashboard` | — | `marketer.dashboard` | — |
| 2 | **Campaigns** (group) | `megaphone` | Campaign marketplace | `marketer.marketplace` | `tenant:campaigns` |
|  |  |  | Invitations | `marketer.invitations` | `tenant:campaigns` |
|  |  |  | Active campaigns | `marketer.campaigns` | `tenant:campaigns` |
|  |  |  | Links & coupons | `marketer.links` | `tenant:campaigns` |
| 3 | **Promotion tools** (group) | `sparkles` | Cross-promotion | `marketer.cross-promotion` | `tenant:campaigns` |
|  |  |  | Flash sales | `marketer.flash-sales` | `tenant:campaigns` |
|  |  |  | Advertising | `marketer.advertising` | `tenant:campaigns` |
| 4 | **My products** (group) | `package` | My products | `marketer.products` | `tenant:studio` |
|  |  |  | Product requests | `marketer.product-requests` | `tenant:studio` |
| 5 | **Broker** (group, brokers only) | `briefcase` | Leads pipeline | `marketer.broker.leads` | `tenant:broker` |
|  |  |  | Viewing & reserve requests | `marketer.broker.requests` | `tenant:broker` |
|  |  |  | Special requests | `marketer.broker.special-requests` | `tenant:broker` |
|  |  |  | Properties | `marketer.broker.properties` | `tenant:broker` |
| 6 | **Earnings** (group) | `wallet` | Wallet & withdrawals | `marketer.wallet` | `tenant:wallet` |
|  |  |  | Monthly reports | `marketer.reports` | `tenant:wallet` |
| 7 | **Profile & contract** (group) | `user-circle` | Profile & socials | `marketer.profile` | `tenant:profile` |
|  |  |  | Marketer contract | `marketer.contract` | `tenant:contract` |
| 8 | **Support** (link) | `life-buoy` | — | `marketer.support` | `tenant:support` |

### Travel Partner (`travel_agency`)

| # | Entry | Icon | Item | Route | Permission |
|---|---|---|---|---|---|
| 1 | **Dashboard** (link) | `layout-dashboard` | — | `travel_agency.dashboard` | — |
| 2 | **Trips** (group) | `plane` | Packages & trips | `travel_agency.packages` | `tenant:packages` |
|  |  |  | Departures & capacity | `travel_agency.departures` | `tenant:packages` |
| 3 | **Bookings** (group) | `ticket` | Bookings | `travel_agency.bookings` | `tenant:bookings` |
|  |  |  | Inquiries | `travel_agency.inquiries` | `tenant:inquiries` |
|  |  |  | Booking reports | `travel_agency.reports` | `tenant:reports` |
| 4 | **Promotions** (group) | `megaphone` | Promotions | `travel_agency.promotions` | `tenant:promotions` |
|  |  |  | Promote a trip | `travel_agency.promotions.create` | `tenant:promotions` |
| 5 | **Agency & team** (group) | `building` | Team & permissions | `travel_agency.team` | `tenant:team` |
|  |  |  | Change requests | `travel_agency.change-requests` | `tenant:profile` |
| 6 | **Support** (link) | `life-buoy` | — | `travel_agency.support` | `tenant:support` |

### Shipping (carrier / supervisor) (`carrier`)

| # | Entry | Icon | Item | Route | Permission |
|---|---|---|---|---|---|
| 1 | **Overview** (link) | `layout-dashboard` | — | `carrier.dashboard` | — |
| 2 | **Live dispatch** (link) | `route` | — | `carrier.dispatch` | `tenant:dispatch` |
| 3 | **Shipments** (group) | `truck` | Pickup requests | `carrier.pickups` | `tenant:dispatch` |
|  |  |  | Shipments | `carrier.shipments` | `tenant:dispatch` |
|  |  |  | Assignments | `carrier.assignments` | `tenant:dispatch` |
|  |  |  | Failed deliveries | `carrier.exceptions` | `tenant:dispatch` |
| 4 | **Drivers** (group) | `users` | Drivers & supervisors | `carrier.agents` | `tenant:agents` |
|  |  |  | Driver documents | `carrier.driver-documents` | `tenant:agents` |
| 5 | **Money & claims** (group) | `banknote` | COD & reconciliation | `carrier.cod` | `tenant:cod` |
|  |  |  | Agent withdrawals | `carrier.withdrawals` | `tenant:payouts` |
|  |  |  | Claims & scorecard | `carrier.claims` | `tenant:claims` |
| 6 | **Company settings** (link) | `building` | — | `carrier.company` | `tenant:company` |
| 7 | **Support** (link) | `life-buoy` | — | `carrier.support` | `tenant:support` |

### Delivery (driver) (`delivery`)

| # | Entry | Icon | Item | Route | Permission |
|---|---|---|---|---|---|
| 1 | **My day** (link) | `sun` | — | `delivery.dashboard` | — |
| 2 | **Assignments** (link) | `clipboard-list` | — | `delivery.assignments` | — |
| 3 | **Money** (group) | `wallet` | COD cash & settlements | `delivery.cod` | — |
|  |  |  | Earnings | `delivery.earnings` | — |
| 4 | **Profile & documents** (group) | `user-circle` | Profile & vehicle | `delivery.profile` | — |
|  |  |  | Documents | `delivery.documents` | — |


## Icons

Outline, 24 × 24 viewBox, `stroke="currentColor"`, 1.75 px stroke, round caps/joins, drawn at 20 px (groups and
top-level links) and 16 px (UI controls). Paths follow Lucide (ISC licence, notice in
`backend/resources/views/components/icon.blade.php`). Names in use: `layout-dashboard`, `clipboard-check`,
`clipboard-list`, `shopping-bag`, `tag`, `store`, `users`, `user-circle`, `truck`, `route`, `warehouse`, `package`,
`megaphone`, `sparkles`, `layout-grid`, `layers`, `message-square`, `plane`, `ticket`, `file-text`, `banknote`,
`wallet`, `bar-chart`, `globe`, `shield`, `server`, `briefcase`, `building`, `life-buoy`, `sun`; UI: `chevron-down`,
`search`, `x`, `panel-left`, `chevrons-down-up`, `chevrons-up-down`.

## Previous layout → new (for testers)

The set of links each user sees is unchanged (same routes, same permission checks); only order and grouping
changed. Old headings (*Overview, Commerce, Growth, Verticals, Operations, Content, Platform, Data migration* in
admin; *Main, Growth, Finance, Account* in partner panels …) no longer exist. Links in collapsed groups are in the
DOM but not visible: browser specs click the group header (`[data-nav-toggle]`, accessible name = group label) or
type in the search box (`[data-nav-search]`, opens every matching group) before clicking a link.
