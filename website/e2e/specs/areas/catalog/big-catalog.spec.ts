import type { APIRequestContext, Page } from "@playwright/test";
import { mkdirSync, readdirSync, readFileSync, writeFileSync } from "node:fs";
import path from "node:path";
import { expect, settle, test, type PageProblems } from "../../../support/fixtures";
import { rejectOptionalCookies } from "../../../support/storefront";

/**
 * Live test of the big development catalogue (`php artisan nawy:dev:big-catalog`,
 * ~5,000 products / ~30,000 offers / 60 sellers per country), every market × language
 * (project `areas`, no seeding: run with E2E_NO_GLOBAL_SETUP=1 --no-deps).
 *
 * Per market: home (page-builder sections with real products and local-currency
 * prices), the Electronics category (rail filters change the results, infinite
 * scroll loads more, sponsored rows), a product with several sellers, and search.
 * Every page: no broken images, no console / page errors, timings recorded.
 *
 * Output:
 * - screenshots: e2e/docx/shots/big-catalog/{market}-{home|category|product|search}.png
 * - per-market fragments: e2e/results/big-catalog/{market}.json (merged by
 *   e2e/docx/big-catalog-report.mjs into the docx report)
 */

const COUNTRIES = ["uae", "omn", "ksa", "egy", "kwt", "qat", "bhr", "jor"] as const;
const LOCALES = ["en", "ar"] as const;
const CURRENCY: Record<string, string> = { uae: "AED", omn: "OMR", ksa: "SAR", egy: "EGP", kwt: "KWD", qat: "QAR", bhr: "BHD", jor: "JOD" };
const THREE_DECIMALS = new Set(["OMR", "KWD", "BHD", "JOD"]);
const API = `${process.env.E2E_API_URL ?? "http://localhost:8000"}/api/v1`;
const E2E_ROOT = path.resolve(__dirname, "../../..");
const SHOTS = path.join(E2E_ROOT, "docx/shots/big-catalog");
const FRAGMENTS = path.join(E2E_ROOT, "results/big-catalog");

type Step = { page: string; url: string; load_ms: number; checks: Record<string, number | string | boolean>; broken_images: number; console_errors: number; screenshot: string; ok: boolean; error?: string };
type MarketResult = { market: string; currency: string; api: Record<string, number>; steps: Step[] };

const group = (n: number) => String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ",");

async function apiJson(request: APIRequestContext, url: string): Promise<{ ms: number; body: any }> {
  const started = Date.now();
  const response = await request.get(`${API}/${url}`);
  expect(response.ok(), url).toBeTruthy();
  return { ms: Date.now() - started, body: await response.json() };
}

/** Scroll to the end (lazy blocks, infinite scroll), then back to the top. */
async function scrollThrough(page: Page, rounds = 14) {
  for (let i = 0; i < rounds; i++) {
    await page.mouse.wheel(0, 1600);
    await page.waitForTimeout(120);
  }
  await settle(page, 5_000);
}

async function brokenImages(page: Page) {
  return page.evaluate(() => [...document.images].filter((img) => img.complete && img.currentSrc !== "" && img.naturalWidth === 0).map((img) => img.currentSrc));
}

const productLinks = (page: Page, market: string) => page.locator(`main a[href*="/${market}/products/"]`);

async function distinctProducts(page: Page, market: string) {
  const hrefs = await productLinks(page, market).evaluateAll((links) => links.map((a) => (a as HTMLAnchorElement).pathname));
  return new Set(hrefs).size;
}

async function shot(page: Page, name: string, fullPage = true) {
  mkdirSync(SHOTS, { recursive: true });
  const file = path.join(SHOTS, `${name}.png`);
  await page.screenshot({ path: file, fullPage, animations: "disabled" });
  return path.relative(E2E_ROOT, file);
}

function consoleErrors(problems: PageProblems, since: number) {
  return problems.console.length + problems.pageErrors.length - since;
}

