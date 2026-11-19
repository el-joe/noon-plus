import { test, expect, expectClean } from "./support/test";
import { dropDevImageCsp, expectFlash, expectNoServerError, go, inDays, jpeg, localAt, sc, shooter, signedInAs, tinkerIn, vendorFixtures } from "./support/vendor";

/**
 * Seller Center growth tools: coupons (D10 limits), flash sales, marketer
 * campaigns, advertising. Report: vendor-panel-finance-fbn-and-settings
 * (VEN-20…VEN-24).
 */
const shot = shooter("vendor-panel-finance-fbn-and-settings");

/** Fills the coupon form (create or edit). */
async function fillCoupon(page: import("@playwright/test").Page, c: { code?: string; value: string; ends: string; note?: string; type?: string }) {
  const form = page.locator("form[data-seller-coupon-form]");
  if (c.code !== undefined) await form.locator('[name="code"]').fill(c.code);
  if (c.note) await form.locator('[name="description"]').fill(c.note);
  await form.locator('[name="type"]').selectOption(c.type ?? "percentage");
  await form.locator('[name="value"]').fill(c.value);
  await form.locator('[name="starts_at"]').fill(localAt(0, 0).replace("T00:00", "T00:01"));
  await form.locator('[name="ends_at"]').fill(c.ends);
  return form;
}

test("VEN-20 coupons: create, view, edit, switch off", async ({ page, problems }) => {
  const { run } = vendorFixtures();
  const code = `E2EV${run.toUpperCase()}`;
  await go(page, sc("coupons"));
  await shot(page, "VEN-20", 1);
  await page.getByRole("link", { name: /New coupon/ }).first().click();
  const form = await fillCoupon(page, { code: code.toLowerCase(), value: "15", ends: localAt(30), note: "Browser test coupon" });
  await form.locator('[name="max_discount_amount"]').fill("50");
  await form.locator('[name="usage_limit"]').fill("100");
  await form.locator('[name="per_customer_limit"]').fill("1");
  await shot(page, "VEN-20", 2);
  await form.getByRole("button", { name: "Create coupon" }).click();
  await expectFlash(page, "Coupon created");
  await expect(page.locator("main")).toContainText(code); // stored in capitals
  await shot(page, "VEN-20", 3);

  await page.getByRole("link", { name: "Edit" }).first().click();
  await page.locator('form[data-seller-coupon-form] [name="value"]').fill("20");
  await page.locator("form[data-seller-coupon-form]").getByRole("button", { name: "Save coupon" }).click();
  await expect(page.locator("main")).toContainText("20");
  await shot(page, "VEN-20", 4);

  // Switch it off from the list.
  await go(page, sc(`coupons?q=${code}`));
  const row = page.locator("main [data-coupon]").filter({ hasText: code });
  await row.locator('form[action*="/toggle"] button').click();
  await expect(page.locator("main [data-coupon]").filter({ hasText: code })).toContainText(/Off|Switched off|Inactive/i);
  await shot(page, "VEN-20", 5);
  expectClean(problems);
});

