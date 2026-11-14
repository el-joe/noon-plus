# Noon UAE customer app audit (structure only), 2026-11-14

**Purpose:** a layout and behaviour inventory for building "Nawy". Do not copy any noon logos, artwork, colours-as-brand, copy or assets.

**Sources and confidence**
- **[S]**: current Google Play listing screenshots for `com.noon.buyerapp`. These are marketing composites of the native app and were viewed zoomed.
- **[W]**: noon.com/uae-en mobile web at a 375×812 viewport, inspected live (home, search overlay, search results, filter sheet, product page, empty cart). Mobile web is a close sibling of the app, but it is not the app.
- **[D]**: App Store and Play descriptions (iOS v5.26111, Nov 2026, "Liquid Glass" redesign).
- See also `noon-homepage-audit.md` for the desktop home section order.

## 1. App shell

**Super-app switcher [S]** (top of every vertical)
- A horizontal row of rounded-square app tiles, about 64px each, that scrolls sideways.
- Tiles: noon (main), supermall, noon Food, Minutes (15-min), NowNow, home services, noon Send, Pay, OUT (dining).
- The active tile is enlarged or highlighted. The whole header area re-themes per vertical: yellow for main, blue for supermall, pink for Food, red for Minutes, orange for NowNow, purple for Send/OUT.

**Header under the switcher [S]**
- Address line: a home icon, the address label ("Home ⌄") and the street on a 2nd line ("Sheikh Zayed Road, Dubai"). Tapping it opens the address picker.
- In quick verticals the label becomes an ETA instead: "⚡ 9 minutes delivery" or "1 Hrs 30 Mins Delivery".
- Full-width pill search box with a rotating placeholder: Search "Maybelline" / "coffee" / "power bank".
- supermall adds a camera (visual search) icon. Minutes adds a side "Magic List" button.

**Header on mobile web [W]**
- An app-install banner, then hamburger + logo + location ("Dubai ⌄").
- Below that, the search pill plus a wishlist heart button.

**Bottom tab bar per vertical [S]** (icon above label, active tab tinted)

| Vertical | Tabs (in order) |
|---|---|
| noon (main) | Home · Categories · Deals · Account · Cart (with count badge) |
| supermall | Home · Brands · Account · Cart |
| noon Food | Home · Offers · Signature · Favorites · Profile |
| Minutes | Home · Categories · (centre round promo/brand slot) · My Items · Account |
| NowNow | Home · Stores · Search · Account (labels small; partly inferred) |
| home services | Home · (services) · Bookings · … · Cart (unclear) |
| Send | Home · Orders · Account |
| OUT | Home · Pays · Favorites · Account |

The mobile-web bar is Home · Categories · [sponsored brand logo slot] · Account · Cart [W].

## 2. Home screen (main vertical), in order

1. **Hero carousel [S][W]**
   - Large, rounded, about 90% width with the next card peeking. Swipes horizontally.
   - Dot pager. Banners can be portrait (web) or landscape (app).
   - Sale campaign art ("Yellow Friday – coming soon").
2. **Sponsored strip banner [W]**: a slim full-width promo with a brand price, labelled "Ad".
3. **Category circles/tiles [S][W]**
   - App: 2 rows of circular image tiles with 2-line labels (Shop now, Home & Kitchen, Women's fashion, Appliances, Health & Nutrition, Gift Cards, Men's fashion, Mobiles, …). The grid scrolls horizontally.
   - Web: 4-column rounded-square tiles (Deals, Mobiles, Beauty, Women's Fashion, Electronics, Laptops & Desktops, Gift Cards, Men's Fashion).
4. **Full-width ad banner** (labelled "Ad") [W].
5. **"Recommended for you" / "Best picks for you"** [S][W]: a horizontal product carousel with about 2.3 cards visible.
6. **"Offers for you" [W]**: offer/coupon cards in a horizontal carousel ("Buy 5, Get 10% Cashback", "Get 15% Cashback", code chip).
7. **"Bestsellers for you" [W]**: a product carousel with a "Best Seller" corner tag.
8. **2-up / 3-up promo tiles [W]**: square banners ("Clean spaces…", "Fuel your performance – up to 70% off").
9. **Sections confirmed in the desktop/mobile-web DOM** (order as on desktop):
   - "More reasons to shop": a 2×2 tile grid.
   - "Mega deals": a countdown timer plus a 2×2 mini product grid.
   - "In focus": stacked banners.
   - "Maximize your savings": offer cards.
   - Campaign carousels ("Free X worth Y", "Buy 2 Get 10% off | code").
   - "Previously browsed products".
   - About 16 category showcases (title + View all + 7 tiles).
   - Popular Searches: a chip cloud.
