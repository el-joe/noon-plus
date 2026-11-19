import { test, expect, expectClean } from "./support/test";
import { PANELS } from "../../../support/accounts";
import { mailMark, waitForMail } from "../../../support/mail";
import { PDF, expectFlash, expectNoServerError, fetchIn, go, panelSignIn, sc, shooter, signedInAs, vendorFixtures } from "./support/vendor";

/**
 * Seller Center account: team & permissions (limited role, password reset of
 * the new member), documents, store settings, contracts, support,
 * notifications, Arabic RTL, 2FA sign-in and sign-in history.
 * Report: vendor-panel-finance-fbn-and-settings (VEN-30…VEN-37).
 */
const shot = shooter("vendor-panel-finance-fbn-and-settings");

test("VEN-30 team: invite an Orders member, they accept, get a limited panel, reset their password; owner removes them", async ({ page, browser, problems }) => {
  test.setTimeout(240_000);
  const { run } = vendorFixtures();
  const email = `e2e-ven-staff-${run}@nawy.test`;
  await go(page, sc("team"));
  await shot(page, "VEN-30", 1);
  const invite = page.locator('form[action$="/team/invitations"]');
  await invite.locator('[name="name"]').fill(`E2E Orders ${run}`);
  await invite.locator('[name="email"]').fill(email);
  await invite.locator('[name="role"]').selectOption("orders");
  const mark = mailMark();
  await invite.getByRole("button", { name: "Send invitation" }).click();
  await expect(page.locator("main")).toContainText(email);
  await shot(page, "VEN-30", 2);

  const mail = await waitForMail(email, mark, /team-invitations/);
  const link = /https?:\/\/partner\.[^\s"<>]+\/team-invitations\/[^\s"<>]+/.exec(mail)![0];
  const guest = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  const invitee = await guest.newPage();
  invitee.on("console", (m) => m.type() === "error" && !/403/.test(m.text()) && problems.console.push(`${invitee.url()} :: ${m.text()}`));
  await invitee.goto(link);
  await expect(invitee.locator("body")).toContainText(/Orders/);
  await shot(invitee, "VEN-30", 3);
  const firstPassword = "E2e-Orders-Pass1!";
  await invitee.locator('[name="password"]').fill(firstPassword);
  await invitee.locator('[name="password_confirmation"]').fill(firstPassword);
  await invitee.locator('form button[type="submit"]').first().click();
  await expect(invitee).toHaveURL(/\/login/);
  await expect(invitee.locator("body")).toContainText("Invitation accepted");
  await panelSignIn(invitee, "partner", email, firstPassword);

  // Orders role: orders, returns, inventory; products read-only; no coupons, finance, team or documents.
  const side = invitee.locator("#panel-sidebar");
  await expect(side.getByRole("link", { name: "Orders", exact: true })).toBeVisible();
  for (const hidden of ["Coupons", "Earnings", "Team & permissions", "Documents", "Subscription"]) {
    await expect(side.getByRole("link", { name: hidden, exact: true }), `${hidden} hidden`).toHaveCount(0);
  }
  await shot(invitee, "VEN-30", 4);
  for (const path of ["team", "coupons", "earnings", "products/create"]) {
    expect((await invitee.goto(sc(path)))?.status(), `${path} for the Orders role`).toBe(403);
  }
  await invitee.goto(sc("products"));
  await expect(invitee.getByRole("link", { name: /Add a product|Add product/ }), "no add button for a read-only role").toHaveCount(0);
  // BT-VEN-008: a listing page is read-only for this role (no price, image, stock or status forms).
  await invitee.goto(sc(`products/${vendorFixtures().order_listing.id}/edit`));
  await expect(invitee.locator("[data-read-only]")).toBeVisible();
  await expect(invitee.locator('main form[action*="/products/"][method="POST"]')).toHaveCount(0);
  await shot(invitee, "VEN-30", 5);

  // Password reset of the member: forgot → mail → new password → sign in with it.
  await guest.clearCookies();
  await invitee.goto(sc("forgot-password"));
  await invitee.locator('[name="email"]').fill(email);
  const resetMark = mailMark();
  await invitee.locator('form button[type="submit"]').first().click();
  await shot(invitee, "VEN-30", 6);
  const resetMail = await waitForMail(email, resetMark, /reset-password/);
  const resetLink = /https?:\/\/partner\.[^\s"<>]+\/reset-password\/[^\s"<>]+/.exec(resetMail)![0];
  await invitee.goto(resetLink);
  const newPassword = "E2e-Orders-Pass2!";
  if (!(await invitee.locator('[name="email"]').inputValue())) await invitee.locator('[name="email"]').fill(email);
  await invitee.locator('[name="password"]').fill(newPassword);
  await invitee.locator('[name="password_confirmation"]').fill(newPassword);
  await shot(invitee, "VEN-30", 7);
  await invitee.locator('form button[type="submit"]').first().click();
  await expect(invitee).toHaveURL(/\/login/);
  await panelSignIn(invitee, "partner", email, newPassword);
  await expect(invitee.locator("#panel-sidebar")).toBeAttached();
  await shot(invitee, "VEN-30", 8);
  await guest.close();

  // The owner sees the member and removes them.
  await go(page, sc("team"));
  const member = page.locator("tr[data-member]").filter({ hasText: email });
  await expect(member).toBeVisible();
  // Removes this run's member (and any left by an earlier interrupted run).
  const staff = page.locator("tr[data-member]").filter({ hasText: "e2e-ven-staff-" });
  for (let n = await staff.count(); n > 0; n--) {
    await staff.first().getByRole("button", { name: "Remove" }).click();
    await expect(staff).toHaveCount(n - 1);
  }
  await expect(page.locator("tr[data-member]").filter({ hasText: email })).toHaveCount(0);
  await shot(page, "VEN-30", 9);
  expectClean(problems);
});