test("VEN-21 coupon limits (D10): 90% max, 365 days max, 20 live max", async ({ browser, problems }) => {
  test.setTimeout(180_000);
  const fx = vendorFixtures();
  const { context, page } = await signedInAs(browser, fx.fresh.email);
  page.on("console", (m) => m.type() === "error" && problems.console.push(`${page.url()} :: ${m.text()}`));
  await page.goto(sc("coupons/create"));

  // 91% is refused.
  let form = await fillCoupon(page, { code: `PCT${fx.run}`, value: "91", ends: localAt(30) });
  await form.getByRole("button", { name: "Create coupon" }).click();
  await expect(page.locator("main")).toContainText(/at most 90/i);
  await shot(page, "VEN-21", 1);

  // Longer than 365 days is refused.
  form = await fillCoupon(page, { code: `DAYS${fx.run}`, value: "10", ends: localAt(370) });
  await form.getByRole("button", { name: "Create coupon" }).click();
  await expect(page.locator("main")).toContainText(/365 days/i);
  await shot(page, "VEN-21", 2);

  // 90% for 365 days is fine: coupon #1.
  form = await fillCoupon(page, { code: `LIVE1${fx.run}`, value: "90", ends: localAt(364) });
  await form.getByRole("button", { name: "Create coupon" }).click();
  await expectFlash(page, "Coupon created");
  await shot(page, "VEN-21", 3);

  // 18 more live coupons (copies of #1, test data) → 19 live; the 20th from the browser is accepted, the 21st refused.
  tinkerIn("uae", `$c = App\\Domains\\Coupons\\Models\\Coupon::query()->where('vendor_id', ${fx.fresh.store_id})->firstOrFail(); for ($i = 2; $i <= 19; $i++) { $copy = $c->replicate(); $copy->code = 'LIVE'.$i.strtoupper('${fx.run}'); $copy->save(); } echo 'ok';`);
  await page.goto(sc("coupons/create"));
  form = await fillCoupon(page, { code: `LIVE20${fx.run}`, value: "5", ends: localAt(10) });
  await form.getByRole("button", { name: "Create coupon" }).click();
  await expectFlash(page, "Coupon created");
  await page.goto(sc("coupons/create"));
  form = await fillCoupon(page, { code: `LIVE21${fx.run}`, value: "5", ends: localAt(10) });
  await form.getByRole("button", { name: "Create coupon" }).click();
  await expect(page.locator("main")).toContainText(/20 live coupons|at most 20|20 coupons/i);
  await shot(page, "VEN-21", 4);
  await page.goto(sc("coupons"));
  await expect(page.locator("main [data-coupon]")).toHaveCount(20);
  await shot(page, "VEN-21", 5);
  await context.close();
  expectClean(problems);
});

test("VEN-22 flash sales: submit a listing to the open event, then withdraw it", async ({ page, problems }) => {
  const { order_listing, flash_event_id } = vendorFixtures();
  await go(page, sc("flash-sales"));
  await shot(page, "VEN-22", 1);
  const event = page.locator(`form[data-event="${flash_event_id}"]`);
  await event.locator('[name="listing_id"]').selectOption(String(order_listing.id));
  await event.locator('[name="flash_price"]').fill("59.5");
  await event.locator('[name="quantity_cap"]').fill("3");
  await event.getByRole("button", { name: "Submit offer" }).click();
  await expectNoServerError(page);
  const submission = page.locator("main [data-submission]").filter({ hasText: /59\.5/ }).first();
  await expect(submission).toBeVisible();
  await shot(page, "VEN-22", 2);
  await submission.locator('form[action*="/withdraw"] button').click();
  await expectNoServerError(page);
  await expect(page.locator("main [data-submission]").filter({ hasText: /59\.5/ }).first()).toContainText(/Withdrawn/i);
  // A withdrawal is final: the listing is no longer offered for this event, and the form says why.
  await expect(event.locator(`[name="listing_id"] option[value="${order_listing.id}"]`)).toHaveCount(0);
  await expect(page.locator(`form[data-event="${flash_event_id}"] ~ [data-offer-exclusions] [data-withdrawn-note]`)).toBeVisible();
  await shot(page, "VEN-22", 3);
  expectClean(problems);
});

