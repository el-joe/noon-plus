# Boksha audit (boksha.com/ar) — 2026-11-14, Firecrawl
Page inspected: Abaya size guide (`/ar/pages/abaya-size-guide`). The catalogue is a curated fashion and lifestyle marketplace of independent designers and shops. **Layout rule: Nawy keeps its own layout.**

## Findings
- **Made to order.** Most abayas are tailored, unless a product is marked "free size" or "ready to ship". The customer writes measurements or notes for the designer in a **comment box on the product page**.
- **Abaya size guide (by height):**
  - Columns: person's height (cm and ft), suitable abaya length (inches), sleeve length from the neck (inches).
  - Example: 147 cm / 4.8 ft → 50" length, 26" sleeve.
  - Tip: add 1–2" when wearing heels.
  - These are garment measurements, not body measurements.
- **Dress, kaftan and travel-wear size table:**
  - Columns: size, UK, US, EU, bust, waist, hips.
  - Example: XS · UK 4-6 · US 0-2 · EU 32-34 · bust 31-32 · waist 24-25 · hips 33-34.
- The page has a measuring diagram image.

## Nawy decisions
- **Size guide engine** (extends the SRS size guides):
  - Each size-guide **template type** (`apparel_alpha`, `abaya_height_length`, `shoes`, `rings`, custom) has its own column schema and units (cm, in, ft; the customer can switch).
  - Templates are scoped to a category or a seller.
- **Product fulfilment option `made_to_order`:**
  - lead time (days)
  - **measurement form** per category (height, length, sleeve, bust…, required or optional) and a free-text note
  - measurements are stored on the order item and shown to the seller on the sub-order
  - returns policy override (custom-made items are not returnable unless defective)
- **PDP additions:**
  - a "Size guide" drawer
  - "Find my size" (height → recommended abaya length)
  - "Ready to ship / Made to order · ships in N days" badge
- **Category:** a modest-fashion vertical (abayas, jalabiyas, kaftans) with designer shops (`seller_store` template).
