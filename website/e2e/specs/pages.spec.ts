import { readFileSync, writeFileSync } from "node:fs";
import path from "node:path";
import { test, expect, expectClean, expectLocale, internalLinks, settle, shoot } from "../support/fixtures";
import { BOGUS, QUERY_PAGES, isAccount, resolveRoutes, routePatterns, type Fixtures, type RouteEntry } from "../support/routes";
import { CUSTOMER_STATE } from "../support/accounts";
import { API_ROOT, GENERATED } from "../support/global-setup";

/**
 * Every storefront and portal page (app/[market]/**): all of them in uae-en and
 * uae-ar, a representative subset in the other markets. Each page: HTTP status,
 * no console or page errors, no raw translation keys, dir/lang, a full-page
 * screenshot, and its internal links saved for links.spec.ts.
 */
const FULL_MARKETS = ["uae-en", "uae-ar"];
const SUBSET_MARKETS = ["omn-ar", "omn-en", "ksa-ar", "egy-en", "kwt-ar", "qat-en", "bhr-ar", "jor-en"];
const SUBSET_PATTERNS = ["", "search", "cart", "deals", "help", "products/[slug]", "[...slug]", "travel", "classified", "property", "membership", "gift-cards", "login", "sell", "join", "pages/[slug]", "blog", "account", "account/orders"];

const resolved = new Map<string, Promise<RouteEntry[]>>();
function routesOf(market: string): Promise<RouteEntry[]> {
  if (!resolved.has(market)) {
    const fixtures = JSON.parse(readFileSync(path.join(GENERATED, "fixtures.json"), "utf8")) as Fixtures;
    const token = readFileSync(path.join(GENERATED, "customer-token.txt"), "utf8").trim();
    resolved.set(market, resolveRoutes(API_ROOT, market, fixtures, token).then((r) => r.routes));
  }
  return resolved.get(market)!;
}

const locale = (market: string) => (market.endsWith("-ar") ? "ar" : "en");
const shotName = (p: string) => (p === "" ? "home" : p.replace(/\//g, "__"));

async function visit(page: import("@playwright/test").Page, market: string, route: RouteEntry, problems: import("../support/fixtures").PageProblems) {
  const url = `/${market}${route.path ? `/${route.path}` : ""}`;
  const response = await page.goto(url, { waitUntil: "domcontentloaded" });
  expect(response, `no response for ${url}`).not.toBeNull();
  expect(response!.status(), `HTTP status of ${url}`).toBe(route.status);
  await settle(page);
  await expectLocale(page, locale(market));
  const body = await page.locator("body").innerText();
  expect.soft(body, "error digest / 500 text").not.toMatch(/Application error|Internal Server Error|digest:/i);
  if (route.status === 200 && route.auth) expect.soft(page.url(), "signed-in page redirected to login").not.toContain("/login");
  await shoot(page, market, shotName(route.pattern === route.path ? route.path : `${route.pattern.replace(/[[\].]/g, "")}--${route.path.split("/").pop()}`));
  const links = await internalLinks(page);
  if (route.status === 404) {
    // Chrome logs the document's own 404 status as a console error; that one is the expected result.
    const own = `${page.url()} :: Failed to load resource: the server responded with a status of 404 (Not Found)`;
    problems.console.splice(0, problems.console.length, ...problems.console.filter((line) => line !== own));
  }
  // A 404 page links to the same missing path in the other language (also a 404): only real pages feed the link check.
  if (route.status === 200) writeFileSync(path.join(GENERATED, "links", `${market}--${shotName(route.path).replace(/[^a-zA-Z0-9_-]/g, "_")}.json`), JSON.stringify({ from: url, links }));
  expectClean(problems);
}

for (const market of [...FULL_MARKETS, ...SUBSET_MARKETS]) {
  const patterns = routePatterns().filter((p) => p !== "missing" && (FULL_MARKETS.includes(market) || SUBSET_PATTERNS.includes(p)));
  test.describe(`${market} pages`, () => {
    for (const pattern of patterns) {
      const auth = isAccount(pattern);
      test.describe(() => {
        if (auth) test.use({ storageState: CUSTOMER_STATE });
        test(`${market}/${pattern || "(home)"}`, async ({ page, problems }) => {
          const route = (await routesOf(market)).find((r) => r.pattern === pattern);
          test.skip(!route, `no ${pattern} record in ${market}`);
          if (QUERY_PAGES.has(pattern)) test.info().annotations.push({ type: "note", description: "opened without its query: shows its own error state" });
          await visit(page, market, route!, problems);
        });
      });
    }
  });
}

test.describe("real 404s", () => {
  for (const market of ["uae-en", "uae-ar", "omn-ar"]) {
    for (const bogus of BOGUS) {
      test(`${market}/${bogus} is 404`, async ({ page, problems }) => {
        await visit(page, market, { path: bogus, pattern: bogus, auth: false, status: 404 }, problems);
      });
    }
  }
  test("unknown market is 404", async ({ page }) => {
    const res = await page.goto("/xyz-en/cart");
    expect(res!.status()).toBe(404);
  });
});
