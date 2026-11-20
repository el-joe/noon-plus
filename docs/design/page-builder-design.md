# Admin page builder — design handoff

Replaces the raw-JSON settings editor at `admin.noon-plus.loc/pages/{id}` (`PageBuilderController` + `resources/views/panels/admin/content/page.blade.php`) with a three-pane visual editor: page outline, live storefront preview and a typed settings form per block, plus a pages list, an *Add section* gallery, revisions and publish / schedule.

- Figma file `d2MhBJmRddMrMSHfjJY8Y8`, page **Admin · Page builder** (`243:2`).
- Block reference images: `backend/public/images/page-blocks/{block_type}.png` (57 files). The mapping is in `docs/design/page-builder-thumbnails.md`.
- Block catalogue, settings and rules: `docs/design/page-builder-blocks.md`, `docs/project/page-builder-blocks_v1.md`, `BlockRegistry`, `PageTemplate` (zones).
- Ledger: `docs/design/figma-state.json` → `pageBuilder`.

## 1. Screens (node ids)

| Screen | Node | What it shows |
|---|---|---|
| Pages list | `253:5141` | Admin shell (Nav/Sidebar expanded, Content › Pages active), search, filters (template, country, status, locale), status tabs with counts, table (page + slug, template, country, locales, status chip, sections, last edited by/at in Oman time), hover actions (Edit, preview, duplicate), row menu (Edit in page builder, Preview draft, Duplicate, Copy to other countries…, Revision history, Archive) |
| Editor — section selected | `247:208` | Main layout: top bar, outline, live preview with selection + hover outlines and quick actions, settings panel with reference thumbnail and grouped fields, unsaved-changes bar |
| Add section gallery | `249:1209` (modal `249:1923`) | Categorised gallery with search, 12 categories with counts, cards with reference thumbnail + name + description + data tags, hover "Add", disabled cards with reason ("Header zone only") |
| Validation errors | `250:3069` | Publish disabled + "2 issues" chip, blocking alert above the preview with "Go to first issue", invalid row in outline, error summary + field errors in the panel |
| Publish confirmation | `250:4183` (modal `250:4897`) | Publish now / Schedule, date-time range (country time + Oman label), pre-publish checks, change count vs live, Compare |
| Revision history | `251:4481` | Compare banner (Draft / v14 / Side by side), diff outlines + tags on changed/added sections, revision list with Restore / Compare, field-level diff |
| Empty page | `251:5385` | Empty zones with "Add section to …", empty state with starter layouts, page settings panel (title EN/AR, slug, template, countries, page schedule) |
| Save / publish status | `251:6345` | Top-bar status for Unsaved, Saving, Saved, Save failed, Scheduled, Published, Edited after publish |
| Settings panel — Arabic (RTL) | `254:5502` | Mirrored panel in Cairo: header, groups, bilingual field (AR on the right), toggle, link picker, select, number with unit, date field |
| Block thumbnails page | `241:2` | Six composed thumbnails for the category-page blocks (group K) |

## 2. Components (node ids, page `243:2`)

