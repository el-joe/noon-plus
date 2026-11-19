import type { Page } from "@playwright/test";
import { test, expect, expectClean, settle } from "../../../support/fixtures";
import { CUSTOMER_STATE, panelState, panelUrl } from "../../../support/accounts";
import { mailMark, waitForMail } from "../../../support/mail";
import { rejectOptionalCookies } from "../../../support/storefront";
import { RUN, expectFlash, expectNoServerError, gotoPanel, inDays, panelContext, routePanelGateway, snap, ta, uae } from "./helpers";

/**
 * Travel Partner end to end, on a trip this run creates:
 * TRV-02 create (departures, room prices, no commission D11) → TRV-03 submit,
 * admin approves (only to continue) → TRV-04 departures & capacity →
 * TRV-05 guest + signed-in booking requests reach the agency (D8, no payment)
 * → TRV-06 confirm / reject → TRV-07 inquiry → TRV-08 Sponsored promotion
 * paid by fake card: failed, pending, captured (D14) → TRV-09 trip leads
 * travel search with the Sponsored label.
 */
test.describe.configure({ mode: "serial" });
test.use({ storageState: panelState("travel-agency") });

const TITLE = `E2E Trip ${RUN}`;
const MINI_PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 300]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
let packageId = 0;
let slug = "";
let guestNumber = "";
let guestState: Awaited<ReturnType<import("@playwright/test").BrowserContext["storageState"]>> | undefined;
let customerNumber = "";

async function requestToBook(page: Page, contact?: { name: string; email: string }) {
  await page.goto(`/uae-en/travel/${slug}/book`);
  await settle(page);
  if (contact) {
    await page.getByPlaceholder("Your full name").fill(contact.name);
    await page.locator('input[type="email"][autocomplete="email"]').last().fill(contact.email);
  }
  const phone = page.locator('input[type="tel"][autocomplete="tel"]').last();
  if (!(await phone.inputValue())) await phone.fill("+971501234567");
  await page.getByText(/confirms my booking and I pay them directly/).click();
  return phone;
}

