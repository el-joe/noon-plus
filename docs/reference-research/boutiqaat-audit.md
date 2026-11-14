# Boutiqaat audit (boutiqaat.com/ar-sa/women) — 2026-11-14, built-in browser
Firecrawl was blocked by the site, so the page text was read in the built-in browser. **Layout rule: Nawy keeps its own layout. This is a feature reference for the influencer, affiliate and broker pages.**

## Findings
- **Top navigation:** Women · Men · **TV** · **Celebrities (المشاهير)** · Brands · Gift card. Category nav: makeup, K-beauty ("Korea Town"), skincare, niche perfumes, Arabic perfumes, international perfumes, hair, bath & body, eyewear.
- **Celebrities mega-menu.** Each celebrity has a branded **"boutique"** (e.g. "<name> بوتيك"), with 100+ listed and a View all link.
- **"Boutique picks" (مختارات بوتيكات).** Curated product carousels from celebrity boutiques.
- **Product card:** brand, name, was/now price, % off, **"Exclusive" (حصري)** badge, a numeric code shown on exclusive items (celebrity/boutique code), colour count ("3 colours").
- **TV.** Video content: celebrity videos and tutorials that link to products.

## Nawy decisions (applied to marketer types)
- **Creator directory** (`/creators`):
  - filters: type (influencer, affiliate, broker), category, country, language, follower range, verified
  - sort: popular, new, top sellers
  - cards: avatar, name, followers, categories, "Visit boutique"
- **Creator boutique** (`marketer_store` template, `/creator/{slug}`):
  - `brand_header` (cover, avatar, verified, followers, socials, Follow, the creator's **referral code** with copy)
  - tabs: Picks · Own products · Videos · Reviews
  - curated picks (affiliate listings, attributed directly)
  - own products (creator-owned FBN, §5.5)
  - **Exclusive** badge for creator-only listings and codes
  - creator coupon
  - live and recorded streams
- **Nawy TV** (`/tv`): short videos and replays of streams with shoppable products. Uses the `video_block` and `live_streams_strip` blocks plus a feed template.
- **Broker profile** (`/broker/{slug}`): `brand_header` (agency, licence/RERA-style number, verified, rating, response time) + tabs Properties · Sold/Rented · Reviews + a lead form (contract gate) + special requests CTA.
- **Affiliate page:** the same boutique template with affiliate picks only (no own products), and the "Shop with code" banner.
