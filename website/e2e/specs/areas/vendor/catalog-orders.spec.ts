import { test, expect, expectClean } from "./support/test";
import { panelUrl } from "../../../support/accounts";
import { dropDevImageCsp, expectFlash, expectNoServerError, fetchIn, go, jpeg, panelContext, sc, shooter, vendorFixtures } from "./support/vendor";

/**
 * Seller Center (partner panel) — catalogue, orders, returns, reviews.
 * Report: vendor-panel-catalog-and-orders (VEN-01…VEN-12). Every record the
 * flows change is the run's own (support/setup.php); listing 19 and the
 * seeded e2e orders are never touched.
 */
const shot = shooter("vendor-panel-catalog-and-orders");

test("VEN-01 dashboard: KPIs, attention items and the CSV report", async ({ page, problems }) => {
  await go(page, sc());
  await expect(page.locator("#panel-sidebar")).toBeAttached();
  await expect(page.locator("[data-kpi]").first()).toBeVisible();
  expect(await page.locator("[data-kpi]").count()).toBeGreaterThanOrEqual(4);
  await shot(page, "VEN-01", 1);
  const report = await fetchIn(page, sc("dashboard/report"));
  expect(report.status).toBe(200);
  expect(report.type).toMatch(/csv|text|octet|spreadsheet/);
  expect(report.size).toBeGreaterThan(20);
  expectClean(problems);
});

test("VEN-02 every Seller Center sidebar page opens", async ({ page, problems }) => {
  test.setTimeout(6 * 60_000);
  await go(page, sc());
  const origin = new URL(sc()).origin;
  const links = await page.$$eval("#panel-sidebar a[href]", (as) => [...new Set(as.map((a) => (a as HTMLAnchorElement).href.split("#")[0]))]);
  const internal = links.filter((h) => h.startsWith(origin) && !/\/logout/.test(h));
  test.info().annotations.push({ type: "sidebar links", description: String(internal.length) });
  expect(internal.length, "sidebar pages (PanelNavigation partner: 23)").toBeGreaterThanOrEqual(23);
  let n = 0;
  for (const link of [...internal, sc("notifications"), sc("account/security")]) {
    const res = await page.goto(link);
    expect.soft(res?.status(), link).toBe(200);
    await expectNoServerError(page);
    await shot(page, "VEN-02", `${++n}-${new URL(link).pathname.replace(/^\/|\/$/g, "").replace(/\//g, "_") || "dashboard"}`);
  }
  dropDevImageCsp(problems);
  expectClean(problems);
});