test("VEN-31 documents: upload a trade licence and download it", async ({ page, problems }) => {
  await go(page, sc("documents"));
  await shot(page, "VEN-31", 1);
  const form = page.locator('form[action$="/documents"]');
  await form.locator('[name="document_type_id"]').selectOption({ index: 1 });
  const category = form.locator('[name="category_id"]');
  if ((await category.count()) && (await category.locator("option").count()) > 1 && !(await category.inputValue())) await category.selectOption({ index: 1 }).catch(() => undefined);
  await form.locator('[name="file"]').setInputFiles({ name: "trade-licence.pdf", mimeType: "application/pdf", buffer: PDF });
  const validUntil = form.locator('[name="valid_until"]');
  if (await validUntil.count()) await validUntil.fill(new Date(Date.now() + 300 * 86_400_000).toISOString().slice(0, 10));
  await form.locator('button[type="submit"]').click();
  await expectNoServerError(page);
  const download = page.locator('main a[href*="/documents/"][href$="/download"]').first();
  await expect(download).toBeVisible();
  await shot(page, "VEN-31", 2);
  const file = await fetchIn(page, (await download.getAttribute("href"))!);
  expect(file.status).toBe(200);
  expect(file.head).toBe("%PDF-");
  expectClean(problems);
});

test("VEN-32 store settings: contact phone and description; contracts list", async ({ page, problems }) => {
  const { run } = vendorFixtures();
  await go(page, sc("store"));
  await shot(page, "VEN-32", 1);
  const form = page.locator("form[data-store-form]");
  await form.locator('[name="contact_phone"]').fill("+971500000099");
  await form.locator('[name="description"]').fill(`E2E Store — browser test description ${run}.`);
  await form.locator('button[type="submit"]').click();
  await expectFlash(page, /./);
  await expect(page.locator('form[data-store-form] [name="description"]')).toHaveValue(`E2E Store — browser test description ${run}.`);
  await shot(page, "VEN-32", 2);
  await go(page, sc("contracts"));
  await expectNoServerError(page);
  await shot(page, "VEN-32", 3);
  expectClean(problems);
});

