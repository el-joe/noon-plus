import type { Page } from "@playwright/test";
import { mkdirSync, readdirSync, readFileSync, writeFileSync } from "node:fs";
import path from "node:path";
import { expect, settle, test } from "../../../support/fixtures";
import { rejectOptionalCookies } from "../../../support/storefront";

/**
 * Header category mega menu, every market × language (project `areas`).
 *
 * Per market: hover every top-level nav category and check the flyout
 * (level-2 columns, level-3 links, the category image really loads, top
 * brands, image on the far side for the language direction), then follow
 * one level-3 link and one brand tile (the listing shows products and the
 * menu is closed). Timings are recorded.
 *
 * Output:
 * - screenshot per market: e2e/docx/shots/mega-menu/{market}.png (flyout open)
 * - per-market fragments: e2e/results/mega-menu/{market}.json
 * - merged summary:      e2e/results/mega-menu.json
 *   { generated_at, storefront, api, markets: [{ market, locale, dir, api_ms, page_load_ms,
 *     top_level, opened, failures, categories: [{ slug, name, open_ms, columns, api_columns, leaves,
 *     api_leaves, brands, api_brands, image_loaded, image_far_side }], level3: {...}, brand: {...},
 *     screenshot }] , totals: {...} }
 */

const COUNTRIES = ["uae", "omn", "ksa", "egy", "kwt", "qat", "bhr", "jor"] as const;
const LOCALES = ["en", "ar"] as const;
const API = `${process.env.E2E_API_URL ?? "http://localhost:8000"}/api/v1`;
const E2E_ROOT = path.resolve(__dirname, "../../..");
const SHOTS = path.join(E2E_ROOT, "docx/shots/mega-menu");
const RESULTS = path.join(E2E_ROOT, "results");
const FRAGMENTS = path.join(RESULTS, "mega-menu");

type ApiMenu = Array<{
  slug: string;
  name: string;
  path: string;
  image: string | null;
  children: Array<{ slug: string; children: Array<{ slug: string }> }>;
  brands: Array<{ slug: string; path: string }>;
}>;

type CategoryResult = {
  slug: string;
  name: string;
  open_ms: number | null;
  columns: number;
  api_columns: number;
  leaves: number;
  api_leaves: number;
  brands: number;
  api_brands: number;
  image_loaded: boolean;
  image_far_side: boolean | null;
  error?: string;
};

type FollowResult = { from: string; href: string | null; ms: number | null; products: number; menu_closed: boolean; ok: boolean; error?: string };

type MarketResult = {
  market: string;
  locale: string;
  dir: "ltr" | "rtl";
  api_ms: number;
  page_load_ms: number;
  top_level: number;
  opened: number;
  categories: CategoryResult[];
  level3: FollowResult | null;
  brand: FollowResult | null;
  screenshot: string | null;
  failures: string[];
};

const panel = (page: Page) => page.locator('[data-testid="mega-menu-panel"]');
const trigger = (page: Page, slug: string) => page.locator(`header [data-menu-trigger="${slug}"]`);

/** Products on a listing page (product cards link to /{market}/products/{slug}). */
async function productCount(page: Page, market: string) {
  const cards = page.locator(`main a[href*="/${market}/products/"]`);
  await expect(cards.first()).toBeVisible({ timeout: 20_000 });
  return cards.count();
}

async function openFlyout(page: Page, slug: string) {
  const link = trigger(page, slug);
  await link.scrollIntoViewIfNeeded();
  // Leave the nav first so every open pays the hover-intent delay (comparable timings).
  await page.mouse.move(5, 600);
  await expect(panel(page)).toHaveCount(0, { timeout: 5_000 });
  const started = Date.now();
  await link.hover();
  await expect(page.locator(`#mega-menu-${slug}`)).toBeVisible({ timeout: 5_000 });
  return Date.now() - started;
}

