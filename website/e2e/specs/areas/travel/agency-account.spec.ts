import { test, expect, expectClean } from "../../../support/fixtures";
import { PANELS, panelState } from "../../../support/accounts";
import { mailMark, waitForMail } from "../../../support/mail";
import { RUN, expectFlash, gotoPanel, panelSignIn, snap, ta } from "./helpers";

/**
 * Travel Partner: every sidebar page, notifications and security, Arabic
 * (RTL), team invitation with a limited role, change requests, support and
 * the password-reset mail. Report: travel-agency-panel (TRV-01, TRV-10…14).
 */
test.use({ storageState: panelState("travel-agency") });

/** The 11 items of PanelNavigation 'travel_agency'. */
const NAV: [string, string][] = [
  ["Dashboard", ""], ["Packages & trips", "packages"], ["Departures & capacity", "departures"],
  ["Bookings", "bookings"], ["Inquiries", "inquiries"], ["Promotions", "promotions"], ["Promote a trip", "promotions/create"],
  ["Booking reports", "reports"], ["Change requests", "change-requests"], ["Team & permissions", "team"], ["Support", "support"],
];

test("TRV-01 sidebar: every page of the Travel Partner panel opens", async ({ page, problems }) => {
  test.setTimeout(180_000);
  await gotoPanel(page, ta());
  const sidebar = page.locator("#panel-sidebar");
  const hrefs = await sidebar.locator("a[href]").evaluateAll((as) => [...new Set(as.map((a) => new URL((a as HTMLAnchorElement).href).pathname.replace(/\/$/, "")))]);
  for (const [label, path] of NAV) {
    await expect(sidebar.getByRole("link", { name: label, exact: true }).first(), `sidebar link ${label}`).toBeVisible();
    expect(hrefs).toContain(path ? `/${path}` : "");
  }
  let n = 1;
  for (const [label, path] of [...NAV, ["Notifications", "notifications"], ["Account security", "account/security"]] as [string, string][]) {
    const res = await page.goto(ta(path));
    expect.soft(res?.status(), `${label} status`).toBe(200);
    const text = await page.locator("body").innerText();
    expect.soft(text, label).not.toMatch(/Server Error|Whoops|ErrorException|SQLSTATE/);
    expect.soft(text, `raw keys on ${label}`).not.toMatch(/\b(?:validation|passwords|auth|pagination|panels?|messages)\.[a-z_]+\b/);
    await snap(page, "TRV-01", n++);
  }
  // D11: no commission or settlement anywhere on the dashboard (value is paid to the agency directly).
  await page.goto(ta());
  await expect(page.locator("main")).toContainText("paid to you directly");
  await expect(page.locator("main")).not.toContainText(/commission/i);
  expectClean(problems);
});

test("TRV-10 Arabic panel renders right-to-left", async ({ page, problems }) => {
  await gotoPanel(page, ta("bookings"));
  await page.locator("#panel-locale").selectOption("ar");
  await page.waitForLoadState("load");
  await expect(page.locator("html")).toHaveAttribute("dir", "rtl");
  await expect(page.locator("html")).toHaveAttribute("lang", "ar");
  await snap(page, "TRV-10", 1);
  await page.goto(ta("promotions/create"));
  await expect(page.locator("html")).toHaveAttribute("dir", "rtl");
  await snap(page, "TRV-10", 2);
  await page.locator("#panel-locale").selectOption("en");
  await page.waitForLoadState("load");
  await expect(page.locator("html")).toHaveAttribute("dir", "ltr");
  expectClean(problems);
});

