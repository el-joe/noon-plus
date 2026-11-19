import { test, expect, expectClean, expectLocale, settle } from "../../../support/fixtures";
import { rejectOptionalCookies } from "../../../support/storefront";
import { shot, souq } from "./support/souq";

/**
 * Open Souq, guest browsing: home and categories, search with filters and
 * sort, motors, ad detail, real estate list / map view / property detail
 * with the financing card, shop page, compare, Arabic RTL.
 */
test.beforeEach(async ({ page }) => rejectOptionalCookies(page));

test("SOQ-01 Open Souq home lists categories and ads", async ({ page, problems }) => {
  souq();
  await page.goto("/uae-en/classified");
  await settle(page);
  await expect(page.locator("main h1")).toBeVisible();
  await expect(page.locator("main")).toContainText("Browse categories");
  for (const name of ["Cars & vehicles", "Mobiles & electronics", "Property"]) await expect(page.locator("main").getByRole("link", { name: new RegExp(name) }).first()).toBeVisible();
  await shot(page, "SOQ-01", 1);
  // Category tile → filtered search.
  await page.locator("main").getByRole("link", { name: /Mobiles & electronics/ }).first().click();
  await expect(page).toHaveURL(/category=electronics-souq|electronics-souq/);
  await settle(page);
  await expect(page.locator("main")).toContainText("Galaxy S24");
  await shot(page, "SOQ-01", 2);
  expectClean(problems);
});

test("SOQ-02 search, filters and sort", async ({ page, problems }) => {
  souq();
  await page.goto("/uae-en/classified");
  await settle(page);
  const hero = page.locator("main form").first();
  await hero.locator('input[name="q"]').fill("Galaxy");
  await hero.locator('input[name="q"]').press("Enter");
  await expect(page).toHaveURL(/classified\/search\?.*q=Galaxy/);
  await settle(page);
  await expect(page.locator("main")).toContainText(/Results for “Galaxy”/);
  await expect(page.locator("main")).toContainText("E2E Souq shop — Galaxy S24 Ultra 512GB");
  await shot(page, "SOQ-02", 1);

  // Price filter excludes the 3,200 AED phone; widening brings it back.
  await page.goto("/uae-en/classified/search?category=electronics-souq&min_price=5000");
  await settle(page);
  await expect(page.locator("main")).not.toContainText("Galaxy S24 Ultra");
  await shot(page, "SOQ-02", 2);
  const form = page.locator("main form").filter({ has: page.locator('input[name="min_price"]') }).first();
  await form.locator('input[name="min_price"]').fill("1000");
  await form.locator('input[name="max_price"]').fill("4000");
  await form.locator('input[name="city"]').fill("Dubai");
  await form.getByRole("button", { name: "Apply filters" }).click();
  await expect(page).toHaveURL(/min_price=1000/);
  await settle(page);
  await expect(page.locator("main")).toContainText("Galaxy S24 Ultra");
  await shot(page, "SOQ-02", 3);

  // Sort by price, then a no-result search shows the empty state.
  await page.goto("/uae-en/classified/search?sort=price_asc");
  await settle(page);
  await expect(page.locator("main")).toContainText(/\d+ ads?/);
  await page.goto("/uae-en/classified/search?q=zzqqxxnothing");
  await settle(page);
  await expect(page.locator("main")).toContainText("No ads match your search");
  await shot(page, "SOQ-02", 4);
  expectClean(problems);
});

test("SOQ-03 motors: cars category and a car ad", async ({ page, problems }) => {
  await page.goto("/uae-en/classified/search?category=cars-for-sale");
  await settle(page);
  await expect(page.locator("main")).toContainText("Toyota Land Cruiser");
  await shot(page, "SOQ-03", 1);
  await page.locator("main a[href*='/classified/find/CL-AE-DEMO1']").first().click();
  await expect(page).toHaveURL(/classified\/find\/CL-AE-DEMO1/);
  await settle(page);
  await expect(page.getByRole("heading", { level: 1, name: /Toyota Land Cruiser/ })).toBeVisible();
  await expect(page.locator("main")).toContainText("Stay safe");
  await expect(page.getByRole("button", { name: "Show phone number" })).toBeVisible();
  await expect(page.getByRole("button", { name: /Request a viewing or reserve/ })).toBeVisible();
  await shot(page, "SOQ-03", 2);
  expectClean(problems);
});

