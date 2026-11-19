import type { Page } from "@playwright/test";
import { test, expect, expectClean, settle } from "../../../support/fixtures";
import { CUSTOMER, CUSTOMER_STATE } from "../../../support/accounts";
import { mailMark, mailsTo, waitForMail } from "../../../support/mail";
import { rejectOptionalCookies } from "../../../support/storefront";
import { freshCustomer, runId, shot, souq } from "./support/souq";

/**
 * Open Souq buyer flows: favourites, saved searches, chat with the seller,
 * D12 viewing / reserve requests (record only: name, email, phone, preferred
 * time, message — no status) with the D13 mails (seller gets "new request",
 * the customer gets no receipt), show phone and the buyer contract gate.
 */
const inDays = (n: number) => new Date(Date.now() + n * 86_400_000).toISOString().slice(0, 10);

async function fillContact(page: Page, contact?: { name: string; email: string; phone: string }) {
  const dialog = page.getByRole("dialog");
  const name = dialog.locator('input[autocomplete="name"]');
  const email = dialog.locator('input[type="email"]');
  const phone = dialog.locator('input[type="tel"]');
  await expect(name).toBeVisible();
  if (contact) {
    await name.fill(contact.name);
    await email.fill(contact.email);
    await phone.fill(contact.phone);
  } else {
    if (!(await name.inputValue())) await name.fill("E2E Customer");
    if (!(await email.inputValue())) await email.fill(CUSTOMER.email);
    if (!(await phone.inputValue())) await phone.fill("+971501234567");
  }
}

test.beforeEach(async ({ page }) => rejectOptionalCookies(page));