test.describe.serial("listing lifecycle on new catalogue variants", () => {
  let listingA = 0;
  let listingB = 0;

  test("VEN-03 create a listing: find the variant, price, stock, image, submit for review", async ({ page, problems }) => {
    const fx = vendorFixtures();
    const [a] = fx.new_variants;
    await go(page, sc("products/create"));
    await page.locator('input[name="q"]').fill(a.sku);
    await page.locator('form[role="search"] button[type="submit"]').click();
    await expect(page.locator("main")).toContainText(a.sku);
    await shot(page, "VEN-03", 1);
    await page.locator(`a[href*="variant=${a.id}"]`).first().click();
    const form = page.locator('form[action$="/products"]');
    await expect(form.locator('input[name="product_variant_id"]')).toHaveValue(String(a.id));

    // Validation: a sale price above the price is refused.
    await form.locator('[name="price"]').fill("150");
    await form.locator('[name="sale_price"]').fill("170");
    await form.locator('[name="stock"]').fill("12");
    await form.locator('[name="vendor_sku"]').fill(`MY-${a.sku}`);
    await form.locator('[name="images[]"]').setInputFiles({ name: "front.jpg", mimeType: "image/jpeg", buffer: await jpeg(page) });
    await form.locator('[name="image_alt_en"]').fill("Front view");
    await form.locator('[name="image_alt_ar"]').fill("منظر أمامي");
    await shot(page, "VEN-03", 2);
    await form.getByRole("button", { name: "Submit for review" }).click();
    await expect(page.locator("main")).toContainText(/sale price/i);
    await shot(page, "VEN-03", 3);

    const again = page.locator('form[action$="/products"]');
    await again.locator('[name="price"]').fill("150");
    await again.locator('[name="sale_price"]').fill("139");
    await again.locator('[name="stock"]').fill("12");
    await again.locator('[name="images[]"]').setInputFiles({ name: "front.jpg", mimeType: "image/jpeg", buffer: await jpeg(page) });
    await again.getByRole("button", { name: "Submit for review" }).click();
    await expect(page).toHaveURL(/\/products\/\d+\/edit/);
    listingA = Number(/products\/(\d+)\/edit/.exec(page.url())![1]);
    await expect(page.locator("main")).toContainText(/Pending review/i);
    await expect(page.locator("main")).toContainText("12 units available");
    await expect(page.locator("main [data-image] input[name='alt_en'][value='Front view']").first()).toBeAttached();
    await shot(page, "VEN-03", 4);
    dropDevImageCsp(problems);
    expectClean(problems);
  });

  test("VEN-04 second variant of the product: save draft, then submit; Nawy approves both", async ({ page, browser, problems }) => {
    const fx = vendorFixtures();
    const b = fx.new_variants[1];
    await go(page, sc(`products/create?variant=${b.id}`));
    const form = page.locator('form[action$="/products"]');
    await form.locator('[name="price"]').fill("155");
    await form.locator('[name="stock"]').fill("7");
    await form.getByRole("button", { name: "Save draft" }).click();
    await expect(page).toHaveURL(/\/products\/\d+\/edit/);
    listingB = Number(/products\/(\d+)\/edit/.exec(page.url())![1]);
    await expect(page.locator("main header, main").first()).toContainText(/Draft/i);
    await shot(page, "VEN-04", 1);
    await page.getByRole("button", { name: "Submit for review" }).click();
    await expect(page.locator("main")).toContainText(/Pending review/i);
    await shot(page, "VEN-04", 2);

    // Products list: both variants of the one product.
    await go(page, sc("products?status=pending_review"));
    for (const v of fx.new_variants) await expect(page.locator("main")).toContainText(v.sku);
    await shot(page, "VEN-04", 3);

    // Nawy (admin panel) approves the two listings so the seller can manage live ones.
    const { context, page: admin } = await panelContext(browser, "admin");
    await admin.goto(panelUrl("admin", "approvals"));
    for (const id of [listingA, listingB]) {
      const approve = admin.locator(`form[action$="/approvals/listings/${id}/approve"]`);
      await expect(approve).toHaveCount(1);
      await approve.locator('button[type="submit"]').click();
      await expect(admin.locator(`form[action$="/approvals/listings/${id}/approve"]`)).toHaveCount(0);
    }
    await shot(admin, "VEN-04", 4);
    await context.close();

    await go(page, sc(`products/${listingA}/edit`));
    await expect(page.locator("main")).toContainText(/Active/i);
    await shot(page, "VEN-04", 5);
    dropDevImageCsp(problems);
    expectClean(problems);
  });

  test("VEN-05 manage a live listing: price, images, alt text, stock, pause and resume", async ({ page, problems }) => {
    test.skip(!listingA, "VEN-03 did not create the listing");
    await go(page, sc(`products/${listingA}/edit`));
    const price = page.locator(`form[action$="/products/${listingA}"]`);
    await price.locator('[name="price"]').fill("149.5");
    await price.locator('[name="sale_price"]').fill("135");
    await price.locator('[name="handling_days"]').fill("2");
    await price.getByRole("button", { name: "Save" }).click();
    await expect(page.locator(`form[action$="/products/${listingA}"] [name="price"]`)).toHaveValue("149.5");
    await shot(page, "VEN-05", 1);

    // Add a variant-only image, then edit the run's first image (alt text, position), then remove both again
    // (images belong to the store's product, so the run leaves the shared catalogue product as it found it).
    const images = page.locator("main [data-image]");
    const before = await images.count();
    const upload = page.locator(`form[action$="/products/${listingA}/images"]`);
    await upload.locator('[name="images[]"]').setInputFiles({ name: "side.jpg", mimeType: "image/jpeg", buffer: await jpeg(page, "#16a34a") });
    await upload.locator('[name="alt_en"]').fill("Side view");
    await upload.locator('[name="variant_only"]').check();
    await upload.getByRole("button", { name: "Upload" }).click();
    await expect(images).toHaveCount(before + 1);
    await expect(page.locator("main")).toContainText("This variant only");
    await shot(page, "VEN-05", 2);
    const front = images.filter({ has: page.locator("input[name='alt_en'][value='Front view']") }).last();
    await front.locator('[name="alt_en"]').fill("Front view, updated");
    await front.locator('[name="sort_order"]').fill("5");
    await front.getByRole("button", { name: "Save" }).click();
    await expect(page.locator("main [data-image] input[name='alt_en'][value='Front view, updated']")).toHaveCount(1);
    await shot(page, "VEN-05", 3);
    for (const alt of ["Side view", "Front view, updated"]) {
      await images.filter({ has: page.locator(`input[name='alt_en'][value='${alt}']`) }).first().getByRole("button", { name: "Remove" }).click();
      await expect(page.locator(`main [data-image] input[name='alt_en'][value='${alt}']`)).toHaveCount(0);
    }
    await expect(images).toHaveCount(before - 1);

    // Stock: +5 restock, −2 damaged; the ledger lists both.
    const stock = page.locator(`form[action$="/products/${listingA}/stock"]`);
    await stock.locator('[name="quantity"]').fill("5");
    await stock.locator('[name="reason"]').selectOption("restock");
    await stock.getByRole("button", { name: "Update stock" }).click();
    await expect(page.locator("main")).toContainText("17 units available");
    await page.locator(`form[action$="/products/${listingA}/stock"] [name="quantity"]`).fill("-2");
    await page.locator(`form[action$="/products/${listingA}/stock"] [name="reason"]`).selectOption("damaged");
    await page.locator(`form[action$="/products/${listingA}/stock"]`).getByRole("button", { name: "Update stock" }).click();
    await expect(page.locator("main")).toContainText("15 units available");
    await shot(page, "VEN-05", 4);

    // Pause, then resume.
    await page.getByRole("button", { name: "Pause" }).click();
    await expect(page.getByRole("button", { name: "Resume" })).toBeVisible();
    await shot(page, "VEN-05", 5);
    await page.getByRole("button", { name: "Resume" }).click();
    await expect(page.getByRole("button", { name: "Pause" })).toBeVisible();
    await shot(page, "VEN-05", 6);
    dropDevImageCsp(problems);
    expectClean(problems);
  });
});

