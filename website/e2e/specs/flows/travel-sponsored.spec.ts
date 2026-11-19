import { readFileSync } from "node:fs";
import path from "node:path";
import type { Locator, Page } from "@playwright/test";
import { test, expect, expectClean, expectLocale, shoot, settle } from "../../support/fixtures";
import { GENERATED } from "../../support/global-setup";
import type { Fixtures } from "../../support/routes";
import { rejectOptionalCookies } from "../../support/storefront";

/**
 * D14 "Travel: free listing, paid placement": the e2e agency's trip is
 * promoted by E2eSeeder (`promoted_trip_slug`), so travel search lists it
 * first with the Sponsored label (EN/AR) and its trip page shows the label.
 */
const API = process.env.E2E_API_URL ?? "http://localhost:8000";
const promoted = () => (JSON.parse(readFileSync(path.join(GENERATED, "fixtures.json"), "utf8")) as Fixtures).promoted_trip_slug ?? null;

const cards = (page: Page) => page.locator("main").getByTestId("trip-card");
const cardOf = (page: Page, slug: string) => page.locator(`main [data-testid="trip-card"][href$="/travel/${encodeURIComponent(slug)}"]`);
const badge = (scope: Locator) => scope.getByTestId("trip-sponsored");

/** The promoted trip leads the list: the first card is sponsored and the trip is in the leading sponsored block. */
async function expectPromotedFirst(page: Page, slug: string, label: string) {
  const all = cards(page);
  await expect(all.first()).toBeVisible();
  const hrefs = await all.evaluateAll((els) => els.map((el) => [el.getAttribute("href") ?? "", !!el.querySelector('[data-testid="trip-sponsored"]')] as const));
  const leading = hrefs.findIndex(([, sponsored]) => !sponsored);
  const sponsoredBlock = leading === -1 ? hrefs : hrefs.slice(0, leading);
  expect(sponsoredBlock.length, "sponsored cards come first").toBeGreaterThan(0);
  expect(hrefs.slice(sponsoredBlock.length).some(([, sponsored]) => sponsored), "no sponsored card after an organic one").toBe(false);
  expect(sponsoredBlock.map(([href]) => href.split("/").pop())).toContain(encodeURIComponent(slug));
  if (sponsoredBlock.length === 1) expect(await all.first().getAttribute("href")).toMatch(new RegExp(`/travel/${encodeURIComponent(slug)}$`));
  await expect(badge(cardOf(page, slug))).toHaveText(label);
}

test.beforeEach(async ({ page }) => {
  test.skip(!promoted(), "E2eSeeder did not promote a trip (promoted_trip_slug is null)");
  await rejectOptionalCookies(page);
});

test("promoted trip leads travel search with the Sponsored label", async ({ page, problems }) => {
  const slug = promoted()!;
  await page.goto("/uae-en/travel/search");
  await settle(page);
  await expectPromotedFirst(page, slug, "Sponsored");
  await shoot(page, "flows", "travel-sponsored-search");

  // Same with the trip's own type filter.
  const detail = (await (await page.request.get(`${API}/api/v1/uae-en/travel/packages/${encodeURIComponent(slug)}`, { headers: { Accept: "application/json" } })).json()) as { data?: { trip_type?: string | null } };
  const type = detail.data?.trip_type;
  if (type) {
    await page.goto(`/uae-en/travel/search?trip_type=${encodeURIComponent(type)}`);
    await settle(page);
    await expectPromotedFirst(page, slug, "Sponsored");
  }
  expectClean(problems);
});

test("trip page shows the Sponsored label near the title", async ({ page, problems }) => {
  const slug = promoted()!;
  await page.goto(`/uae-en/travel/${slug}`);
  await settle(page);
  const header = page.locator("main h1").locator("..");
  await expect(badge(header)).toHaveText("Sponsored");
  const related = page.getByTestId("trip-related");
  if (await related.count()) await expect(related.getByTestId("trip-card").first()).toBeVisible();
  await shoot(page, "flows", "travel-sponsored-trip");
  expectClean(problems);
});

test("Arabic market shows the Arabic Sponsored label", async ({ page, problems }) => {
  const slug = promoted()!;
  await page.goto("/uae-ar/travel/search");
  await settle(page);
  await expectLocale(page, "ar");
  await expectPromotedFirst(page, slug, "مُموَّل");
  await shoot(page, "flows", "travel-sponsored-search-ar");

  await page.goto(`/uae-ar/travel/${slug}`);
  await settle(page);
  await expect(badge(page.locator("main h1").locator(".."))).toHaveText("مُموَّل");
  expectClean(problems);
});