test("TRV-02 create a trip with departures and room prices (no commission)", async ({ page, problems }) => {
  await gotoPanel(page, ta("packages"));
  await snap(page, "TRV-02", 1);
  await page.getByRole("link", { name: "New package" }).first().click();
  await expect(page).toHaveURL(/packages\/create/);
  // D11: no commission anywhere on the editor.
  expect(await page.locator('[name*="commission" i]').count()).toBe(0);
  await expect(page.locator("main")).not.toContainText(/commission/i);
  await page.locator('[name="title_en"]').fill(TITLE);
  await page.locator('[name="title_ar"]').fill(`رحلة اختبار ${RUN}`);
  await page.locator('[name="trip_type"]').selectOption("holiday");
  await page.locator('[name="destination_city"]').fill("Salalah");
  await page.locator('[name="destination_country"]').fill("Oman");
  await page.locator('[name="nights"]').fill("4");
  await page.locator('[name="description_en"]').fill("Browser-test trip: beaches, khareef season and the souq.");
  await page.locator('[name="itinerary_text"]').fill("Arrive and check in\nMountain tour\nBeach day\nSouq and departure");
  await page.locator('[name="inclusions_text"]').fill("Hotel\nBreakfast\nTransfers");
  await page.locator('[name="exclusions_text"]').fill("Flights");
  await page.locator('[name="policy[0][days_before]"]').fill("14");
  await page.locator('[name="policy[0][refund_percent]"]').fill("100");
  await page.locator('[name="policy[1][days_before]"]').fill("3");
  await page.locator('[name="policy[1][refund_percent]"]').fill("50");
  await snap(page, "TRV-02", 2);
  await page.getByRole("button", { name: "Save package" }).click();
  await expect(page).toHaveURL(/\/packages\/\d+$/);
  packageId = Number(/packages\/(\d+)/.exec(page.url())![1]);
  await expectFlash(page, "Draft saved");

  // Departure 1 with room prices per person.
  const add = page.locator(`form[action$="/packages/${packageId}/departures"]`);
  await add.locator('[name="departs_on"]').fill(inDays(40));
  await add.locator('[name="returns_on"]').fill(inDays(44));
  await add.locator('[name="seat_capacity"]').fill("20");
  await add.locator('[name="rooms[single]"]').fill("3200");
  await add.locator('[name="rooms[double]"]').fill("2600");
  await add.locator('[name="rooms[triple]"]').fill("2300");
  await add.locator('[name="rooms[child]"]').fill("1200");
  await add.getByRole("button", { name: "Add departure" }).click();
  await expectFlash(page, "Departure added");
  // Departure 2.
  await add.locator('[name="departs_on"]').fill(inDays(70));
  await add.locator('[name="returns_on"]').fill(inDays(74));
  await add.locator('[name="seat_capacity"]').fill("12");
  await add.locator('[name="rooms[double]"]').fill("2800");
  await add.getByRole("button", { name: "Add departure" }).click();
  await expectFlash(page, "Departure added");
  const rows = page.locator("main table").last().locator("tbody tr");
  await expect(rows).toHaveCount(2);
  await expect(rows.first()).toContainText("Single 3,200.00");
  await expect(rows.first()).toContainText("Double 2,600.00");
  await snap(page, "TRV-02", 3);

  // Edit: change nights and description, save.
  await page.locator('[name="nights"]').fill("5");
  await page.locator('[name="description_en"]').fill("Browser-test trip (edited): beaches, khareef season and the souq.");
  // The trip contract file is required before review.
  await page.locator('input[name="contract"]').setInputFiles({ name: `trip-contract-${RUN}.pdf`, mimeType: "application/pdf", buffer: Buffer.from(MINI_PDF) });
  await page.getByRole("button", { name: "Save package" }).click();
  await expectFlash(page, "Package saved");
  await expect(page.locator('[name="nights"]')).toHaveValue("5");
  await expect(page.locator("main")).toContainText("on file");
  await snap(page, "TRV-02", 4);

  // Invalid departure: return before departure is refused.
  await add.locator('[name="departs_on"]').fill(inDays(50));
  await add.locator('[name="returns_on"]').fill(inDays(45));
  await add.locator('[name="seat_capacity"]').fill("5");
  await add.getByRole("button", { name: "Add departure" }).click();
  await expect(page.locator("main")).toContainText(/returns on|must be a date after or equal/i);
  await expect(rows).toHaveCount(2);
  await snap(page, "TRV-02", 5);
  expectClean(problems);
});

test("TRV-03 submit for review; Nawy approves; the trip is live on the website", async ({ page, browser, problems }) => {
  expect(packageId).toBeGreaterThan(0);
  await gotoPanel(page, ta(`packages/${packageId}`));
  await page.getByRole("button", { name: "Submit for review" }).click();
  await expectFlash(page, "Submitted for review");
  await snap(page, "TRV-03", 1);

  // Admin moderation (another agent's area) only to continue the flow.
  const admin = await panelContext(browser, "admin");
  await admin.page.goto(panelUrl("admin", "travel"));
  const approve = admin.page.locator(`form[action$="/travel/${packageId}/approve"]`);
  await expect(approve).toHaveCount(1);
  await approve.locator('button[type="submit"]').click();
  await expect(admin.page.locator(`form[action$="/travel/${packageId}/approve"]`)).toHaveCount(0);
  await admin.context.close();

  await page.goto(ta("packages"));
  await expect(page.locator(`tr[data-package="${packageId}"]`)).toContainText(/Active/i);
  await snap(page, "TRV-03", 2);

  slug = uae(`echo App\\Domains\\Travel\\Models\\TravelPackage::findOrFail(${packageId})->slug;`).split("\n").pop()!.trim();
  expect(slug).toBeTruthy();
  const shop = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  const web = await shop.newPage();
  await rejectOptionalCookies(web);
  await web.goto(`/uae-en/travel/${slug}`);
  await settle(web);
  await expect(web.locator("main h1")).toContainText(TITLE);
  await expect(web.locator("main")).toContainText("Salalah");
  await snap(web, "TRV-03", 3);
  await shop.close();
  expectClean(problems);
});