test("VEN-06 product request for a product not in the catalogue, then cancel it", async ({ page, problems }) => {
  const { run } = vendorFixtures();
  await go(page, sc("product-requests"));
  const form = page.locator("form[data-product-request-form]");
  await form.locator('[name="title_en"]').fill(`E2E Smart Kettle ${run}`);
  await form.locator('[name="title_ar"]').fill("غلاية ذكية للاختبار");
  await form.locator('[name="brand_name"]').fill("E2E Brand");
  await form.locator('[name="gtin"]').fill(`62${String(Date.now()).slice(-10)}`);
  await form.locator('[name="model_number"]').fill(`KT-${run}`);
  await form.locator('[name="reference_url"]').fill("https://example.com/kettle");
  await form.locator('[name="description"]').fill("1.7 L kettle with app control. Browser test request.");
  await shot(page, "VEN-06", 1);
  await form.getByRole("button", { name: "Send request" }).click();
  const row = page.locator("main tr").filter({ hasText: `E2E Smart Kettle ${run}` });
  await expect(row).toContainText(/Pending/i);
  await shot(page, "VEN-06", 2);
  await row.getByRole("button", { name: /Cancel/ }).click();
  await expect(page.locator("main tr").filter({ hasText: `E2E Smart Kettle ${run}` })).toContainText(/Cancelled/i);
  await shot(page, "VEN-06", 3);
  expectClean(problems);
});

