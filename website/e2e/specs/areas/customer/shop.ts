import type { Page } from "@playwright/test";
import { fixtures } from "./data";
import { expect, main, MARKET, settle, tinkerPhp, visit } from "./kit";

/**
 * Shopping helpers shared by the checkout and order-lifecycle specs. The
 * e2e store sells nova-x12-smartphone twice: listing 19 Fulfilled by Nawy
 * (AED 95, the buy box) and listing 16 shipped by the seller (AED 120).
 */
export type Offer = "fbn" | "fbm";

/** Adds the e2e store's offer to the cart from the product page (choosing the seller-fulfilled offer in "other offers"). */
export async function addOffer(page: Page, offer: Offer, qty = 1) {
  await visit(page, `/products/${fixtures().product_slug}`);
  if (offer === "fbm") {
    await main(page).getByRole("button", { name: /other offers/ }).first().click();
    const row = main(page).locator("li").filter({ hasText: "E2E Store" }).filter({ hasText: "120.00" }).first();
    await row.getByRole("button", { name: "Choose" }).click();
    await expect(main(page).getByText("120.00").first()).toBeVisible();
  }
  for (let i = 1; i < qty; i++) await main(page).getByRole("button", { name: "Increase quantity" }).first().click();
  await main(page).getByRole("button", { name: "Add to cart" }).first().click();
  await expect(page.getByText(/Added to cart|View cart/).first()).toBeVisible();
}

/**
 * Stands in for the fake gateway's hosted page (https://pay.fake.test/{method}/fake_{method}_{ref}):
 * scripts the outcome with FakeGateway::willReport (and, optionally, a tokenised card),
 * then sends the browser to Laravel's return URL.
 */
export async function routeGateway(page: Page, outcome: "captured" | "failed" | "pending" | "under_review", opts: { tokenise?: { token: string; last4: string; brand: string } } = {}) {
  const seen: string[] = [];
  await page.unroute("https://pay.fake.test/**").catch(() => undefined);
  await page.route("https://pay.fake.test/**", async (route) => {
    const [, method, gatewayReference] = new URL(route.request().url()).pathname.split("/");
    const reference = gatewayReference.replace(`fake_${method}_`, "");
    if (!/^[A-Za-z0-9_-]+$/.test(gatewayReference)) throw new Error("bad reference");
    seen.push(gatewayReference);
    const card = opts.tokenise ? `App\\Domains\\Payments\\Support\\Gateways\\FakeGateway::willTokenise("${gatewayReference}", ["token" => "${opts.tokenise.token}", "last4" => "${opts.tokenise.last4}", "brand" => "${opts.tokenise.brand}", "exp_month" => 12, "exp_year" => 2030]);` : "";
    tinkerPhp(`App\\Domains\\Payments\\Support\\Gateways\\FakeGateway::willReport("${gatewayReference}", App\\Domains\\Payments\\Enums\\PaymentStatus::from("${outcome}")); ${card} echo "ok";`);
    await route.fulfill({ status: 302, headers: { location: `${process.env.E2E_API_URL ?? "http://localhost:8000"}/api/v1/${MARKET}/payments/${reference}/return` } });
  });
  return seen;
}

/** Re-scripts the outcome of a gateway reference seen earlier (pending → captured). */
export function gatewayNowReports(gatewayReference: string, outcome: "captured" | "failed") {
  tinkerPhp(`App\\Domains\\Payments\\Support\\Gateways\\FakeGateway::willReport("${gatewayReference}", App\\Domains\\Payments\\Enums\\PaymentStatus::from("${outcome}")); echo "ok";`);
}

/** Opens checkout and fills a new delivery address when the shopper has none saved. */
export async function checkoutAddress(page: Page) {
  await visit(page, "/checkout");
  const name = page.getByLabel("Full name");
  // The address step renders after the saved addresses load: wait for the new-address form or a saved address.
  await expect(name.or(page.locator('input[type="radio"][name="address"]')).first()).toBeVisible();
  if (await name.isVisible().catch(() => false)) {
    await name.fill("QA Customer");
    await page.getByLabel("Mobile number").fill("+971501234567");
    await page.getByLabel("City").fill("Dubai");
    await page.getByLabel("Area").fill("Marina");
    await page.getByLabel("Street and house / flat").fill("QA Tower, flat 7");
  }
}

/** Picks a payment method radio by its visible name ("Credit / debit card", "Cash on delivery", "Nawy Credits"…). */
export async function choosePayment(page: Page, name: RegExp) {
  const option = page.locator("label").filter({ has: page.locator('input[type="radio"][name="payment"]') }).filter({ hasText: name }).first();
  await option.locator("input").check();
}

/** Continue to review → Place order; returns the order number once the browser is on the payment / success page. */
export async function placeOrder(page: Page, opts: { doubleClick?: boolean } = {}) {
  await page.getByRole("button", { name: "Continue to review" }).click();
  const place = page.getByRole("button", { name: /Place order/ });
  await expect(place).toBeVisible();
  if (opts.doubleClick) await place.dblclick();
  else await place.click();
  await expect(page).toHaveURL(/\/checkout\/(payment|success)/, { timeout: 45_000 });
  await settle(page);
  const url = new URL(page.url());
  const fromUrl = url.searchParams.get("order");
  const fromText = /NW-AE-[A-Z0-9-]+/.exec(await main(page).innerText().catch(() => ""))?.[0];
  return fromUrl ?? fromText ?? "";
}