10. **Footer** (web only): help + link columns. The app does not have one.

## 3. Key screens

**Categories tab** (app; layout not captured, so inferred from web + standard pattern)
- Left rail of top categories, right pane of subcategory image grids grouped by heading, plus a "Top Brands" logo grid. The web mega-menu shows the hierarchy: L1 → L2 groups → L3 links + Top Brands ×10.

**Search overlay [W]**
- Header: back arrow + input with a clear ×.
- Empty state:
  - "Recently searched" row of thumbnail cards (image + term) with an "Edit" action.
  - "Trending searches": a wrap of pill chips, each with a ↗ icon.
- Typing: an autosuggest list. The typed prefix is bold, some rows have a product thumbnail, and there are about 10 rows.

**Search results / PLP [W]**
1. A sponsored brand block at the top ("Ad"): brand logo, headline, "Shop BRAND >", and a mini product carousel with an express badge.
2. "1K+ Results for "air fryer"" with a grid/list toggle icon on the right.
3. A sticky horizontal chip bar: Filter · Sort · | · Deals ⌄ · Brand ⌄ · Capacity ⌄ … then quick-filter chips (brand names, subcategories).
4. A 2-column grid.
5. **Filter sheet**: a full-height bottom sheet.
   - Title "Filters", then "Search across filters".
   - Left rail of facets, each with a dot when active: Sort By, Delivery, Arrives by, Category, Brand, Price, Product Rating, Top Picks, then attribute facets (Capacity, Wattage, Colour, Material…), then Seller.
   - Right pane of collapsible groups:
     - Sort: Recommended / Price Low→High / High→Low / New Arrivals / Best Rated.
     - Delivery: badge options (Direct from brand, Fulfilled by noon, Sourced Globally).
     - Arrives by: Today.
     - Price: Min/Max inputs.
     - Rating: 4.5+/4.0+/3.5+/3.0+.
     - Top Picks: Mega Deal, Deal, sale-event Deal, New Arrivals.
     - Lowest Price Ever in 7 days / 30 days / 1 year.
     - Lists end with "View All".
   - Footer: "Clear All" + primary "Show 3K+ results" (live count).
6. The Sort chip opens the same sheet anchored on Sort.

**PDP [W]** (top to bottom)
1. Floating back, wishlist and share buttons over a full-bleed swipeable image gallery.
2. Brand link "Visit Store", the title, and rating "4.5 (20 Ratings)".
3. Price block: price, struck-out was-price, "41% OFF", "(incl. of VAT)".
4. Badges: Deal, "#45 in Air Fryers" (bestseller rank link).
5. **Coupons**: a horizontal list of coupon chips ("Extra 10% off, CODE: …").
6. **Delivery Information**: "Get it by 16 Nov" + a cutoff countdown, "Order in 20h 28m".
7. A sponsored alternative product row ("Ad").
8. Payment discount, BNPL ("Split in up to 6 payments") and a co-brand card cashback line.
9. **Add-on protection**: selectable plan cards (Extended Warranty 1Y/2Y, Accidental Damage, combo), each with bullets, a price and SELECT.
10. Trust chips: Delivery by noon · High Rated Seller · Low Returns · Cash on Delivery.
11. **Product Overview** with tabs AI Summary / All details. "Summarised by noon AI" bullets.
12. Additional info: warranty, free delivery to pickup points, easy returns, "Explore other bestsellers".
13. **Sold by** card: seller name, rating and count, "% positive", "Low return seller", "Item as shown 80%", "Partner since 5+ years".
14. A brand promo carousel.
15. Product Features, with spec groups.
16. **Frequently Bought Together**: checkbox list with "Buy all for X".
17. **Ratings & Reviews**: average, 5→1 histogram %, "Top Reviews", "Verified Purchase" tag, date, "Translate to English", "Helpful".
18. **Sticky bottom bar**: Qty selector (1–10) + "Add to cart". After adding it shows an "Added to cart" state.

Variant pickers (colour/size/storage chips) are expected on multi-variant items. They were not on the sampled SKU.

**Cart [W] (guest/empty)**
- A free-shipping promo banner ("Free shipping on your 1st order on items from Supermall & Express").
- Empty-state illustration: "Your shopping cart is empty / What are you waiting for?" + a "Start Shopping" button.
- Then "Items you previously viewed" and "Bestsellers for you" carousels.
- The filled cart (shipments grouped by delivery type, coupon field, free-delivery progress bar, sticky checkout summary) was not seen.

