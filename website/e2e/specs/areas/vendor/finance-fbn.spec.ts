import { test, expect, expectClean } from "./support/test";
import { expectFlash, expectNoServerError, fetchIn, go, inDays, routePayPost, sc, shooter, signedInAs, tinkerIn, vendorFixtures } from "./support/vendor";

/**
 * Seller Center finance (earnings, statements, payouts, subscription paid
 * through the fake gateway), Fulfilled by Nawy and packaging supplies.
 * Report: vendor-panel-finance-fbn-and-settings (VEN-25…VEN-29).
 */
const shot = shooter("vendor-panel-finance-fbn-and-settings");

test("VEN-25 earnings, statements and payouts", async ({ page, problems }) => {
  await go(page, sc("earnings"));
  await expect(page.locator("[data-earnings-balances] [data-balance]").first()).toBeVisible();
  await shot(page, "VEN-25", 1);
  // Period filter.
  const period = page.locator("form[data-earnings-period]");
  await period.locator('[name="period"]').selectOption({ index: 1 }).catch(() => undefined);
  await period.locator('button[type="submit"]').click().catch(() => undefined);
  await expectNoServerError(page);
  await shot(page, "VEN-25", 2);
  // Statements & invoices is the payouts page.
  await go(page, sc("statements"));
  await expect(page).toHaveURL(/\/payouts/);
  await shot(page, "VEN-25", 3);
  const payout = page.locator('main [data-payout] a[href*="/payouts/"]').first();
  if (await payout.count()) {
    await payout.click();
    await expect(page).toHaveURL(/payouts\/\d+/);
    await expectNoServerError(page);
    await shot(page, "VEN-25", 4);
  } else test.info().annotations.push({ type: "payouts", description: "the e2e store has no payout yet" });
  expectClean(problems);
});

test("VEN-26 subscription: subscribe, card payment declined then captured, PDF, auto-renew, change plan, cancel and keep", async ({ browser, problems }) => {
  test.setTimeout(240_000);
  const fx = vendorFixtures();
  const { context, page } = await signedInAs(browser, fx.fresh.email);
  page.on("console", (m) => m.type() === "error" && problems.console.push(`${page.url()} :: ${m.text()}`));
  await page.goto(sc("subscription"));
  await shot(page, "VEN-26", 1);
  const plan = page.locator("form[data-plan]").first();
  await plan.locator('[name="payment_preference"]').selectOption("card");
  await plan.getByRole("button", { name: "Subscribe" }).click();
  await expect(page.locator("[data-subscription]")).toBeVisible();
  await shot(page, "VEN-26", 2);

  // The open invoice: PDF, then pay by card — declined first.
  const invoiceRow = page.locator("main tr").filter({ has: page.locator('form[action*="/subscription/invoices/"][action$="/pay"]') }).first();
  await expect(invoiceRow).toBeVisible();
  const pdfHref = await invoiceRow.getByRole("link", { name: "PDF" }).getAttribute("href");
  const pdf = await fetchIn(page, pdfHref!);
  expect(pdf.status).toBe(200);
  expect(pdf.head).toBe("%PDF-");
  const payCard = invoiceRow.locator('form[action$="/pay"]').filter({ hasNot: page.locator('input[value="payout"]') }).first();
  await routePayPost(page, /^\/subscription\/invoices\/\d+\/pay$/, "failed");
  // The return URL (?payment=) is read once, flashed and dropped (BT-VEN-009), so wait for the request, then the clean page.
  await Promise.all([page.waitForRequest(/\/subscription\?.*payment=/), payCard.locator("button").click()]);
  await expect(page).toHaveURL(/\/subscription$/);
  test.info().annotations.push({ type: "declined return", description: page.url() });
  await expect(page.locator("main tr").filter({ hasText: /Pending/i }).first()).toBeVisible();
  await expect(page.locator('[data-payment-result="danger"]')).toBeVisible(); // BT-VEN-005
  await shot(page, "VEN-26", 3);

  // Captured: the invoice is paid and the subscription active.
  await routePayPost(page, /^\/subscription\/invoices\/\d+\/pay$/, "captured");
  await Promise.all([
    page.waitForRequest(/\/subscription\?.*payment=.*status=captured/),
    page.locator("main tr").filter({ has: page.locator('form[action$="/pay"]') }).first().locator('form[action$="/pay"]').filter({ hasNot: page.locator('input[value="payout"]') }).first().locator("button").click(),
  ]);
  await expect(page.locator("main tr").filter({ hasText: /Paid/i }).first()).toBeVisible();
  await expect(page.locator("[data-subscription]")).toHaveAttribute("data-subscription", "active");
  await expect(page).toHaveURL(/\/subscription$/);
  await expect(page.locator('[data-payment-result="success"]')).toContainText("Invoice paid.");
  await shot(page, "VEN-26", 4);
  await page.unrouteAll({ behavior: "wait" });

  // Auto-renew with the saved card (the gateway kept a token on the paid invoice), then off again.
  const renew = page.locator("form[data-auto-renew]");
  if (await renew.locator('[name="saved_payment_card_id"]').count()) {
    await renew.getByRole("button", { name: "Turn on" }).click();
    await expect(page.locator("form[data-auto-renew]")).toContainText("On");
    await shot(page, "VEN-26", 5);
    await page.locator("form[data-auto-renew]").getByRole("button", { name: "Turn off" }).click();
    await expect(page.locator("form[data-auto-renew]")).toContainText("Off");
  } else test.info().annotations.push({ type: "auto-renew", description: "no saved card after the fake payment" });

  // Change plan from the next period.
  const change = page.locator('form[action$="/subscription/plan"]');
  const other = await change.locator('[name="plan_id"] option').evaluateAll((os, cur) => os.map((o) => (o as HTMLOptionElement).value).filter((v) => v && v !== cur), await change.locator('[name="plan_id"]').inputValue());
  await change.locator('[name="plan_id"]').selectOption(other[0]);
  await change.getByRole("button", { name: "Change" }).click();
  await expect(page.locator("main")).toContainText("From the next period");
  await expect(page.locator("[data-payment-result]")).toHaveCount(0); // BT-VEN-009: the gateway result showed once
  await shot(page, "VEN-26", 6);

  // Cancel at period end, then keep the plan.
  page.once("dialog", (d) => d.accept());
  await page.locator('form[action$="/subscription/cancel"] button').click();
  await expect(page.getByRole("button", { name: "Keep my plan" })).toBeVisible();
  await shot(page, "VEN-26", 7);
  await page.getByRole("button", { name: "Keep my plan" }).click();
  await expect(page.locator('form[action$="/subscription/cancel"]')).toBeVisible();
  await shot(page, "VEN-26", 8);
  await context.close();
  expectClean(problems);
});

