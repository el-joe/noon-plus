import { readFileSync } from "node:fs";
import path from "node:path";
import type { Page } from "@playwright/test";
import { test, expect, expectClean, shoot, settle } from "../../support/fixtures";
import { CUSTOMER_STATE, panelState, panelUrl } from "../../support/accounts";
import { GENERATED } from "../../support/global-setup";
import type { Fixtures } from "../../support/routes";
import { rejectOptionalCookies } from "../../support/storefront";

/**
 * D8 travel bookings without payment: request to book as a guest and signed
 * in → "request sent" → booking status; the agency confirms the guest request
 * in the Travel Partner panel and the shopper sees it confirmed.
 */
const trip = () => (JSON.parse(readFileSync(path.join(GENERATED, "fixtures.json"), "utf8")) as Fixtures).trip_slug!;

async function requestToBook(page: Page, contact?: { name: string; email: string }) {
  await page.goto(`/uae-en/travel/${trip()}/book`);
  await settle(page);
  if (contact) {
    await page.getByPlaceholder("Your full name").fill(contact.name);
    await page.locator('input[type="email"][autocomplete="email"]').last().fill(contact.email);
  }
  const phone = page.locator('input[type="tel"][autocomplete="tel"]').last();
  if (!(await phone.inputValue())) await phone.fill("+971501234567");
  await page.getByText(/confirms my booking and I pay them directly/).click();
  await shoot(page, "flows", `travel-book-form-${contact ? "guest" : "signed-in"}`);
  await page.getByRole("button", { name: "Send booking request" }).click();
  await expect(page).toHaveURL(/\/travel\/bookings\/TB-[A-Z]{2}-\d+\/sent/, { timeout: 30_000 });
  return /TB-[A-Z]{2}-\d+/.exec(page.url())![0];
}

test.beforeEach(async ({ page }) => rejectOptionalCookies(page));

test("guest travel request → sent → status → agency confirms", async ({ page, browser, problems }) => {
  test.setTimeout(120_000);
  const number = await requestToBook(page, { name: "E2E Guest Traveller", email: `e2e-traveller-${Date.now()}@nawy.test` });
  await settle(page);
  await shoot(page, "flows", "travel-guest-sent");

  await page.goto(`/uae-en/travel/bookings/${number}`);
  await settle(page);
  await expect(page.locator("main")).toContainText("Requested — awaiting agency confirmation");
  await shoot(page, "flows", "travel-guest-status-requested");

  // Travel Partner panel: confirm the request.
  const agency = await browser.newContext({ storageState: panelState("travel-agency") });
  const panel = await agency.newPage();
  await panel.goto(panelUrl("travel-agency", "bookings"));
  const row = panel.locator("tr").filter({ hasText: number });
  await row.getByRole("button", { name: "Confirm" }).click();
  await expect(panel.locator("tr").filter({ hasText: number })).toContainText(/Confirmed/i);
  await shoot(panel, "panels/actions", "travel-confirm-booking");
  await agency.close();

  await page.reload();
  await settle(page);
  await expect(page.locator("main")).toContainText("Confirmed by the agency");
  await shoot(page, "flows", "travel-guest-status-confirmed");
  expectClean(problems);
});

test.describe("signed in", () => {
  test.use({ storageState: CUSTOMER_STATE });
  test("travel request appears under My bookings", async ({ page, problems }) => {
    const number = await requestToBook(page);
    await page.goto("/uae-en/account/bookings");
    await settle(page);
    await expect(page.locator("main")).toContainText(number);
    await shoot(page, "flows", "travel-signed-in-my-bookings");
    expectClean(problems);
  });
});