for (const country of COUNTRIES) {
  for (const locale of LOCALES) {
    const market = `${country}-${locale}`;
    const currency = CURRENCY[country];

    test(`big catalogue ${market}: home, category, product and search with real data`, async ({ page, request, problems }) => {
      test.setTimeout(240_000);
      await page.setViewportSize({ width: 1440, height: 900 });
      const result: MarketResult = { market, currency, api: {}, steps: [] };
      const amount = new RegExp(`${currency}\\s*[\\d,]+\\.\\d{${THREE_DECIMALS.has(currency) ? 3 : 2}}`);

      const step = async (name: string, url: string, body: (s: Step) => Promise<void>, fullPage = true) => {
        const before = problems.console.length + problems.pageErrors.length;
        const s: Step = { page: name, url, load_ms: 0, checks: {}, broken_images: 0, console_errors: 0, screenshot: "", ok: false };
        try {
          const started = Date.now();
          await page.goto(url, { waitUntil: "domcontentloaded" });
          await page.locator("main").first().waitFor();
          s.load_ms = Date.now() - started;
          await settle(page);
          await body(s);
          const broken = await brokenImages(page);
          s.broken_images = broken.length;
          expect.soft(broken, `${market} ${name}: broken images`).toEqual([]);
          s.screenshot = await shot(page, `${market}-${name}`, fullPage);
          s.console_errors = consoleErrors(problems, before);
          expect.soft(s.console_errors, `${market} ${name}: console errors`).toBe(0);
          s.ok = s.broken_images === 0 && s.console_errors === 0;
        } catch (error) {
          s.error = (error as Error).message.split("\n")[0];
          throw error;
        } finally {
          result.steps.push(s);
          mkdirSync(FRAGMENTS, { recursive: true });
          writeFileSync(path.join(FRAGMENTS, `${market}.json`), JSON.stringify(result, null, 2));
        }
      };

      await page.goto(`/${market}`);
      await rejectOptionalCookies(page);

      // API timings (warm) for the report.
      for (const [key, url] of Object.entries({
        listing: `${market}/products?category=electronics&per_page=60`,
        facets: `${market}/products/facets?category=electronics`,
        filtered: `${market}/products?category=electronics&deals=1&fulfilment=fbn&per_page=60`,
        search: `${market}/products?q=smart&per_page=60`,
        home_page: `${market}/pages/home`,
      })) {
        await apiJson(request, url);
        result.api[key] = (await apiJson(request, url)).ms;
      }

      // 1. Home: page-builder sections with real products in the market's currency.
      await step("home", `/${market}`, async (s) => {
        await scrollThrough(page);
        const text = await page.locator("main").innerText();
        s.checks.products = await distinctProducts(page, market);
        s.checks.prices = (text.match(new RegExp(amount.source, "g")) ?? []).length;
        s.checks.sections = await page.locator("main section").count();
        s.checks.countdown = text.match(/\d{2}\s*:\s*\d{2}\s*:\s*\d{2}/) !== null || (await page.locator("main [data-countdown], main time").count()) > 0;
        expect(s.checks.products as number, "home shows real products").toBeGreaterThanOrEqual(30);
        expect(s.checks.prices as number, `home prices in ${currency}`).toBeGreaterThanOrEqual(20);
        expect(text).toMatch(/FIRST15|NAWY10|SCENT10/);
        expect(s.checks.sections as number).toBeGreaterThanOrEqual(12);
      });

      // 2. Category: real results, a filter changes them, infinite scroll loads more.
      const facets = (await apiJson(request, `${market}/products/facets?category=electronics`)).body.data;
      const all = (await apiJson(request, `${market}/products?category=electronics&per_page=1`)).body.meta.total as number;
      await step("category", `/${market}/electronics`, async (s) => {
        s.checks.total = all;
        s.checks.brands = facets.brands.length;
        s.checks.sellers = facets.sellers.length;
        expect(all, "electronics has many products").toBeGreaterThan(500);
        expect(facets.sellers.length, "multiple sellers in the facet").toBeGreaterThanOrEqual(10);
        const main = page.locator("main");
        await expect(main).toContainText(group(all));
        await expect(main).toContainText(amount);
        await page.screenshot({ path: path.join(SHOTS, `${market}-category-top.png`), animations: "disabled" });

        const first = await distinctProducts(page, market);
        // Keep scrolling to the end until the next page has been appended (bounded).
        let more = first;
        for (let round = 0; round < 12 && more <= first; round++) {
          await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
          await page.waitForTimeout(700);
          more = await distinctProducts(page, market);
        }
        s.checks.first_page = first;
        s.checks.after_scroll = more;
        expect(more, "infinite scroll loads more products").toBeGreaterThan(first);

        // Filter: pick the top brand in the rail → URL and result count change.
        const brand = facets.brands[0];
        const filteredTotal = (await apiJson(request, `${market}/products?category=electronics&brand=${brand.slug}&per_page=1`)).body.meta.total as number;
        await page.evaluate(() => window.scrollTo(0, 0));
        const started = Date.now();
        await page.locator("aside, [data-testid='filter-rail']").getByText(brand.name, { exact: true }).first().click();
        await page.waitForURL(new RegExp(`brand=${brand.slug}`));
        await settle(page);
        s.checks.filter_ms = Date.now() - started;
        s.checks.filtered_total = filteredTotal;
        expect(filteredTotal).toBeLessThan(all);
        await expect(main).toContainText(group(filteredTotal));
      }, false);

      // 3. Product with several sellers: the other offers are listed.
      const candidates = (await apiJson(request, `${market}/products?category=mobiles&sort=bestselling&per_page=40`)).body.data as Array<{ slug: string }>;
      let slug = candidates[0]?.slug;
      let detail: any = null;
      for (const c of candidates) {
        detail = (await apiJson(request, `${market}/products/${c.slug}`)).body.data;
        if (new Set([detail.buy_box?.seller?.slug, ...(detail.other_offers ?? []).map((o: any) => o.seller?.slug)].filter(Boolean)).size >= 3) {
          slug = c.slug;
          break;
        }
      }
      await step("product", `/${market}/products/${slug}`, async (s) => {
        const sellers = [...new Set<string>([detail.buy_box?.seller?.name, ...(detail.other_offers ?? []).map((o: any) => o.seller?.name)].filter(Boolean))];
        s.checks.sellers = sellers.length;
        expect(sellers.length, "product has several sellers").toBeGreaterThanOrEqual(3);
        const main = page.locator("main");
        await expect(main).toContainText(detail.title);
        await expect(main).toContainText(amount);
        // The other offers sit behind "N other offers from …" (collapsed by default).
        const toggle = main.locator("section button[aria-expanded='false']").filter({ hasText: currency }).first();
        if (await toggle.isVisible().catch(() => false)) await toggle.click();
        let shown = 0;
        for (const name of sellers) shown += (await main.getByText(name).count()) > 0 ? 1 : 0;
        s.checks.sellers_shown = shown;
        expect(shown, "other sellers shown on the product page").toBeGreaterThanOrEqual(2);
        const gallery = page.locator("main img[src*='products/demo']");
        expect(await gallery.count()).toBeGreaterThan(0);
      });

      // 4. Search.
      const searchTotal = (await apiJson(request, `${market}/products?q=smart&per_page=1`)).body.meta.total as number;
      await step("search", `/${market}/search?q=smart`, async (s) => {
        s.checks.total = searchTotal;
        s.checks.products = await distinctProducts(page, market);
        expect(searchTotal).toBeGreaterThan(50);
        expect(s.checks.products as number).toBeGreaterThan(10);
        await expect(page.locator("main")).toContainText(amount);
      }, false);
    });
  }
}

test.afterAll(() => {
  // Keep a merged summary next to the fragments (the docx builder re-merges anyway).
  try {
    const markets = readdirSync(FRAGMENTS).filter((f) => f.endsWith(".json")).map((f) => JSON.parse(readFileSync(path.join(FRAGMENTS, f), "utf8")));
    writeFileSync(path.join(E2E_ROOT, "results/big-catalog.json"), JSON.stringify({ generated_at: new Date().toISOString(), markets }, null, 2));
  } catch {
    /* no fragments yet */
  }
});