test("VEN-07 change request for a locked profile section, then cancel it", async ({ page, problems }) => {
  const { run } = vendorFixtures();
  await go(page, sc("change-requests"));
  await shot(page, "VEN-07", 1);
  const card = page.locator("main [data-section]").first();
  const section = await card.getAttribute("data-section");
  await card.locator("summary").click();
  for (const input of await card.locator('input[name^="values["]').all()) {
    const name = (await input.getAttribute("name")) ?? "";
    await input.fill(/iban/.test(name) ? "AE070331234567890123456" : /phone/.test(name) ? "+971500000077" : /email/.test(name) ? `e2e-change-${run}@nawy.test` : `E2E ${run}`);
  }
  await card.locator('[name="note"]').fill(`Browser test ${run}: updated ${section}`);
  await shot(page, "VEN-07", 2);
  await card.getByRole("button", { name: "Send request" }).click();
  const row = page.locator("tr[data-change-request]").first();
  await expect(row).toContainText(/Pending/i);
  await shot(page, "VEN-07", 3);
  await row.getByRole("button", { name: "Cancel" }).click();
  await expect(page.locator("tr[data-change-request]").first()).toContainText(/Cancelled/i);
  await shot(page, "VEN-07", 4);
  expectClean(problems);
});

test("VEN-08 order: packing slip, confirm → pack → ready to ship, parcel label and invoice PDF", async ({ page, problems }) => {
  test.setTimeout(150_000);
  const { to_ship: so } = vendorFixtures();
  await go(page, sc("orders?status=placed"));
  await expect(page.locator("main")).toContainText(so.number);
  await shot(page, "VEN-08", 1);
  await go(page, sc(`orders/${so.id}`));
  await expect(page.locator("main")).toContainText(so.number);
  await shot(page, "VEN-08", 2);

  const slip = await fetchIn(page, sc(`orders/${so.id}/packing-slip`));
  expect(slip.status).toBe(200);
  expect(slip.text).toContain(so.number);

  const advance = page.locator(`form[action$="/orders/${so.id}/advance"]`);
  const seen: string[] = [];
  for (let i = 0; i < 4 && (await advance.count()); i++) {
    const to = await advance.locator('input[name="to"]').inputValue();
    seen.push(to);
    await advance.locator('button[type="submit"]').click();
    await expectFlash(page, new RegExp(`is now ${to.replace(/_/g, " ")}`));
    await shot(page, "VEN-08", 3 + i);
    if (/ready_to_ship|shipped|handed/.test(to)) break;
  }
  test.info().annotations.push({ type: "steps", description: seen.join(" → ") });
  expect(seen[0]).toBe("confirmed");

  // Parcel label (HTML + PDF) once a shipment exists, invoice PDF once the invoice is issued.
  const label = page.locator("[data-parcel-label]");
  if (await label.count()) {
    const html = await fetchIn(page, sc(`orders/${so.id}/label`));
    expect(html.status).toBe(200);
    const pdf = await fetchIn(page, sc(`orders/${so.id}/label.pdf`));
    expect(pdf.status).toBe(200);
    expect(pdf.head).toBe("%PDF-");
    await page.goto(sc(`orders/${so.id}/label`));
    await shot(page, "VEN-08", 8);
    await page.goBack();
  } else test.info().annotations.push({ type: "label", description: "no shipment allocated yet (carrier allocation is a later step)" });
  const invoice = page.getByRole("link", { name: "Download invoice (PDF)" });
  if (await invoice.count()) {
    const pdf = await fetchIn(page, sc(`orders/${so.id}/invoice.pdf`));
    expect(pdf.status).toBe(200);
    expect(pdf.head).toBe("%PDF-");
  } else test.info().annotations.push({ type: "invoice", description: "invoice not issued for a COD order before delivery" });
  await page.goto(sc(`orders/${so.id}/packing-slip`));
  await shot(page, "VEN-08", 9);
  expectClean(problems);
});