test("TRV-04 departures & capacity: resize a departure", async ({ page, problems }) => {
  await gotoPanel(page, ta("departures"));
  const row = page.locator("tr[data-departure]").filter({ hasText: TITLE }).filter({ hasText: "Double 2,800" });
  await expect(row).toHaveCount(1);
  await snap(page, "TRV-04", 1);
  await row.locator('[name="seat_capacity"]').fill("16");
  await row.getByRole("button", { name: "Save" }).click();
  await expectFlash(page, "Capacity updated");
  await expect(page.locator("tr[data-departure]").filter({ hasText: TITLE }).filter({ hasText: "Double 2,800" }).locator('[name="seat_capacity"]')).toHaveValue("16");
  await snap(page, "TRV-04", 2);
  expectClean(problems);
});

test("TRV-05 guest and signed-in booking requests reach the agency (no payment, D8)", async ({ browser, page, problems }) => {
  test.setTimeout(150_000);
  const guestEmail = `e2e-trv-guest-${RUN}@nawy.test`;
  const shop = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  const web = await shop.newPage();
  web.on("pageerror", (e) => problems.pageErrors.push(`${web.url()} :: ${e.message}`));
  await rejectOptionalCookies(web);
  await requestToBook(web, { name: `E2E Guest ${RUN}`, email: guestEmail });
  // D8: no card / payment step on the request form.
  await expect(web.locator("main")).not.toContainText(/card number|pay now|checkout/i);
  await snap(web, "TRV-05", 1);
  const mark = mailMark();
  await web.getByRole("button", { name: "Send booking request" }).click();
  await expect(web).toHaveURL(/\/travel\/bookings\/TB-[A-Z]{2}-\d+\/sent/, { timeout: 30_000 });
  guestNumber = /TB-[A-Z]{2}-\d+/.exec(web.url())![0];
  await settle(web);
  await snap(web, "TRV-05", 2);
  await waitForMail(guestEmail, mark, undefined, 20_000).catch(() => test.info().annotations.push({ type: "note", description: "no receipt mail to guest" }));
  // The guest's device keeps the booking access (cookie); TRV-06 opens the status page on it.
  guestState = await shop.storageState();
  await shop.close();

  const customer = await browser.newContext({ storageState: CUSTOMER_STATE });
  const cweb = await customer.newPage();
  await rejectOptionalCookies(cweb);
  await requestToBook(cweb);
  await cweb.getByRole("button", { name: "Send booking request" }).click();
  await expect(cweb).toHaveURL(/\/travel\/bookings\/TB-[A-Z]{2}-\d+\/sent/, { timeout: 30_000 });
  customerNumber = /TB-[A-Z]{2}-\d+/.exec(cweb.url())![0];
  await customer.close();

  await gotoPanel(page, ta("bookings?status=requested"));
  for (const number of [guestNumber, customerNumber]) {
    const row = page.locator("tr[data-booking]").filter({ hasText: number });
    await expect(row).toContainText(TITLE);
    await expect(row).toContainText(/Requested/i);
  }
  await expect(page.locator("tr[data-booking]").filter({ hasText: guestNumber })).toContainText(guestEmail);
  await snap(page, "TRV-05", 3);
  await page.goto(ta());
  await expect(page.locator("main")).toContainText("Requests to answer");
  await snap(page, "TRV-05", 4);
  expectClean(problems);
});