**Minutes (quick commerce) [S]**
- ETA header, then search + Magic List.
- Tall promo/brand cards in a horizontal row.
- A segmented pill toggle: "Order Again | Favorites".
- A 3-column product grid. Each card has an image, a "+" add button on the image, a name, a size ("500g ▾" variant dropdown) and a price.
- A **floating "View Cart · 5 items" pill** with product thumbnails above the tab bar.

**noon Food [S]**
- "Search cuisines", then a hero carousel of restaurant promos.
- Shortcut tiles: All Offers / 15 Mins Delivery / Latest Additions / Signature.
- Chip toggle: "Recommended | FREE delivery".
- Restaurant cards: a large image with an offer overlay ("30% OFF", "FREE delivery") and a heart button.

**supermall [S]**: an "All Brands at your doorstep" brand bubble cluster, a 3-column category image tiles grid (Toys, Fragrance, Beauty, Men/Women Fashion, Gaming), and "Bestsellers near you".

**NowNow [S]**: a store hero, "Your Neighbourhood Favorites" round store-logo row, a full-width "EXTRA 30% OFF" strip and store-offer cards.

**Home services [S]**: a promo hero (cleaning % off + code) and a 4-column service icon grid with labels and small price tags.

**Send [S]**: hero art, then a pickup/drop card ("Pickup from …" / "Where is your drop?") with a swap button, then "About our services" cards.

**Account / Orders / Returns / Wallet / Membership** (not inspectable without login)
- Confirmed facts:
  - Account → Orders → select order → "Get help" (live chat), per noon's own App Store replies.
  - The credits/wallet URL exists (`/credits/`) and is login-gated.
  - noon One costs 25 AED/mo: free delivery, instant Food/NowNow delivery, exclusive deals [D].
  - noon credits are earned via the co-brand card [W].
  - Payments: card, COD, Tabby/Tamara 4–12 instalments, No-Cost EMI; 15-day returns [D].
- Expected account menu (unverified): Orders, Returns, Wishlist, Addresses, Payments, noon Credits, noon One, Language (EN/AR), Country, Notifications, Help, Sign out.

**Deals tab** [S, label only]: contents not captured.

## 4. Recurring components

- **Product card (grid/carousel)**
  - Image with a multi-image dot pager, a top-right heart, a bottom-right "+" quick-add, and a top-left tag ("Best Seller", "Mega Deal 📣", "Deal").
  - 2-line title, then a star + rating + (count) pill.
  - Price + struck price + green "% OFF".
  - Unit price ("60ml | 1.13/ml").
  - Merchandising lines: "#28 in Air Fryers", "Lowest price in 30 days", "Only 9 left in stock", "Selling out fast".
  - Coupon line ("Extra 10% off + 1").
  - Delivery badges: express pill, "express Tomorrow", supermall, market, Global, "GET IN 1 HR 8 MINS".
- **Mini grocery card**: image, "+" stepper, name, size dropdown, price.
- **Restaurant card**: image, offer overlay, heart.
- **Sponsored unit**: an "Ad" label on banners, brand blocks, PDP alt-product rows and the bottom-bar slot.
- **Chips**: filter chips with ⌄, quick-filter chips, trending chips with an icon, segmented toggles, coupon chips with codes.
- **Sheets**: a full-height filter sheet with a facet rail, the sort sheet, the address picker (inferred) and the quantity dropdown.
- **Timers**: the Mega deals countdown and the PDP "Order in 20h 28m" delivery cutoff.
- **Floating cart pill** (quick verticals), plus a cart badge count on the tab.
- **Toast**: the bottom error toast "Something went wrong." with a red icon. These stack.
- **Empty state**: illustration + title + subtitle + primary button, followed by recommendations.
- **Rating**: stars, histogram bars, "Verified Purchase", Helpful, Translate.
- **Trust/feature chips** (seller quality, COD) and protection-plan select cards.
- **App-install banner and cookie consent bar** (web only).

## 5. Not verified

- Native screens beyond the store screenshots: categories-tab layout, Deals tab, account menu, orders/tracking timeline, returns flow, wishlist, notifications inbox, wallet/credits, noon One page (blank without app/login), filled cart and checkout (address, slots, payment method list, place order). All of these need a logged-in device session.
- Skeleton loaders were not observed. The home and section order in the app is personalised and changes daily.
- The exact NowNow, home-services and Minutes centre tab labels are partly illegible in the screenshots.
- Store screenshots are marketing composites. Exact sizes are approximate (based on a 375pt-wide device).