test.describe("signed-in customer", () => {
  test.use({ storageState: CUSTOMER_STATE });

  test("SOQ-09 favourites: save an ad, list it, remove it", async ({ page, problems }) => {
    const { ads } = souq();
    await page.goto(`/uae-en/classified/find/${ads.shop}`);
    await settle(page);
    const save = page.locator("main").getByRole("button", { name: /^(Save|Saved) \(\d+\)$/ });
    if ((await save.getAttribute("aria-pressed")) === "true") {
      await save.click();
      await expect(save).toHaveAttribute("aria-pressed", "false");
    }
    await save.click();
    await expect(save).toHaveAttribute("aria-pressed", "true");
    await expect(save).toHaveText(/Saved \(\d+\)/);
    await shot(page, "SOQ-09", 1, false);

    await page.goto("/uae-en/account/favourites");
    await settle(page);
    const card = page.locator("main").locator(`a[href*="/classified/find/${ads.shop}"]`).first();
    await expect(card).toBeVisible();
    await shot(page, "SOQ-09", 2);
    await page.locator("main").getByRole("button", { name: "Remove from favourites" }).first().click();
    await expect(page.locator("main").locator(`a[href*="/classified/find/${ads.shop}"]`)).toHaveCount(0);
    await shot(page, "SOQ-09", 3);
    expectClean(problems);
  });

  test("SOQ-10 saved search with alerts", async ({ page, problems }) => {
    souq();
    const name = `E2E Galaxy alert ${runId()}`;
    await page.goto("/uae-en/classified/search?q=Galaxy&category=electronics-souq");
    await settle(page);
    await page.getByRole("button", { name: "Save search" }).click();
    const dialog = page.getByRole("dialog");
    await dialog.getByLabel("Name this search").fill(name);
    await dialog.getByLabel("Alerts").selectOption("instant");
    await shot(page, "SOQ-10", 1, false);
    await dialog.getByRole("button", { name: "Save search" }).click();
    await expect(dialog).toContainText("We'll alert you when new ads match");
    await page.keyboard.press("Escape");

    await page.goto("/uae-en/account/saved-searches");
    await settle(page);
    const row = page.locator("main li, main article, main tr").filter({ hasText: name }).first();
    await expect(row).toBeVisible();
    await shot(page, "SOQ-10", 2);
    const toggle = row.getByRole("button", { name: /Alerts (on|paused)/ });
    if (await toggle.count()) {
      const before = await toggle.innerText();
      await toggle.click();
      await expect(toggle).not.toHaveText(before);
    }
    await row.getByRole("button", { name: "Remove" }).click();
    await expect(page.locator("main")).not.toContainText(name);
    await shot(page, "SOQ-10", 3);
    expectClean(problems);
  });

  test("SOQ-11 chat with the seller → my inquiries (Chats tab)", async ({ page, problems }) => {
    const { ads } = souq();
    const text = `E2E chat ${runId()} — is it still available?`;
    await page.goto(`/uae-en/classified/find/${ads.shop}`);
    await settle(page);
    await page.locator("main").getByRole("button", { name: "Chat", exact: true }).click();
    await fillContact(page);
    const dialog = page.getByRole("dialog");
    await dialog.getByLabel("Your message").fill(text);
    await dialog.getByRole("checkbox").check();
    await shot(page, "SOQ-11", 1, false);
    await dialog.getByRole("button", { name: "Send message" }).click();
    await expect(dialog).toContainText("Message sent");
    await shot(page, "SOQ-11", 2, false);
    await dialog.getByRole("link", { name: "Open in my inquiries" }).click();
    await expect(page).toHaveURL(/account\/inquiries\?thread=\d+/);
    await settle(page);
    await expect(page.locator("main")).toContainText(text);
    await expect(page.locator("main")).toContainText("Galaxy S24 Ultra");
    // Follow-up in the same thread.
    const follow = `Follow-up ${runId()}`;
    await page.getByPlaceholder("Write a reply…").fill(follow);
    await page.getByRole("button", { name: "Send" }).click();
    await expect(page.locator("main")).toContainText(follow);
    await shot(page, "SOQ-11", 3);
    expectClean(problems);
  });

  test("SOQ-13 reserve request signed in → my requests list (no status)", async ({ page, problems }) => {
    const { ads, broker } = souq();
    const note = `E2E reserve ${runId()}`;
    await page.goto(`/uae-en/classified/find/${ads.broker}`);
    await settle(page);
    await page.getByRole("button", { name: "Request a viewing or reserve" }).click();
    const dialog = page.getByRole("dialog");
    await dialog.getByLabel("Reserve — the seller holds it for you").check();
    await fillContact(page);
    await dialog.getByLabel("Preferred date").fill(inDays(4));
    await dialog.getByLabel("Preferred time").selectOption({ index: 2 });
    await dialog.getByLabel("Message to the seller (optional)").fill(note);
    await dialog.getByRole("checkbox", { name: /Share my name/ }).check();
    await shot(page, "SOQ-13", 1, false);
    const mark = mailMark();
    await dialog.getByRole("button", { name: "Send request" }).click();
    await expect(dialog).toContainText("Request sent");
    await expect(dialog).toContainText(/Reference #\d+/);
    await shot(page, "SOQ-13", 2, false);
    // D13: the broker gets the "new request" mail; the customer gets none.
    const mail = await waitForMail(broker.email, mark, /Reserve request for/);
    expect(mail).toContain(note);
    await page.waitForTimeout(1500);
    expect(mailsTo(CUSTOMER.email, mark).filter((m) => /request/i.test(m)), "no receipt mail to the customer").toEqual([]);

    await dialog.getByRole("link", { name: "View my requests" }).click();
    await expect(page).toHaveURL(/account\/inquiries\?tab=requests/);
    await settle(page);
    const item = page.locator("main li, main article").filter({ hasText: note }).first();
    await expect(item).toBeVisible();
    await expect(item).toContainText("Preferred time:");
    await expect(item).not.toContainText(/Pending|Confirmed|Rejected|Scheduled/);
    await shot(page, "SOQ-13", 3);
    expectClean(problems);
  });
});

test("SOQ-12 guest viewing request — D12 record, D13 mails", async ({ page, problems }) => {
  const { ads, seller } = souq();
  const id = runId();
  const guest = { name: `E2E Guest ${id}`, email: `e2e-souq-guest-${id}@nawy.test`, phone: "+971501112233" };
  await page.goto(`/uae-en/classified/find/${ads.shop}`);
  await settle(page);
  await page.getByRole("button", { name: "Request a viewing or reserve" }).click();
  const dialog = page.getByRole("dialog");
  await expect(dialog).toContainText("No payment on Nawy — never send a deposit");
  await dialog.getByLabel("Viewing — see it in person").check();
  await fillContact(page, guest);
  await dialog.getByLabel("Preferred date").fill(inDays(3));
  await dialog.getByLabel("Preferred time").selectOption({ index: 1 });
  await dialog.getByLabel("Message to the seller (optional)").fill(`Viewing please (${id})`);
  await expect(dialog).toContainText("No account needed. Requests are free.");
  // Consent is ticked by default; the send button waits for it.
  const consent = dialog.getByRole("checkbox", { name: /Share my name/ });
  await consent.uncheck();
  await expect(dialog.getByRole("button", { name: "Send request" })).toBeDisabled();
  await consent.check();
  await shot(page, "SOQ-12", 1, false);
  const mark = mailMark();
  await dialog.getByRole("button", { name: "Send request" }).click();
  await expect(dialog).toContainText("Request sent");
  await expect(dialog).toContainText("Nothing to pay on Nawy");
  await shot(page, "SOQ-12", 2, false);

  const mail = await waitForMail(seller.email, mark, /Viewing request for/);
  expect(mail).toContain(guest.email);
  expect(mail).toContain(guest.name);
  expect(mail).toContain("record only");
  await page.waitForTimeout(1500);
  expect(mailsTo(guest.email, mark), "D13: no receipt mail to the customer").toEqual([]);
  expectClean(problems);
});

test("SOQ-14 show phone and the buyer contract gate (new customer)", async ({ page, problems }) => {
  test.setTimeout(150_000);
  const { ads } = souq();
  const email = `e2e-souq-buyer-${runId()}@nawy.test`;
  // A guest tapping Show phone is sent to sign in and comes back to the ad.
  await page.goto(`/uae-en/classified/find/${ads.contract}`);
  await settle(page);
  await page.getByRole("button", { name: "Show phone number" }).click();
  await expect(page).toHaveURL(/\/login\?next=.*classified%2Ffind/);
  await shot(page, "SOQ-14", 1, false);

  await freshCustomer(page, email);
  await page.goto(`/uae-en/classified/find/${ads.contract}`);
  await settle(page);
  await page.getByRole("button", { name: "Show phone number" }).click();
  const dialog = page.getByRole("dialog");
  await expect(dialog).toContainText("Accept the purchase terms");
  await expect(dialog).toContainText("E2E Souq buyer terms");
  await dialog.getByLabel("Your full name (signature)").fill("E2E Souq Buyer");
  await dialog.getByRole("checkbox", { name: "I have read and accept these terms" }).check();
  await shot(page, "SOQ-14", 2, false);
  await dialog.getByRole("button", { name: "Accept and continue" }).click();
  await expect(page.locator("main a[href^='tel:']")).toBeVisible();
  await expect(page.locator("main a[href^='tel:']")).toContainText("+971500000098");
  await expect(page.locator("main a[href*='wa.me']")).toBeVisible();
  await shot(page, "SOQ-14", 3, false);
  // The 409 customer_contract_required answer that opens the terms modal is expected.
  problems.console = problems.console.filter((m) => !/409 \(Conflict\).*\/phone\]$/.test(m));
  expectClean(problems);
});

test("SOQ-29 report an ad", async ({ page, problems }) => {
  const { ads } = souq();
  await page.goto(`/uae-en/classified/find/${ads.shop}`);
  await settle(page);
  await page.getByRole("button", { name: "Report this ad" }).click();
  const dialog = page.getByRole("dialog");
  await dialog.getByLabel("Duplicate ad").check();
  await dialog.getByLabel("Details (optional)").fill(`E2E report ${runId()} — browser test, please dismiss.`);
  await shot(page, "SOQ-29", 1, false);
  await dialog.getByRole("button", { name: "Send report" }).click();
  await expect(dialog).toContainText("Thanks — our team will review this ad.");
  await shot(page, "SOQ-29", 2, false);
  expectClean(problems);
});