test("TRV-06 agency confirms one request and rejects the other; the shopper sees both", async ({ page, browser, problems }) => {
  expect(guestNumber && customerNumber).toBeTruthy();
  await gotoPanel(page, ta("bookings"));
  await page.locator("tr[data-booking]").filter({ hasText: guestNumber }).getByRole("button", { name: "Confirm" }).click();
  await expectFlash(page, "Booking confirmed");
  await expect(page.locator("tr[data-booking]").filter({ hasText: guestNumber })).toContainText(/Confirmed/i);
  await snap(page, "TRV-06", 1);

  // Reject needs a reason of at least 5 characters (browser-side minlength first).
  const reject = page.locator("tr[data-booking]").filter({ hasText: customerNumber });
  await reject.locator('[name="reason"]').fill("Sold out for these dates, sorry");
  await reject.getByRole("button", { name: "Reject" }).click();
  await expectFlash(page, "rejected");
  await expect(page.locator("tr[data-booking]").filter({ hasText: customerNumber })).toContainText(/Rejected/i);
  await snap(page, "TRV-06", 2);
  await page.goto(ta("bookings?status=rejected"));
  await expect(page.locator("tr[data-booking]").filter({ hasText: customerNumber })).toHaveCount(1);
  await snap(page, "TRV-06", 3);

  const shop = await browser.newContext({ storageState: guestState });
  const web = await shop.newPage();
  await web.goto(`/uae-en/travel/bookings/${guestNumber}`);
  await settle(web);
  await expect(web.locator("main")).toContainText("Confirmed by the agency");
  await snap(web, "TRV-06", 4);
  await shop.close();

  const customer = await browser.newContext({ storageState: CUSTOMER_STATE });
  const cweb = await customer.newPage();
  await rejectOptionalCookies(cweb);
  await cweb.goto(`/uae-en/travel/bookings/${customerNumber}`);
  await settle(cweb);
  await expect(cweb.locator("main")).toContainText("Not available — the agency declined");
  await snap(cweb, "TRV-06", 5);
  await customer.close();

  // Booking reports count the confirmed request.
  await page.goto(ta("reports"));
  await expectNoServerError(page);
  await snap(page, "TRV-06", 6);
  expectClean(problems);
});

test("TRV-07 a traveller's question reaches Inquiries; the agency moves it on", async ({ page, browser, problems }) => {
  const shop = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  const web = await shop.newPage();
  await rejectOptionalCookies(web);
  await web.goto(`/uae-en/travel/${slug}`);
  await settle(web);
  await web.getByRole("button", { name: "Ask the agency" }).first().click();
  const dialog = web.getByRole("dialog");
  await dialog.getByPlaceholder("Your full name").fill(`E2E Asker ${RUN}`);
  await dialog.locator('input[type="email"]').fill(`e2e-trv-ask-${RUN}@nawy.test`);
  await dialog.locator('input[type="tel"]').fill("+971501234567");
  await dialog.locator("textarea").fill(`Is a sea-view room available? (${RUN})`);
  await snap(web, "TRV-07", 1);
  await dialog.getByRole("button", { name: "Send question" }).click();
  await expect(dialog).toContainText("Your question was sent");
  await snap(web, "TRV-07", 2);
  await shop.close();

  await gotoPanel(page, ta("inquiries"));
  const row = page.locator("tr").filter({ hasText: `(${RUN})` });
  await expect(row).toContainText(/New/i);
  await snap(page, "TRV-07", 3);
  await row.getByRole("button", { name: "Contacted" }).click();
  await expectFlash(page, "Inquiry updated");
  await expect(page.locator("tr").filter({ hasText: `(${RUN})` })).toContainText(/Contacted/i);
  await snap(page, "TRV-07", 4);
  expectClean(problems);
});