test("VEN-27 Fulfilled by Nawy: enrol, fee preview (0), inbound plan draft → submit → labels → ship, warehouse receives, removal and cancel", async ({ page, problems }) => {
  test.setTimeout(180_000);
  const fx = vendorFixtures();
  await go(page, sc("inventory"));
  await shot(page, "VEN-27", 1);
  const enrol = page.locator('form[action$="/inventory/enrol"]');
  await enrol.locator('[name="listing_id"]').selectOption(String(fx.fbn_listing.id));
  await enrol.getByRole("button", { name: "Enrol in FBN" }).click();
  await expect(page.locator("main [data-fbn-listing]").filter({ hasText: fx.fbn_listing.sku })).toBeVisible();
  await shot(page, "VEN-27", 2);

  // Fee preview: fees are 0 by owner decision.
  await go(page, sc(`inventory/fees?listing_id=${fx.fbn_listing.id}`));
  await expect(page.locator("[data-fbn-preview]")).toBeVisible();
  await expect(page.locator("[data-outbound-fee]")).toHaveText(/^0(\.0+)? [A-Z]{3}$/);
  await shot(page, "VEN-27", 3);

  // Inbound plan: save a draft, then submit from its page.
  await go(page, sc("inventory/inbound/create"));
  const plan = page.locator("form[data-inbound-form]");
  await plan.locator('[name="warehouse_id"]').selectOption(String(fx.warehouse.id));
  await plan.locator('[name="expected_at"]').fill(inDays(4));
  await plan.locator('[name="notes"]').fill(`E2E inbound ${fx.run}`);
  await plan.locator('[name="lines[0][listing_id]"]').selectOption(String(fx.fbn_listing.id));
  await plan.locator('[name="lines[0][quantity]"]').fill("6");
  await plan.locator('[name="lines[0][cartons]"]').fill("2");
  await shot(page, "VEN-27", 4);
  await plan.getByRole("button", { name: "Save draft" }).click();
  await expect(page).toHaveURL(/inventory\/inbound\/\d+/);
  const inboundId = Number(/inbound\/(\d+)/.exec(page.url())![1]);
  await expect(page.locator("main")).toContainText(fx.fbn_listing.sku);
  await page.getByRole("button", { name: "Submit plan" }).click();
  await expect(page.locator('form[action$="/ship"]')).toBeVisible();
  await shot(page, "VEN-27", 5);

  // Carton labels: HTML and PDF.
  const labels = await fetchIn(page, sc(`inventory/inbound/${inboundId}/labels`));
  expect(labels.status).toBe(200);
  const labelsPdf = await fetchIn(page, sc(`inventory/inbound/${inboundId}/labels.pdf`));
  expect(labelsPdf.status).toBe(200);
  expect(labelsPdf.head).toBe("%PDF-");
  await page.goto(sc(`inventory/inbound/${inboundId}/labels`));
  await shot(page, "VEN-27", 6);
  await go(page, sc(`inventory/inbound/${inboundId}`));

  const ship = page.locator('form[action$="/ship"]');
  await ship.locator('[name="carrier_name"]').fill("E2E Freight");
  await ship.locator('[name="tracking_number"]').fill(`TRK-${fx.run}`);
  await ship.getByRole("button", { name: "Mark as shipped" }).click();
  await expect(page.locator("main")).toContainText(/In transit/i);
  await shot(page, "VEN-27", 7);

  // The warehouse receives the 6 units (warehouse staff step, run here as the service does it).
  tinkerIn("uae", `$m = app(App\\Domains\\Fulfilment\\Support\\InboundShipments::class); $s = App\\Domains\\Fulfilment\\Models\\InboundShipment::query()->findOrFail(${inboundId}); $m->markArrived($s, 1); $m->startReceiving($s->refresh(), 1); foreach ($s->refresh()->lines as $l) { $m->receiveLine($l, (int) $l->quantity_expected, 0, 'E2E-BIN', 1); } $m->finishReceiving($s->refresh(), 1); echo 'ok';`);
  await go(page, sc("inventory/inbound"));
  await expect(page.locator(`main [data-inbound]`).first()).toBeVisible();
  await shot(page, "VEN-27", 8);
  await go(page, sc("inventory"));
  await expect(page.locator("main [data-fbn-listing]").filter({ hasText: fx.fbn_listing.sku })).toContainText("6");

  // Removal: return 1 unit of the run's listing to the seller (other listings untouched), then cancel it.
  await go(page, sc("inventory/removals"));
  const removal = page.locator("form[data-removal-form]");
  await removal.locator('[name="warehouse_id"]').selectOption(String(fx.warehouse.id));
  await removal.locator('[name="type"]').selectOption("return_to_seller");
  await removal.locator('[name="reason"]').fill("Browser test: sample for a photo shoot");
  await removal.locator('[name="address[name]"]').fill("E2E Seller");
  await removal.locator('[name="address[phone]"]').fill("+971500000099");
  await removal.locator('[name="address[city]"]').fill("Dubai");
  await removal.locator('[name="address[street]"]').fill("E2E Street 9");
  const row = removal.locator("tr").filter({ hasText: fx.fbn_listing.sku });
  await row.locator('input[name$="[quantity]"]').fill("1");
  await shot(page, "VEN-27", 9);
  await removal.locator('button[type="submit"]').last().click();
  const created = page.locator("main [data-removal]").first();
  await expect(created).toBeVisible();
  await shot(page, "VEN-27", 10);
  await created.locator('form[action$="/cancel"] button').click();
  await expect(page.locator("main [data-removal]").first()).toContainText(/Cancelled/i);
  await shot(page, "VEN-27", 11);
  expectClean(problems);
});