/** Writes this market's fragment and (re)merges every fragment into results/mega-menu.json. */
function record(result: MarketResult) {
  mkdirSync(FRAGMENTS, { recursive: true });
  writeFileSync(path.join(FRAGMENTS, `${result.market}.json`), JSON.stringify(result, null, 2));
  // Parallel workers: merge until the set of fragments is stable, so the last writer includes everyone.
  for (let seen = -1, attempt = 0; attempt < 5; attempt++) {
    const files = readdirSync(FRAGMENTS).filter((f) => f.endsWith(".json")).sort();
    if (files.length === seen) break;
    seen = files.length;
    const markets: MarketResult[] = files.map((f) => JSON.parse(readFileSync(path.join(FRAGMENTS, f), "utf8")));
    const all = markets.flatMap((m) => m.categories);
    const openTimes = all.map((c) => c.open_ms).filter((ms): ms is number => ms !== null);
    writeFileSync(
      path.join(RESULTS, "mega-menu.json"),
      JSON.stringify(
        {
          generated_at: new Date().toISOString(),
          storefront: process.env.E2E_STOREFRONT_URL ?? "http://localhost:3000",
          api: API,
          totals: {
            markets: markets.length,
            top_level_categories: all.length,
            opened: all.filter((c) => c.open_ms !== null).length,
            with_columns: all.filter((c) => c.columns > 0).length,
            with_level3: all.filter((c) => c.leaves > 0).length,
            with_brands: all.filter((c) => c.brands > 0).length,
            images_loaded: all.filter((c) => c.image_loaded).length,
            level3_follows_ok: markets.filter((m) => m.level3?.ok).length,
            brand_follows_ok: markets.filter((m) => m.brand?.ok).length,
            failures: markets.reduce((n, m) => n + m.failures.length, 0),
            open_ms_avg: openTimes.length ? Math.round(openTimes.reduce((a, b) => a + b, 0) / openTimes.length) : null,
            open_ms_max: openTimes.length ? Math.max(...openTimes) : null,
            api_ms_avg: markets.length ? Math.round(markets.reduce((a, m) => a + m.api_ms, 0) / markets.length) : null,
          },
          markets,
        },
        null,
        2,
      ),
    );
  }
}