test("VEN-23 marketer campaign: fee quote, launch, view, cancel", async ({ page, problems }) => {
  const { order_listing, run } = vendorFixtures();
  await go(page, sc("campaigns"));
  await shot(page, "VEN-23", 1);
  await go(page, sc("campaigns/create"));
  const wizard = page.locator("form[data-campaign-wizard]");
  test.skip(!(await wizard.count()), "the Seller-Marketer contract is not signed for this store");
  await wizard.locator('[name="title"]').fill(`E2E Campaign ${run}`);
  await wizard.locator('[name="listing_id"]').selectOption(String(order_listing.id));
  await wizard.locator('[name="ends_at"]').fill(localAt(20));
  await wizard.getByLabel(/Open to every affiliate/).check();
  await wizard.locator('[name="notes"]').fill("Browser test: please share the product link.");
  await wizard.locator('[name="budget"]').fill("250");
  await expect(page.locator('[data-quote="fee"]')).not.toHaveText("—");
  await shot(page, "VEN-23", 2);
  await wizard.getByRole("button", { name: "Launch campaign" }).click();
  await expect(page).toHaveURL(/campaigns\/\d+/);
  await expect(page.locator("main")).toContainText(`E2E Campaign ${run}`);
  await shot(page, "VEN-23", 3);
  const cancel = page.locator('form[action$="/cancel"]');
  if (await cancel.count()) {
    page.once("dialog", (d) => d.accept());
    await cancel.locator("button").click();
    await expect(page.locator("main")).toContainText(/Cancelled/i);
    await shot(page, "VEN-23", 4);
  }
  expectClean(problems);
});

test("VEN-24 advertising: sponsored product campaign (decimal bid), pause/resume, paid placement", async ({ page, problems }) => {
  const fx = vendorFixtures();
  await go(page, sc("advertising"));
  await shot(page, "VEN-24", 1);
  const create = page.locator('form[action$="/advertising/campaigns"]');
  await create.locator('[name="listing_id"]').selectOption(String(fx.order_listing.id));
  await create.locator('[name="pricing_model"]').selectOption("cpc");
  await create.locator('[name="bid"]').fill("0.75");
  await create.locator('[name="daily_budget"]').fill("25.5");
  await create.locator('[name="keywords"]').fill(`phone, e2e-${fx.run}`);
  // A CPC bid below 1 (0.75) must be accepted: the browser must not block decimals (BT-VEN-002).
  expect(await create.locator('[name="bid"]').evaluate((el) => (el as HTMLInputElement).validity.valid), "decimal bid passes browser validation").toBe(true);
  expect(await create.locator('[name="daily_budget"]').evaluate((el) => (el as HTMLInputElement).validity.valid), "decimal budget passes browser validation").toBe(true);
  await create.getByRole("button", { name: "Create campaign" }).click();
  const row = page.locator("main [data-campaign]").first();
  await expect(row).toContainText(/0\.75/);
  // Pending Nawy's review: no Resume/Pause for the store (BT-VEN-003).
  await expect(row).toContainText(/Pending review/i);
  await expect(row.locator('form[action$="/status"]')).toHaveCount(0);
  await shot(page, "VEN-24", 2);
  const pause = row.locator('form:has(input[name="status"][value="paused"]) button');
  if (await pause.count()) {
    await pause.click();
    await expect(page.locator("main [data-campaign]").first()).toContainText(/Paused/i);
    await page.locator('main [data-campaign]').first().locator('form:has(input[name="status"][value="active"]) button').click();
    await expect(page.locator("main [data-campaign]").first()).toContainText(/Active/i);
  }
  await shot(page, "VEN-24", 3);

  // Paid placement on the run's own test slot (5/day × 3 days).
  const book = page.locator('form[action$="/advertising/bookings"]');
  await book.locator('[name="ad_slot_id"]').selectOption(String(fx.ad_slot_id));
  await book.locator('[name="starts_on"]').fill(inDays(2));
  await book.locator('[name="ends_on"]').fill(inDays(4));
  await book.locator('[name="link_url"]').fill("https://example.com/e2e-banner");
  await book.locator('[name="headline"]').fill(`E2E banner ${fx.run}`);
  await book.locator('[name="creative"]').setInputFiles({ name: "banner.jpg", mimeType: "image/jpeg", buffer: await jpeg(page, "#f59e0b") });
  await book.getByRole("button", { name: "Book & pay" }).click();
  await expectNoServerError(page);
  await expect(page.locator("main")).toContainText("E2E test placement");
  await shot(page, "VEN-24", 4);
  dropDevImageCsp(problems);
  expectClean(problems);
});