test("VEN-28 packaging supplies: order with delivery address", async ({ page, problems }) => {
  const { run } = vendorFixtures();
  await go(page, sc("packaging"));
  await shot(page, "VEN-28", 1);
  const form = page.locator("form[data-packaging-form]");
  const supply = form.locator("tr[data-supply]").first();
  test.skip(!(await supply.count()), "no packaging offered in uae");
  const limits = (await supply.locator("td").nth(2).innerText()).trim();
  const min = Math.max(1, Number(/^(\d+)/.exec(limits)?.[1] ?? 1));
  await supply.locator('input[name^="quantities["]').fill(String(min));
  await form.locator('[name="address[street]"]').fill("E2E Street 5");
  await form.locator('[name="address[area]"]').fill("Al Quoz");
  await form.locator('[name="address[city]"]').fill("Dubai");
  await form.locator('[name="address[phone]"]').fill("+971500000099");
  await form.locator('[name="notes"]').fill(`Browser test ${run}`);
  await form.getByRole("button", { name: "Place order" }).click();
  await expect(page).toHaveURL(/packaging\/\d+/);
  await expectFlash(page, /Packaging order .* placed/);
  await shot(page, "VEN-28", 2);
  await go(page, sc("packaging"));
  await expect(page.locator("main [data-packaging-order]").first()).toBeVisible();
  expectClean(problems);
});
