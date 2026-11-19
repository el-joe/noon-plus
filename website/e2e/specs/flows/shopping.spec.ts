import { readFileSync } from "node:fs";
import path from "node:path";
import { test, expect, expectClean, shoot, settle } from "../../support/fixtures";
import { CUSTOMER, CUSTOMER_STATE } from "../../support/accounts";
import { GENERATED } from "../../support/global-setup";
import type { Fixtures } from "../../support/routes";
import { formOf, rejectOptionalCookies, routeFakeGateway, signInWithPassword } from "../../support/storefront";

const fixtures = (): Fixtures & { delivered_sub_order: { id: number; number: string; order: string } } =>
  JSON.parse(readFileSync(path.join(GENERATED, "fixtures.json"), "utf8"));

test.beforeEach(async ({ page }) => rejectOptionalCookies(page));

/**
 * Browse → PLP filters → PDP → add to cart as a guest → sign in (the guest cart
 * merges) → checkout with the fake card gateway → payment return → order
 * detail, then cancel that shipment with a reason.
 */
test("browse, filter, buy as a guest, merge on sign-in, pay, cancel", async ({ page, problems }) => {
  test.setTimeout(180_000);
  await page.goto("/uae-en");
  await settle(page);
  await page.getByRole("link", { name: "Electronics", exact: true }).first().click();
  await expect(page).toHaveURL(/\/uae-en\/electronics/);
  await settle(page);

  // PLP filter: "Shipped by seller" (fulfilment facet), applied with the filter form.
  const filters = page.locator("form").filter({ has: page.getByRole("button", { name: "Apply" }) }).first();
  await filters.getByLabel(/Shipped by seller/).first().check();
  await filters.getByRole("button", { name: "Apply" }).click();
  await expect(page).toHaveURL(/fulfil/);
  await settle(page);
  await shoot(page, "flows", "shop-01-plp-filtered");

  await page.locator('main a[href*="/products/"]').first().click();
  await expect(page).toHaveURL(/\/uae-en\/products\//);
  await settle(page);
  await shoot(page, "flows", "shop-02-pdp");
  await page.getByRole("button", { name: "Add to cart" }).first().click();
  await expect(page.getByText(/Added to cart|View cart/).first()).toBeVisible();

  await page.goto("/uae-en/cart");
  await settle(page);
  const title = (await page.locator('main a[href*="/products/"]').filter({ hasText: /\S/ }).first().innerText()).trim();
  expect(title.length).toBeGreaterThan(0);
  await shoot(page, "flows", "shop-03-guest-cart");

  // Sign in from checkout; the guest cart comes along.
  await page.getByRole("link", { name: "Checkout" }).or(page.getByRole("button", { name: "Checkout" })).first().click();
  await expect(page).toHaveURL(/\/checkout|\/login/);
  await signInWithPassword(page, "uae-en", CUSTOMER.email, CUSTOMER.password, "/uae-en/checkout");
  await expect(page).toHaveURL(/\/uae-en\/checkout/);
  await settle(page);
  // The merged guest line is in the checkout summary (thumbnails carry the title).
  await expect(page.locator(`main [title="${title}"]`).first()).toBeAttached();

  // Address: a saved one when present, otherwise a new one.
  const name = page.getByLabel("Full name");
  if (await name.isVisible().catch(() => false)) {
    await name.fill("E2E Customer");
    await page.getByLabel("Mobile number").fill("+971501234567");
    await page.getByLabel("City").fill("Dubai");
    await page.getByLabel("Area").fill("Marina");
    await page.getByLabel("Street and house / flat").fill("E2E Tower, flat 12");
  }
  await page.locator('input[type="radio"][name="payment"]').locator("xpath=..").filter({ hasText: "Credit / debit card" }).locator("input").check();
  await page.getByRole("button", { name: "Continue to review" }).click();
  await shoot(page, "flows", "shop-04-checkout-review");

  await routeFakeGateway(page, "uae-en", "captured");
  await page.getByRole("button", { name: /Place order/ }).click();
  await expect(page).toHaveURL(/\/uae-en\/checkout\/(payment|success)/, { timeout: 45_000 });
  await expect(page.locator("main")).toContainText(/Thank you! Your order is placed|Payment received/, { timeout: 30_000 });
  await shoot(page, "flows", "shop-05-payment-return");
  const orderNumber = /NW-AE-\d+/.exec(await page.locator("main").innerText())![0];

  await page.goto(`/uae-en/account/orders/${orderNumber}`);
  await settle(page);
  await expect(page.locator("main")).toContainText(orderNumber);
  await shoot(page, "flows", "shop-06-order-detail");

  // Cancel the shipment with a reason.
  await page.getByRole("button", { name: "Cancel items" }).first().click();
  const dialog = page.getByRole("dialog");
  await dialog.locator('input[type="radio"][name="reason"]').first().check();
  await dialog.getByRole("button", { name: "Cancel shipment" }).click();
  await expect(dialog).toBeHidden();
  await expect(page.locator("main")).toContainText(/Cancelled/);
  await shoot(page, "flows", "shop-07-cancelled");
  expectClean(problems);
});

test.describe("signed-in account flows", () => {
  test.use({ storageState: CUSTOMER_STATE });

  test("order detail shows the delivered shipment; request a return", async ({ page, problems }) => {
    const { delivered_sub_order: so } = fixtures();
    await page.goto(`/uae-en/account/orders/${so.order}`);
    await settle(page);
    await page.getByRole("link", { name: "Return item" }).first().click();
    await expect(page).toHaveURL(/\/account\/returns\/new/);
    await settle(page);
    await page.locator('select[aria-label="Quantity"]').first().selectOption("1");
    await page.locator('select[name="reason"]').selectOption("changed_mind");
    await page.locator('select[name="refund_destination"]').selectOption("wallet");
    await page.getByRole("button", { name: "Submit return" }).click();
    await expect(page).toHaveURL(/\/account\/returns$/);
    await expect(page.locator("main")).toContainText(/RT-/);
    await shoot(page, "flows", "account-return-requested");
    expectClean(problems);
  });

  test("write a review for a delivered item", async ({ page, problems }) => {
    await page.goto("/uae-en/account/reviews");
    await settle(page);
    await page.getByRole("button", { name: "Write review" }).first().click();
    const dialog = page.getByRole("dialog");
    await dialog.getByRole("radio", { name: "Excellent" }).click();
    await dialog.locator('input[name="title"]').fill("E2E review");
    await dialog.locator('textarea[name="body"]').fill("Arrived quickly and works as described.");
    await dialog.getByRole("button", { name: "Submit review" }).click();
    await expect(dialog).toContainText("Thanks! Your review will appear once it has been checked.");
    await shoot(page, "flows", "account-review-submitted");
    expectClean(problems);
  });

  test("wishlist add and remove", async ({ page, problems }) => {
    const slug = JSON.parse(await (await page.request.get("http://localhost:8000/api/v1/uae-en/products?per_page=1")).text()).data[0].slug as string;
    await page.goto(`/uae-en/products/${slug}`);
    await settle(page);
    const add = page.getByRole("button", { name: "Add to wishlist" }).first();
    if (await add.isVisible().catch(() => false)) await add.click();
    await expect(page.getByRole("button", { name: "Remove from wishlist" }).first()).toBeVisible();
    await page.goto("/uae-en/account/wishlist");
    await settle(page);
    await expect(page.locator(`main a[href*="/products/${slug}"]`).first()).toBeVisible();
    await shoot(page, "flows", "account-wishlist");
    await page.getByRole("button", { name: "Remove from wishlist" }).first().click();
    await expect(page.locator(`main a[href*="/products/${slug}"]`)).toHaveCount(0);
    expectClean(problems);
  });

  test("address book: add, make default, delete", async ({ page, problems }) => {
    await page.goto("/uae-en/account/addresses");
    await settle(page);
    await page.getByRole("button", { name: "Add address" }).first().click();
    const form = formOf(page, 'input[name="street"]');
    const street = `E2E Street ${Date.now()}`;
    await form.locator('input[name="name"]').fill("E2E Address");
    await form.locator('input[name="phone"]').fill("+971501112233");
    if (await form.locator('input[name="city_free"]').count()) await form.locator('input[name="city_free"]').fill("Dubai");
    else await form.locator("select").first().selectOption({ index: 1 });
    const area = form.locator('[name="area"]');
    if ((await area.evaluate((el) => el.tagName)) === "SELECT") await area.selectOption({ index: 1 });
    else await area.fill("Marina");
    await form.locator('input[name="street"]').fill(street);
    await form.locator('button[type="submit"]').click();
    await expect(page.locator("main")).toContainText(street);
    await shoot(page, "flows", "account-address-added");
    const card = page.locator(`xpath=//main//*[contains(text(), "${street}")]/ancestor::*[.//button[contains(., "Delete")]][1]`);
    const makeDefault = card.getByRole("button", { name: "Set as default" });
    if (await makeDefault.count()) {
      await makeDefault.click();
      await expect(card).toContainText("Default");
    }
    page.once("dialog", (d) => d.accept());
    await card.getByRole("button", { name: "Delete" }).click();
    await expect(page.locator("main")).not.toContainText(street);
    expectClean(problems);
  });
});