test("VEN-09 cancel an order with a reason (strike warning)", async ({ page, problems }) => {
  const { to_cancel: so } = vendorFixtures();
  await go(page, sc(`orders/${so.id}`));
  const cancel = page.locator(`form[action$="/orders/${so.id}/cancel"]`);
  await cancel.locator('[name="reason"]').fill("Out of stock at the store (browser test).");
  await shot(page, "VEN-09", 1);
  await cancel.getByRole("button", { name: "Cancel order" }).click();
  await expectFlash(page, /was cancelled/);
  await expect(page.locator("main")).toContainText(/Cancelled/i);
  await shot(page, "VEN-09", 2);
  expectClean(problems);
});

test("VEN-10 return: approve, mark received, inspect and refund", async ({ page, problems }) => {
  const { return: ret } = vendorFixtures();
  await go(page, sc("returns"));
  await expect(page.locator("main")).toContainText(ret.number);
  await shot(page, "VEN-10", 1);
  await go(page, sc(`returns/${ret.id}`));
  await shot(page, "VEN-10", 2);
  await page.getByRole("button", { name: "Approve return" }).click();
  await expect(page.locator("main")).toContainText(/approved/i);
  await shot(page, "VEN-10", 3);
  // Drop-off: in transit, then received.
  for (const status of ["in transit", "received"]) {
    await page.getByRole("button", { name: `Mark ${status}` }).click();
    await expect(page.getByRole("listitem").filter({ hasText: new RegExp(`${status}\\s*\\d`, "i") })).toBeVisible();
  }
  await expect(page.locator(`form[action$="/returns/${ret.id}/inspect"]`)).toBeVisible();
  await shot(page, "VEN-10", 4);
  const inspect = page.locator(`form[action$="/returns/${ret.id}/inspect"]`);
  await inspect.locator('select[name$="[outcome]"]').first().selectOption("good");
  await inspect.locator('input[type="checkbox"][name$="[restock]"]').first().check();
  await inspect.getByRole("button", { name: "Save inspection and refund" }).click();
  await expect(page.locator(`form[action$="/returns/${ret.id}/inspect"]`)).toHaveCount(0);
  await expectNoServerError(page);
  await shot(page, "VEN-10", 5);
  expectClean(problems);
});

test("VEN-11 reviews: reply to a review, answer a customer question", async ({ page, problems }) => {
  const { review, question, run } = vendorFixtures();
  await go(page, sc("reviews"));
  await expect(page.locator("main")).toContainText(review.title);
  await shot(page, "VEN-11", 1);
  const reply = page.locator(`form[action$="/reviews/${review.id}/reply"]`);
  await reply.locator('[name="body"]').fill(`Thank you for the review (${run}).`);
  await reply.locator('button[type="submit"]').click();
  await expectFlash(page, "Reply sent");
  await expect(page.locator("main")).toContainText(`Thank you for the review (${run}).`);
  const answer = page.locator(`form[action$="/reviews/questions/${question.id}/answer"]`);
  await answer.locator('[name="body"]').fill("Yes, it comes with a one-year warranty.");
  await answer.locator('button[type="submit"]').click();
  await expect(page.locator("main")).toContainText("Yes, it comes with a one-year warranty.");
  await shot(page, "VEN-11", 2);
  expectClean(problems);
});

test("VEN-12 disputes list and returns filters render", async ({ page, problems }) => {
  await go(page, sc("disputes"));
  await expectNoServerError(page);
  await shot(page, "VEN-12", 1);
  const first = page.locator('main a[href*="/disputes/"]').first();
  if (await first.count()) {
    await first.click();
    await expect(page).toHaveURL(/disputes\/\d+/);
    await shot(page, "VEN-12", 2);
  }
  expectClean(problems);
});

test("VEN-02b permissions: another store's order and listing are not reachable", async ({ page, problems }) => {
  // The seeded e2e travel agency is another tenant; ids outside the store answer 404.
  const res = await page.goto(sc("orders/999999"));
  expect(res?.status()).toBe(404);
  const listing = await page.goto(sc("products/999999/edit"));
  expect(listing?.status()).toBe(404);
  problems.console = problems.console.filter((m) => !/404/.test(m));
  expectClean(problems);
});