test("TRV-11 team: invite a Finance member; they get a limited panel", async ({ page, browser, problems }) => {
  test.setTimeout(150_000);
  const email = `e2e-trv-finance-${RUN}@nawy.test`;
  await gotoPanel(page, ta("team"));
  await snap(page, "TRV-11", 1);
  const invite = page.locator('form[action$="/team/invitations"]');
  await invite.locator('[name="name"]').fill(`E2E Finance ${RUN}`);
  await invite.locator('[name="email"]').fill(email);
  await invite.locator('[name="role"]').selectOption("finance");
  const mark = mailMark();
  await invite.getByRole("button").click();
  await expect(page.locator("main")).toContainText(email);
  await snap(page, "TRV-11", 2);

  const mail = await waitForMail(email, mark, /team-invitations/);
  const link = /https?:\/\/travel-agency\.[^\s"<>]+\/team-invitations\/[^\s"<>]+/.exec(mail)![0];
  const guest = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  const invitee = await guest.newPage();
  await invitee.goto(link);
  await expect(invitee.locator("body")).toContainText(/Finance/);
  await snap(invitee, "TRV-11", 3);
  const password = "E2e-Finance-Pass1!";
  await invitee.locator('[name="password"]').fill(password);
  await invitee.locator('[name="password_confirmation"]').fill(password);
  await invitee.locator('form button[type="submit"]').first().click();
  await expect(invitee).toHaveURL(/\/login/);
  await expect(invitee.locator("body")).toContainText("Invitation accepted");
  await panelSignIn(invitee, "travel-agency", email, password);
  // Finance: dashboard, bookings, promotions, reports and support only (view); no packages, inquiries or team.
  const side = invitee.locator("#panel-sidebar");
  await expect(side.getByRole("link", { name: "Bookings", exact: true })).toBeVisible();
  await expect(side.getByRole("link", { name: "Packages & trips", exact: true })).toHaveCount(0);
  await expect(side.getByRole("link", { name: "Team & permissions", exact: true })).toHaveCount(0);
  await snap(invitee, "TRV-11", 4);
  expect((await invitee.goto(ta("packages")))?.status()).toBe(403);
  expect((await invitee.goto(ta("team")))?.status()).toBe(403);
  // Read-only bookings: no Confirm buttons.
  await invitee.goto(ta("bookings?status=requested"));
  await expect(invitee.getByRole("button", { name: "Confirm" })).toHaveCount(0);
  await snap(invitee, "TRV-11", 5);
  // Promotions are view-only for Finance: no buy form.
  await invitee.goto(ta("promotions/create"));
  await expect(invitee.locator("[data-read-only]")).toBeVisible();
  await expect(invitee.locator("form[data-promote-form]")).toHaveCount(0);
  await snap(invitee, "TRV-11", 7);
  await guest.close();

  await page.reload();
  await expect(page.locator("main")).toContainText(`E2E Finance ${RUN}`);
  await snap(page, "TRV-11", 6);
  problems.console = problems.console.filter((m) => !/403/.test(m));
  expectClean(problems);
});

test("TRV-12 change request for a locked section, then cancel it", async ({ page, problems }) => {
  await gotoPanel(page, ta("change-requests"));
  await snap(page, "TRV-12", 1);
  const card = page.locator('[data-section="address"]');
  await card.locator("summary").click();
  await card.locator('[name="values[building]"]').fill(`Tower ${RUN}`);
  await card.locator('[name="values[street]"]').fill("Sheikh Zayed Road");
  await card.locator('[name="values[area]"]').fill("Trade Centre");
  await card.locator('[name="values[city]"]').fill("Dubai");
  await card.locator('[name="note"]').fill(`Browser test ${RUN}: office moved`);
  await snap(page, "TRV-12", 2);
  await card.getByRole("button", { name: "Send request" }).click();
  const row = page.locator("tr[data-change-request]").first();
  await expect(row).toContainText(/Pending/i);
  await snap(page, "TRV-12", 3);
  await row.getByRole("button", { name: "Cancel" }).click();
  await expect(page.locator("tr[data-change-request]").first()).toContainText(/Cancelled/i);
  await snap(page, "TRV-12", 4);
  expectClean(problems);
});

test("TRV-13 support ticket: open, reply", async ({ page, problems }) => {
  await gotoPanel(page, ta("support"));
  await page.locator('[name="category"]').selectOption({ index: 1 });
  await page.locator('[name="subject"]').fill(`E2E travel ticket ${RUN}`);
  await page.locator('[name="message"]').fill("Browser test: how do I add a child room price?");
  await snap(page, "TRV-13", 1);
  await page.locator('form:has([name="subject"])').locator('button[type="submit"]').click();
  await expect(page.locator("main")).toContainText(`E2E travel ticket ${RUN}`);
  if (!/support\/\d+/.test(page.url())) await page.getByRole("link", { name: new RegExp(`E2E travel ticket ${RUN}`) }).first().click();
  await expect(page).toHaveURL(/support\/\d+/);
  await snap(page, "TRV-13", 2);
  await page.locator('[name="body"]').fill("Adding more detail from the browser test.");
  await page.locator('form:has([name="body"])').locator('button[type="submit"]').click();
  await expect(page.locator("main")).toContainText("Adding more detail from the browser test.");
  await snap(page, "TRV-13", 3);
  expectClean(problems);
});

test.describe("signed out", () => {
  test.use({ storageState: { cookies: [], origins: [] } });
  test("TRV-14 sign-in with 2FA and the password-reset mail", async ({ page, problems }) => {
    await page.goto(ta("login"));
    await snap(page, "TRV-14", 1);
    await panelSignIn(page, "travel-agency");
    await expect(page.locator("#panel-sidebar")).toBeAttached();
    await snap(page, "TRV-14", 2);
    await page.context().clearCookies();

    await page.goto(ta("forgot-password"));
    const email = PANELS["travel-agency"].email;
    await page.locator('[name="email"]').fill(email);
    const mark = mailMark();
    await page.locator('form button[type="submit"]').first().click();
    await snap(page, "TRV-14", 3);
    const mail = await waitForMail(email, mark, /reset-password/);
    const link = /https?:\/\/travel-agency\.[^\s"<>]+\/reset-password\/[^\s"<>]+/.exec(mail)![0];
    await page.goto(link);
    await expect(page.locator('[name="password"]')).toBeVisible();
    await snap(page, "TRV-14", 4);
    // Not submitted: the shared e2e agency password stays as seeded.
    expectClean(problems);
  });
});