| Component | Node | Notes |
|---|---|---|
| Icons `PB/Icon/*` (41, 16 px, Lucide geometry) | frame `243:3` | grip, eye, eyeOff, copy, trash, plus, search, chevrons, alert, monitor, tablet, phone, history, image, link, calendar, clock, undo, redo, x, check, bold, italic, list, more, layers, globe, crop, upload, archive, type, filter, save, lock, external, send, palette, package, settings |
| `PB/Field/Text` (set) | `243:228` | `State=Default/Focus/Error/Disabled`; label, required star, counter, help or error |
| `PB/Field/Textarea` | `243:229` | auto-grows to 6 lines, counter |
| `PB/Field/Bilingual EN + AR` | `243:237` | EN and AR inputs side by side; AR is RTL / Cairo; "Copy EN → AR"; per-language error |
| `PB/Field/Rich text` | `243:257` | Paragraph/H2/H3, bold, italic, list, link; sanitised |
| `PB/Field/Number with unit` | `243:292` | suffix unit, min/max help |
| `PB/Field/Select` | `243:301` | searchable when > 8 options |
| `PB/Field/Segmented control` | `243:311` | 2–4 options |
| `PB/Field/Toggle` | `243:321` | label + description + switch |
| `PB/Field/Checkbox group` | `243:328` | devices, countries, segments |
| `PB/Field/Colour` | `243:344` | token presets first, hex second, contrast check |
| `PB/Field/Schedule (date-time range)` | `245:21` | toggle + starts/ends in page-country time with Oman chip |
| `PB/Field/Image upload` (set) | `245:112` | `State=Empty/Filled`; desktop/mobile art tabs, drop/browse/media library, crop, replace, remove, focal point, alt text EN/AR |
| `PB/Field/Product picker` | `245:113` | Pick manually / Use a rule; search with thumbnails; selected list with drag handles, SKU, price, stock; count / max |
| `PB/Field/Entity picker` | `245:195` | categories, brands, collections, vendors, flash sales: chips + searchable dropdown with path and counts |
| `PB/Field/Link picker` | `245:227` | Page / Category / Product / URL (+ brand store, campaign in the menu); resolved URL; new tab |
| `PB/Field/Repeater` | `245:257` | slides, cards, tiles, coupon cards, tabs, columns: collapse/expand, drag, hide, duplicate, delete, add, min/max, per-item error |
| `PB/Outline row` (set) | `245:463` | `State=Default/Hover/Selected/Hidden/Invalid/Dragging` |
| `PB/Gallery card` (set) | `245:502` | `State=Default/Hover/Disabled` |
| `PB/Settings group` | `245:503` | collapsible group with error badge or summary |
| `PB/Unsaved changes bar` | `245:510` | dark floating bar, Discard / Save draft |

Reused from the file: Nav/Sidebar `239:3605` (pages list shell, cloned from `238:3516`), Badge colours, block components on page `37:2` (rendered inside the preview and gallery), product photos.

## 3. Layout and tokens

**Editor (full-screen focus mode, 1440 × 960 reference; min 1280 wide)**
- Top bar 56 px, `color/bg/surface`, bottom border `color/border/default`. Left: back to Pages, page title + status chip + slug/template caption, country/locale switcher. Right: autosave text, undo, redo, History, Preview (opens draft in new tab), Save draft, Publish split button (chevron = Schedule…).
- Outline panel 280 px fixed, `color/bg/surface`, right border. Header 14/16 padding, full-width **Add section** (inverse button), zones (caption, uppercase, count, `+`), rows 36 px (radius 8, gap 2), footer "Page settings".
- Preview canvas fills the rest, `color/bg/page`, padding 12/24. Toolbar: device (desktop 1440 / tablet 768 / mobile 390), language (EN / عربي), Show hidden, zoom label. Storefront frame white, radius 10, shadow `0 2 12 / 8%`; scaled to fit the canvas width.
- Settings panel 380 px fixed (min 360, resizable to 480), left border. Header: block label + kind badge (Editor block / Ad / System / Listing / Header / Container), zone + position, more, close. Scroll padding 16, gap 16. Footer 56 px sticky: Duplicate, Delete section.
- Field width in the panel: 348 px (component width 352). Inputs 40 px high, radius 8, padding 0/12, label `en/label`, help `en/caption` `color/text/muted`, error `color/text/danger` + `color/border/danger`, focus border `color/border/focus` 2 px.
- Selection in preview: 2 px `color/border/brand` outline 6 px outside the block, tag (brand) top-left, quick actions (inverse) top-right: move up/down, duplicate, hide, delete. Hover: 1 px dashed `color/border/focus`.
- Modals: radius 16, shadow `0 24 48 / 24%`, scrim `#0F172A` 48%. Add section 1080 × 760; Publish 560 wide.
- Colours: only semantic variables (`color/bg/*`, `color/text/*`, `color/border/*`). Status chips: Draft = warning-subtle/warning, Scheduled = info-subtle/info, Published = success-subtle/success, Published · edited = info, Issues = danger-subtle/danger, Archived = subtle/secondary.
- Type: `en/*` Inter, `ar/*` Cairo. Panel titles `en/h4`, section labels `en/label`, captions `en/caption`.