test("VEN-33 support: open a ticket and reply", async ({ page, problems }) => {
  const { run } = vendorFixtures();
  await go(page, sc("support"));
  await page.locator('[name="category"]').selectOption({ index: 1 });
  await page.locator('[name="subject"]').fill(`E2E seller ticket ${run}`);
  await page.locator('[name="message"]').fill("Browser test: how do I change my payout bank?");
  await shot(page, "VEN-33", 1);
  await page.locator('form:has([name="subject"])').locator('button[type="submit"]').click();
  if (!/support\/\d+/.test(page.url())) await page.getByRole("link", { name: new RegExp(`E2E seller ticket ${run}`) }).first().click();
  await expect(page).toHaveURL(/support\/\d+/);
  await page.locator('[name="body"]').fill("More detail from the browser test.");
  await page.locator('form:has([name="body"])').locator('button[type="submit"]').click();
  await expect(page.locator("main")).toContainText("More detail from the browser test.");
  await shot(page, "VEN-33", 2);
  expectClean(problems);
});

test("VEN-34 notifications: list and mark all read", async ({ page, problems }) => {
  await go(page, sc("notifications"));
  await shot(page, "VEN-34", 1);
  const readAll = page.locator('form[action$="/notifications/read-all"]');
  if (await readAll.count()) {
    await readAll.locator("button").click();
    await expectNoServerError(page);
  }
  await shot(page, "VEN-34", 2);
  expectClean(problems);
});

test("VEN-35 Arabic: the panel switches to right-to-left and back", async ({ browser, problems }) => {
  // The language is saved on the user, so this runs as the run's own store owner (not the shared e2e seller).
  const fx = vendorFixtures();
  const { context, page } = await signedInAs(browser, fx.fresh.email);
  page.on("console", (m) => m.type() === "error" && problems.console.push(`${page.url()} :: ${m.text()}`));
  await page.goto(sc());
  await Promise.all([page.waitForLoadState("load"), page.locator("#panel-locale").selectOption("ar")]);
  await expect(page.locator("html")).toHaveAttribute("dir", "rtl");
  await expect(page.locator("html")).toHaveAttribute("lang", "ar");
  await expect(page.locator("#panel-sidebar")).toContainText("الطلبات");
  await shot(page, "VEN-35", 1);
  await page.goto(sc("orders"));
  await expect(page.locator("html")).toHaveAttribute("dir", "rtl");
  await shot(page, "VEN-35", 2);
  await page.goto(sc("coupons/create"));
  await shot(page, "VEN-35", 3);
  await Promise.all([page.waitForLoadState("load"), page.locator("#panel-locale").selectOption("en")]);
  await expect(page.locator("html")).toHaveAttribute("dir", "ltr");
  await context.close();
  expectClean(problems);
});

test.describe("signed out", () => {
  test.use({ storageState: { cookies: [], origins: [] } });
  test("VEN-36 sign-in with password and the emailed 2FA code; sign-in history; forgot-password mail", async ({ page, problems }) => {
    const fx = vendorFixtures();
    await page.goto(sc("login"));
    await shot(page, "VEN-36", 1);
    // A wrong password is refused.
    await page.locator('input[name="email"]').fill(fx.fresh.email);
    await page.locator('input[name="password"]').fill("wrong-password");
    await page.locator('form button[type="submit"]').first().click();
    await expect(page.locator("body")).toContainText(/do not match|incorrect|invalid/i);
    await shot(page, "VEN-36", 2);
    await panelSignIn(page, "partner", fx.fresh.email);
    await expect(page.locator("#panel-sidebar")).toBeAttached();
    await page.goto(sc("account/security"));
    await expect(page.locator("main")).toContainText(/127\.0\.0\.1|Chrome|sign-in/i);
    await shot(page, "VEN-36", 3);
    await page.context().clearCookies();
    await page.goto(sc("forgot-password"));
    await page.locator('[name="email"]').fill(fx.fresh.email);
    const mark = mailMark();
    await page.locator('form button[type="submit"]').first().click();
    await waitForMail(fx.fresh.email, mark, /reset-password/);
    await shot(page, "VEN-36", 4);
    expect(PANELS.partner.email).toBe("e2e-seller@nawy.test"); // the shared seller's password is never reset here
    expectClean(problems);
  });
});