test("SOQ-04 ad detail of a shop ad: specs, location, seller card, similar", async ({ page, problems }) => {
  const { ads } = souq();
  await page.goto(`/uae-en/classified/find/${ads.shop}`);
  await settle(page);
  const main = page.locator("main");
  await expect(main.locator("h1")).toContainText("Galaxy S24 Ultra");
  await expect(main).toContainText(`Ad ID ${ads.shop}`);
  await expect(main).toContainText("E2E Souq Shop");
  await expect(main).toContainText("Deira");
  await expect(main.getByRole("link", { name: "Open in map" })).toHaveAttribute("href", /openstreetmap\.org\/\?mlat=25\.2697/);
  await expect(main.getByRole("link", { name: "Visit shop" })).toBeVisible();
  // Not a financed vertical (electronics): no financing card.
  await expect(main).not.toContainText("/ month");
  await shot(page, "SOQ-04", 1);
  expectClean(problems);
});

test("SOQ-05 real estate: list, map view, property detail with financing", async ({ page, problems }) => {
  const { ads } = souq();
  await page.goto("/uae-en/property");
  await settle(page);
  await expect(page.locator("main")).toContainText("Business Bay");
  await shot(page, "SOQ-05", 1);

  await page.goto("/uae-en/property?view=map");
  await settle(page, 12_000);
  await expect(page.locator(".leaflet-container, [data-testid='property-map']").first()).toBeVisible();
  await shot(page, "SOQ-05", 2);

  await page.goto(`/uae-en/classified/find/${ads.broker}`);
  await settle(page);
  const main = page.locator("main");
  await expect(main.locator("h1")).toContainText("2-bed in Business Bay");
  await expect(main).toContainText("RERA-E2E-1");
  // Financing card (property vertical): AED 120,000 → 20 % down, 60 months, 4.99 %.
  await expect(main).toContainText(/Est\. AED\s?1,811 \/ month/);
  await expect(main).toContainText("indicative only, not a finance offer");
  await expect(main.getByRole("link", { name: "View broker profile" })).toBeVisible();
  await shot(page, "SOQ-05", 3);

  await page.goto(`/uae-en/property/${ads.broker}`);
  await settle(page);
  await expect(page.locator("main")).toContainText("Business Bay");
  await shot(page, "SOQ-05", 4);
  expectClean(problems);
});

test("SOQ-06 shop page lists the shop's live ads", async ({ page, problems }) => {
  const { seller } = souq();
  await page.goto(`/uae-en/classified/shop/${seller.shop_slug}`);
  await settle(page);
  await expect(page.locator("main h1")).toContainText("E2E Souq Shop");
  await expect(page.locator("main")).toContainText("Galaxy S24 Ultra");
  await expect(page.locator("main")).toContainText(/\d+ live ads?/);
  await shot(page, "SOQ-06", 1);
  expectClean(problems);
});

test("SOQ-07 compare two ads of the same category", async ({ page, problems }) => {
  await page.goto("/uae-en/classified/find/CL-AE-DEMO1");
  await settle(page);
  await page.getByRole("button", { name: "Compare", exact: true }).first().click();
  await page.goto("/uae-en/classified/find/CL-AE-DEMO2");
  await settle(page);
  await page.getByRole("button", { name: "Compare", exact: true }).first().click();
  await shot(page, "SOQ-07", 1, false);
  await page.getByRole("link", { name: /Compare|compare/ }).filter({ hasText: /2/ }).first().click();
  await expect(page).toHaveURL(/classified\/compare/);
  await settle(page);
  await expect(page.locator("main")).toContainText("Land Cruiser");
  await expect(page.locator("main")).toContainText("Patrol");
  await shot(page, "SOQ-07", 2);
  expectClean(problems);
});

test("SOQ-08 Arabic Open Souq is RTL", async ({ page, problems }) => {
  const { ads } = souq();
  await page.goto("/uae-ar/classified");
  await settle(page);
  await expectLocale(page, "ar");
  await shot(page, "SOQ-08", 1);
  await page.goto(`/uae-ar/classified/find/${ads.broker}`);
  await settle(page);
  await expectLocale(page, "ar");
  await shot(page, "SOQ-08", 2);
  expectClean(problems);
});