**Pages list**: Admin v2 shell (sidebar 264), page header, filters 38 px, status tabs, table rows ≈ 50 px, row hover `color/bg/page` with Edit + icons, menu 240 px.

## 4. Settings panel structure

Every block's form is built from its schema and grouped in this order (empty groups are hidden):
1. **Reference** — `/images/page-blocks/{type}.png`, one-line description, *Change type* (only to compatible types; keeps matching fields).
2. **Content** — title / subtitle (bilingual), view-all link, block-specific texts, repeaters (slides, items, tiles, tabs, columns, hotspots).
3. **Products / Source** — source select (personalised, bestsellers, new arrivals, collection, category, brand, campaign, coupon, flash sale, price drop, manual), entity pickers, product picker, count, sort, sponsored slots (positions only — ads are filled by `SponsoredRanker`).
4. **Design** — layout variant (segmented), density, rows, columns, height preset, background (none / token colour / image), spacing top/bottom, countdown on/off, alignment.
5. **Visibility & schedule** — devices (checkbox group), schedule (date-time range, country time + Oman label), segments (all, new, logged in, members, custom), A/B variant.
6. **Advanced** — anchor id, tracking key, CSS class (super admin), raw JSON (read-only view + "Edit JSON" for super admins only, validated against the same schema).

Group header shows a summary when collapsed ("Live · all devices") or an error count when a field inside is invalid; invalid groups open automatically on publish attempt.

## 5. Field types (schema `type` → component)

| Schema type | Component | Used for (examples) |
|---|---|---|
| `string` | Text | anchor, tracking_key, slug, coupon code |
| `text` | Textarea | subtitle, description |
| `i18n_string` / `i18n_text` | Bilingual EN + AR | title, subtitle, slide headline, CTA label, tab label, alt text |
| `richtext` | Rich text | `seo_text`, `html` (sanitised) |
| `integer` / `number` + `unit` | Number with unit | max_items, autoplay seconds, height px, max_price (currency of the page country), max_columns |
| `enum` (> 4) | Select | source, default sort, height preset, layout variant (long lists) |
| `enum` (2–4) | Segmented control | layout, density, rows, width (full/half), alignment |
| `boolean` | Toggle | show view-all, dots/arrows, pause, show countdown, sticky |
| `set<enum>` | Checkbox group | devices, segments, visible filter groups |
| `color` | Colour | band background, tone |
| `datetime_range` / `datetime` | Schedule | schedule, ends_at (countdown_strip, campaign_hero) |
| `image` / `image_pair` | Image upload | image, image_d + image_m, hero_image, logo, slide art |
| `product_ids` | Product picker | manual products, vendor_ids (seller variant), sponsored positions |
| `category_ids` / `brand_ids` / `collection` / `flash_sale` / `vendor_ids` / `ad_slot_key` / `stream` / `video` | Entity picker | category, brands, collection, flash_sale_id/slug, ad_slot_key, stream_slug, video_url (media library) |
| `link` | Link picker | link, view_all, tab target |
| `list<object>` | Repeater | slides, slots, items, tiles, tabs, columns, hotspots, keywords, offers |
| `children` | Outline (nested) | `panel_row` columns and `section_band` children are edited as nested sections in the outline |

Required settings come from `BlockRegistry::required` (`a|b` = one of). Missing ones show as field errors and block publish (§7).

## 6. Interaction rules