const message = (e: unknown) => String((e as Error)?.message ?? e).replace(/\u001b\[\d+m/g, "").split("\n").filter(Boolean).slice(0, 3).join(" ").slice(0, 300);

for (const country of COUNTRIES) {
  for (const locale of LOCALES) {
    const market = `${country}-${locale}`;

    test(`mega menu ${market}: every top-level category opens with columns, level-3 links, image and top brands`, async ({ page, request }) => {
      test.setTimeout(300_000);
      await rejectOptionalCookies(page);
      await page.setViewportSize({ width: 1440, height: 900 });
      const dir = locale === "ar" ? "rtl" : "ltr";
      const result: MarketResult = { market, locale, dir, api_ms: 0, page_load_ms: 0, top_level: 0, opened: 0, categories: [], level3: null, brand: null, screenshot: null, failures: [] };

      try {
        // The API the menu reads (cached server side: the second call is the warm one).
        await request.get(`${API}/${market}/catalog/menu`);
        let started = Date.now();
        const response = await request.get(`${API}/${market}/catalog/menu`);
        result.api_ms = Date.now() - started;
        expect(response.ok()).toBe(true);
        const menu = ((await response.json()) as { data: ApiMenu }).data;
        const bySlug = new Map(menu.map((c) => [c.slug, c]));

        started = Date.now();
        await page.goto(`/${market}`);
        await settle(page);
        result.page_load_ms = Date.now() - started;
        await expect(page.locator("html")).toHaveAttribute("dir", dir);

        const triggers = page.locator("header [data-menu-trigger]");
        await expect(triggers.first()).toBeVisible();
        await expect(page.locator('header [data-menu-trigger][aria-haspopup="true"]').first()).toBeAttached({ timeout: 15_000 });
        const slugs = await triggers.evaluateAll((nodes) => nodes.map((n) => n.getAttribute("data-menu-trigger") ?? ""));
        result.top_level = slugs.length;

        for (const slug of slugs) {
          const api = bySlug.get(slug);
          const row: CategoryResult = {
            slug,
            name: (await trigger(page, slug).innerText()).trim(),
            open_ms: null,
            columns: 0,
            api_columns: api?.children.length ?? 0,
            leaves: 0,
            api_leaves: api?.children.reduce((n, c) => n + c.children.length, 0) ?? 0,
            brands: 0,
            api_brands: api?.brands.length ?? 0,
            image_loaded: false,
            image_far_side: null,
          };
          result.categories.push(row);
          try {
            row.open_ms = await openFlyout(page, slug);
            result.opened++;
            const flyout = page.locator(`#mega-menu-${slug}`);
            await expect(trigger(page, slug)).toHaveAttribute("aria-expanded", "true");
            await expect(flyout).toHaveAttribute("dir", dir);
            row.columns = await flyout.locator('[data-testid="mega-menu-column"]').count();
            row.leaves = await flyout.locator('[data-testid="mega-menu-leaf"]').count();
            row.brands = await flyout.locator('[data-testid="mega-menu-brand"]').count();

            const image = flyout.locator('[data-testid="mega-menu-image"] img');
            if ((await image.count()) > 0) {
              await expect.poll(() => image.evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth > 0), { timeout: 10_000 }).toBe(true);
              row.image_loaded = true;
              const [imageBox, rowBox] = await Promise.all([flyout.locator('[data-testid="mega-menu-image"]').boundingBox(), flyout.locator('[data-testid="mega-menu-row"]').boundingBox()]);
              if (imageBox && rowBox) {
                const centre = imageBox.x + imageBox.width / 2;
                row.image_far_side = dir === "rtl" ? centre < rowBox.x + rowBox.width / 2 : centre > rowBox.x + rowBox.width / 2;
              }
            }

            expect.soft(row.columns, `${market} ${slug}: level-2 columns`).toBeGreaterThan(0);
            expect.soft(row.columns, `${market} ${slug}: columns match the API`).toBe(row.api_columns);
            expect.soft(row.leaves, `${market} ${slug}: level-3 links`).toBeGreaterThan(0);
            expect.soft(row.brands, `${market} ${slug}: top brands`).toBeGreaterThan(0);
            expect.soft(row.image_loaded, `${market} ${slug}: category image loaded`).toBe(true);
            expect.soft(row.image_far_side, `${market} ${slug}: image on the far side (${dir})`).toBe(true);
            for (const [ok, what] of [
              [row.columns > 0, "no level-2 columns"],
              [row.leaves > 0, "no level-3 links"],
              [row.brands > 0, "no top brands"],
              [row.image_loaded, "image not loaded"],
              [row.image_far_side === true, "image not on the far side"],
            ] as const) {
              if (!ok) result.failures.push(`${slug}: ${what}`);
            }
          } catch (e) {
            row.error = message(e);
            result.failures.push(`${slug}: ${row.error}`);
            expect.soft(row.error, `${market} ${slug}: flyout`).toBeUndefined();
          }
        }

        // Screenshot: the first category with columns, flyout open.
        const shotSlug = result.categories.find((c) => c.columns > 0)?.slug ?? slugs[0];
        if (shotSlug) {
          await openFlyout(page, shotSlug).catch(() => undefined);
          await page.locator(`#mega-menu-${shotSlug} [data-testid="mega-menu-image"] img`).evaluate((img: HTMLImageElement) => img.decode().catch(() => undefined)).catch(() => undefined);
          mkdirSync(SHOTS, { recursive: true });
          await page.screenshot({ path: path.join(SHOTS, `${market}.png`), animations: "disabled" });
          result.screenshot = `docx/shots/mega-menu/${market}.png`;
        }

        // Follow one level-3 link and one brand tile.
        const follow = async (kind: "leaf" | "brand"): Promise<FollowResult> => {
          const from = result.categories.find((c) => (kind === "leaf" ? c.leaves > 0 : c.brands > 0))?.slug;
          const out: FollowResult = { from: from ?? "", href: null, ms: null, products: 0, menu_closed: false, ok: false };
          if (!from) {
            out.error = `no category with a ${kind === "leaf" ? "level-3 link" : "brand tile"}`;
            return out;
          }
          try {
            await page.goto(`/${market}`);
            await settle(page);
            await openFlyout(page, from);
            const link = page.locator(`#mega-menu-${from} [data-testid="mega-menu-${kind}"]`).first();
            out.href = await link.getAttribute("href");
            const started = Date.now();
            await link.click();
            await page.waitForURL((url) => `${url.pathname}${url.search}`.startsWith(out.href ?? "\u0000"), { timeout: 30_000 });
            out.products = await productCount(page, market);
            out.ms = Date.now() - started;
            await expect(panel(page)).toHaveCount(0);
            out.menu_closed = true;
            if (kind === "brand") expect(new URL(page.url()).searchParams.get("brand")).toBeTruthy();
            out.ok = out.products > 0;
          } catch (e) {
            out.error = message(e);
          }
          return out;
        };

        result.level3 = await follow("leaf");
        result.brand = await follow("brand");
        for (const [label, f] of [["level-3 link", result.level3], ["brand tile", result.brand]] as const) {
          if (!f.ok) result.failures.push(`${label} from ${f.from || "—"}: ${f.error ?? "listing showed no products"}`);
          expect.soft(f.ok, `${market}: follow a ${label} (${f.href ?? "none"}) → listing with products, menu closed. ${f.error ?? ""}`).toBe(true);
        }
      } catch (e) {
        result.failures.push(message(e));
        throw e;
      } finally {
        record(result);
      }

      expect(result.top_level, `${market}: top-level categories in the nav bar`).toBeGreaterThan(0);
      expect(result.opened, `${market}: flyouts opened`).toBe(result.top_level);
    });
  }
}