test("TRV-08 Sponsored promotion paid by card: failed, then pending, then captured (D14)", async ({ page, problems }) => {
  test.setTimeout(150_000);
  // Test data: earlier runs' promotions of their own throwaway trips are cancelled (admin cancel, no refund) so
  // the sponsored slots (travel.promotion_slots, rotating) are not all taken by old browser-test trips.
  uae(`$admin = App\\Models\\User::where('email', 'e2e-admin@nawy.test')->value('id');
    App\\Domains\\Advertising\\Models\\TravelPromotion::query()->where('status', 'active')
      ->whereHas('package', fn ($q) => $q->where('title_en', 'like', 'E2E Trip %')->where('title_en', '!=', '${TITLE}'))
      ->each(fn ($p) => app(App\\Domains\\Advertising\\Support\\TravelPromotions::class)->cancel($p, $admin, 'Browser-test cleanup'));`);
  await gotoPanel(page, ta("promotions"));
  await expect(page.locator("main")).toContainText("Listing trips is free");
  await snap(page, "TRV-08", 1);
  await page.getByRole("link", { name: "Promote a trip" }).first().click();
  await expect(page.locator("form[data-promote-form]")).toBeVisible();
  await expect(page.locator("main")).toContainText("no commission");
  await page.locator('[name="package_id"]').selectOption(String(packageId));
  await page.locator('[name="product_id"]').first().check();
  await snap(page, "TRV-08", 2);

  // 1. Card declined → not active, can try again.
  await routePanelGateway(page, "failed");
  await page.getByRole("button", { name: "Pay by card" }).click();
  await expect(page).toHaveURL(/\/promotions$/);
  await expect(page.locator('main [role="alert"]')).toContainText("The payment did not go through");
  const row = page.locator("tr[data-promotion]").filter({ hasText: TITLE }).first();
  await expect(row).toContainText(/Pending payment/i);
  await snap(page, "TRV-08", 3);

  // 2. Pay again, gateway has not answered yet → pending.
  await routePanelGateway(page, "pending");
  await row.getByRole("button", { name: "Pay by card" }).click();
  await expect(page.locator('main [role="status"]')).toContainText("pending payment confirmation");
  await snap(page, "TRV-08", 4);

  // 3. Pay again, captured → active with its window and a receipt.
  await routePanelGateway(page, "captured");
  await page.locator("tr[data-promotion]").filter({ hasText: TITLE }).first().getByRole("button", { name: "Pay by card" }).click();
  await expect(page.locator('main [role="status"]')).toContainText(/Payment received\. Your promotion for .* (is now active until|is confirmed and starts on)/);
  const paid = page.locator("tr[data-promotion]").filter({ hasText: TITLE }).first();
  await expect(paid).toContainText(/Active|Scheduled/i);
  await expect(paid).toContainText("→");
  await snap(page, "TRV-08", 5);
  await paid.getByRole("link", { name: "Receipt" }).click();
  await expect(page.locator("[data-receipt]")).toContainText("Total paid");
  await expect(page.locator("[data-receipt] [data-vat]")).toBeVisible();
  await snap(page, "TRV-08", 6);
  expectClean(problems);
});

test("TRV-09 the promoted trip leads travel search with the Sponsored label", async ({ browser, problems }) => {
  test.setTimeout(150_000);
  const shop = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  const web = await shop.newPage();
  await rejectOptionalCookies(web);
  const card = () => web.locator(`main [data-testid="trip-card"][href$="/travel/${encodeURIComponent(slug)}"]`);
  // The storefront may cache travel lists briefly; poll.
  await expect(async () => {
    await web.goto("/uae-en/travel/search");
    await settle(web);
    await expect(card().getByTestId("trip-sponsored")).toHaveText("Sponsored", { timeout: 3_000 });
  }).toPass({ timeout: 100_000, intervals: [5_000] });
  const flags = await web.locator('main [data-testid="trip-card"]').evaluateAll((els) => els.map((el) => !!el.querySelector('[data-testid="trip-sponsored"]')));
  const firstOrganic = flags.indexOf(false);
  expect(flags.slice(firstOrganic === -1 ? flags.length : firstOrganic).includes(true), "no sponsored card after an organic one").toBe(false);
  const myIndex = await web.locator('main [data-testid="trip-card"]').evaluateAll((els, s) => els.findIndex((el) => (el.getAttribute("href") ?? "").endsWith(`/travel/${s}`)), encodeURIComponent(slug));
  expect(myIndex).toBeLessThan(firstOrganic === -1 ? flags.length : firstOrganic);
  await card().scrollIntoViewIfNeeded();
  await snap(web, "TRV-09", 1);

  await web.goto(`/uae-en/travel/${slug}`);
  await settle(web);
  await expect(web.locator("main h1").locator("..").getByTestId("trip-sponsored")).toHaveText("Sponsored");
  await snap(web, "TRV-09", 2);

  await web.goto("/uae-ar/travel/search");
  await settle(web);
  await expect(card().getByTestId("trip-sponsored")).toHaveText("مُموَّل");
  await snap(web, "TRV-09", 3);
  await shop.close();
  expectClean(problems);
});
