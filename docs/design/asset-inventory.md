# Asset inventory (Canva → Figma → code)
Policy: **minimal image generation** (user instruction, 2026-11-14). The 8 original images plus the 10 placeholder-fill images below (18 total) are the full set — reuse them; don't generate more without approval.

| Asset | Canva media ID | Original size | Local preview (200px) | Figma | Used in |
|---|---|---|---|---|---|
| hero-electronics | MAHa4-K-T8I | 2880×1440 | docs/design/canva-previews/hero-electronics.png | — (hero uses typographic art) | Home hero (code) |
| hero-beauty | MAHa47FQKJU | 2880×1440 | …/hero-beauty.png | — | Home hero slide 2 (code) |
| product-earbuds | MAHa46dMBec | 2048² | …/product-earbuds.png | hash e569659f… | cards, PDP, cart |
| product-smartwatch | MAHa4x4p5Vg | 2048² | …/product-smartwatch.png | hash 808d73b5… | cards, FBT, cart |
| product-perfume | MAHa4wbzIJE | 2048² | …/product-perfume.png | hash 3cba59aa… | cards |
| product-sneakers | MAHa43Vhq00 | 2048² | …/product-sneakers.png | hash fa84a537… | cards |
| product-espresso | MAHa4_fMlkw | 2048² | …/product-espresso.png | hash 0327962f… | cards, cart |
| product-smartphone | MAHa4yu1AIQ | 2048² | …/product-smartphone.png | hash 815126de… | cards, FBT |

**Open item:** the Canva connector only exposes 200px signed thumbnails. Full-resolution originals must be downloaded once from Canva → Uploads (or exported) and saved to `website/public/assets/{homepage,products}/` as WebP before the Next.js build. Not done yet.

## Placeholder-fill set (Canva `generate-image`, 2026-11-14)
Generated to replace the grey `img/...` placeholders across the Figma file. The Canva connector only returned 200px previews; these previews were uploaded to Figma as-is (fine for placeholders, soft/pixelated on large heroes). Download the full-resolution originals from Canva → Uploads before they're used in code. The scratch copies are in the session scratchpad, not in the repo.

| Asset | Canva media ID | Original size | Figma hash | Used in |
|---|---|---|---|---|
| product-abaya | MAHa6NPhW-0 | 2048² | 2229f66c3991a23b48cf7a3137f5deb6cdb2aab7 | Modest category/PDP/thumbs, fashion tiles, app product cards |
| product-grocery-basket | MAHa6ByfmlI | 2048² | 066071fb576b35fc5d6785b7da369edc1e86fef8 | Minutes/grocery cards, grocery category circles, App2 Mini grocery card |
| classifieds-suv | MAHa6HzwijE | 4:3 | 16e0d9356fbc6a142a29a322c27e3dd8266cb1c2 | Classified ad card master (53:100/53:135), ad cards/gallery/thumbs, Souq screens |
| realestate-apartment | MAHa6Gq1biU | 4:3 | 1778208da2a774f473d6e29be204c3d2d4ea64e3 | Property cards/gallery, property ads, Property app screen |
| travel-landscape | MAHa6Py7ZJs | 16:9 | 6a603f06c8e292edd6d69c1d58e59b39a5c1d025 | Holiday trip cards, destination hero/cities/packages, ops travel packages |
| travel-mosque-sunset | MAHa6FMwQ90 | 16:9 | 8154e9771d8dda70c954c4b3e82684a1e9e68121 | Umrah trip cards, trip detail gallery, booking thumb, app Travel screen |
| promo-banner-wide | MAHa6Ng_pFc | 2:1 | efb9df075940a61360e74155401f557e84bf7788 | Hero carousels, banners, promo tiles, portal heroes, ad formats, blog covers |
| food-dish | MAHa6HBqtZo | 2048² | 171041f9e67e16d1ecf19e8aa4811248e1373322 | Food context hero, restaurant covers and tiles (App2 04 Food) |
| product-air-fryer | MAHa6GYZJ_Y | 2048² | e1b70fd9b13eeba3d59cd8b604bb886bca773425 | Home/kitchen products and tiles, Deal mini card master |
| lifestyle-flatlay | MAHa6AYGWro | 4:5 | 828e43987492fe6eb0c91cd09744c1ae634af416 | Creator card cover master, Shoppable video tile background (30% dark overlay), Nawy TV, blog/article covers, accessories tiles |

New Lucide icon components (Core components → Icons frame 3:2): RotateCcw 134:11850, Shield 134:11853, Link 134:11857, Car 134:11863, Smartphone 134:11867, Briefcase 134:11871, Sofa 134:11878, PawPrint 134:11884.