**Outline & drag and drop**
- Drag a row by its grip (whole row is draggable after 4 px movement). Drop indicator: 2 px `color/border/brand` line between rows; zones that cannot accept the block dim and show "Not allowed in Listing" (`BlockRegistry::allowedIn`).
- Dragging a card from the Add section gallery onto the outline or the preview inserts at the indicator.
- Rows: click = select (preview scrolls to and outlines the block, panel opens); eye = hide/show (hidden rows muted, hidden in preview unless *Show hidden*); duplicate; more menu = Move to zone…, Rename, Copy to another page, Delete. Ad and listing rows have a lock note and no delete when the template requires them.
- Keyboard: ↑/↓ moves selection; Alt+↑/↓ moves the section; Cmd/Ctrl+D duplicates; Delete/Backspace deletes (with undo toast, no confirm); H toggles hide; Enter opens settings; `/` focuses search; Esc closes panel/modal.

**Preview**
- Live — every field change re-renders the block in < 300 ms (debounced 250 ms). Rendering uses the storefront block renderer with draft data; unresolved data shows skeletons, never empty space.
- Device toggle changes the frame width (1440 / 768 / 390) and the image crop (desktop / mobile art). Language toggle switches EN ↔ AR (RTL mirrored).
- Click a block to select it; hover shows dashed outline; selected block gets the brand outline, tag and quick actions.

**Saving**
- Autosave the draft 2 s after the last change and every 30 s at most; manual Save draft and Cmd/Ctrl+S. The top-bar status cycles Unsaved → Saving… → Saved hh:mm (country time). On failure: "Couldn't save — Retry", the unsaved bar stays and leaving the page asks for confirmation.
- Undo / redo (Cmd/Ctrl+Z, Shift+Cmd/Ctrl+Z) for the session, 100 steps.
- Concurrent editing: if another admin saves a newer draft, show a banner "Layla S. edited this page 1 min ago — Reload" and do not overwrite.

**Publish & schedule**
- Publish opens the confirmation modal: Publish now or Schedule (starts required, ends optional, page-country time with Oman label for admins). Pre-publish checks: required fields, EN + AR complete, images with alt text and mobile crops, empty sources, hidden sections reminder.
- Publish is disabled while issues exist; the "n issues" chip and the alert jump to the first invalid field (outline row → panel group → field focus).
- Every publish creates a revision (vN). Scheduled publish keeps the current live version until the start time.

**Revisions**
- History lists drafts, autosaves (collapsed per hour), published and scheduled versions with author and time (country time + Oman in tooltip). Compare: Draft / vN / Side by side; diff tags on sections (Changed · fields, Added, Removed, Moved) and a field-level diff in the panel. Restore copies the revision into the current draft (never publishes directly) and is undoable.

**Pages list**
- Filters persist in the URL. Copy to other countries opens a dialog with target countries, locale mapping and "copy as draft" (default). Archive is reversible from the Archived tab. Duplicate creates "Copy of …" as draft.

## 7. Validation messages (examples)

| Rule | Message |
|---|---|
| required field | "Title is required to publish." |
| bilingual missing | "Arabic title is missing." |
| one-of (`a|b`) | "Pick at least 4 brands or switch to a rule." / "Add an image, an ad slot or a title." |
| empty source | "This source has no products in the UAE right now — choose another or set a fallback." |
| image | "Add a mobile image (5:4) or allow automatic crop." |
| link | "This link points to an archived page." |
| schedule | "End must be after start." |
| zone | "Header blocks can only be placed in the header zone." |

## 8. RTL

- Whole editor mirrors when the admin UI language is Arabic: outline on the right, settings panel on the left, chevrons flip, switch ON = knob left, segmented order reversed, numbers and URLs stay LTR. See `254:5502`.
- Bilingual fields always show EN on the left and AR on the right regardless of UI language; the AR input is `dir="rtl"`, Cairo.

## 9. Implementation notes (for the Blade / Alpine build)

- Field components map 1:1 to Blade components under `resources/views/components/ui/` (e.g. `page-builder/field-*.blade.php`); the form is generated from a per-block field schema next to `BlockRegistry` (type, group, label EN/AR, help, required, options, min/max, unit).
- Thumbnail URL: `asset("images/page-blocks/{$type}.png")`.
- Preview: iframe of the storefront page in draft mode with a `postMessage` bridge (select, hover, scroll-to, re-render block).
- No raw JSON for regular admins; JSON stays as a read-only "Advanced" view for super admins.
