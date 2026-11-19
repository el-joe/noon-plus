import type { Browser, Page } from "@playwright/test";
import { test, expect, expectClean, settle } from "../../../support/fixtures";
import { CUSTOMER, CUSTOMER_STATE, PASSWORD, panelState } from "../../../support/accounts";
import { rejectOptionalCookies } from "../../../support/storefront";
import { API, customerToken, panel, panelContext, png, runId, shot, souq } from "./support/souq";
import { mailMark, mailsTo, waitForCode, waitForMail } from "../../../support/mail";

/**
 * Open Souq seller side: a classified shop in Seller Center (the
 * "Marketplace" layout): 2FA sign-in, every sidebar page, posting a listing
 * with photos and a map location, the ad lifecycle (review → live → pause →
 * resume → mark sold), the D12 viewing / reserve request list (records only)
 * and buyer inquiries with a reply that reaches the buyer on the website.
 */

const EXPECTED_NAV = ["Dashboard", "My listings", "Post a listing", "Inquiries", "Viewing & reserve requests", "Ads & boosts", "Profile", "Change requests", "Contracts", "Documents", "Support"];

async function expectPanelPage(page: Page, url: string) {
  const res = await page.goto(url, { waitUntil: "load" });
  expect.soft(res?.status(), `HTTP status of ${url}`).toBe(200);
  const text = await page.locator("body").innerText();
  expect.soft(text, `500 text on ${url}`).not.toMatch(/Server Error|Internal Server Error|Whoops|ErrorException|SQLSTATE/);
}

async function adminApproveById(browser: Browser, id: number) {
  const ctx = await browser.newContext({ storageState: panelState("admin") });
  const admin = await ctx.newPage();
  await admin.goto(panel("admin", "classifieds"));
  const form = admin.locator(`form[action$="/classifieds/${id}/approve"]`);
  await expect(form).toHaveCount(1);
  await form.locator('button[type="submit"]').click();
  await expect(admin.locator(`form[action$="/classifieds/${id}/approve"]`)).toHaveCount(0);
  await ctx.close();
}

test("SOQ-21 classified seller signs in with 2FA and opens every sidebar page", async ({ browser, problems }) => {
  test.setTimeout(240_000);
  const { seller } = souq();
  // Sign-in (password, then the emailed code) on a fresh context.
  const ctx0 = await browser.newContext();
  const login = await ctx0.newPage();
  await login.goto(panel("partner", "login"));
  await shot(login, "SOQ-21", 1);
  await login.locator('input[name="email"]').fill(seller.email);
  await login.locator('input[name="password"]').fill(PASSWORD);
  const mark = mailMark();
  await login.locator('form button[type="submit"]').first().click();
  await expect(login).toHaveURL(/two-factor/);
  await shot(login, "SOQ-21", 2);
  await login.locator('input[name="code"]').fill(await waitForCode(seller.email, mark));
  await login.locator('form button[type="submit"]').first().click();
  await expect(login).not.toHaveURL(/two-factor|login/);
  await expect(login.locator("#panel-sidebar")).toBeAttached();
  await shot(login, "SOQ-21", 3);
  await ctx0.close();

  const ctx = await panelContext(browser, "partner", seller.email);
  const page = await ctx.newPage();
  page.on("console", (m) => m.type() === "error" && problems.console.push(`${page.url()} :: ${m.text()}`));
  await expectPanelPage(page, panel("partner"));
  const sidebar = page.locator("#panel-sidebar");
  for (const label of EXPECTED_NAV) await expect(sidebar.getByRole("link", { name: label, exact: true })).toBeVisible();
  // The product-seller pages are not offered to a classified shop.
  for (const label of ["Orders", "Products", "Coupons", "Earnings"]) await expect(sidebar.getByRole("link", { name: label, exact: true })).toHaveCount(0);
  const origin = new URL(panel("partner")).origin;
  const links = (await page.$$eval("#panel-sidebar a[href]", (as) => [...new Set(as.map((a) => (a as HTMLAnchorElement).href.split("#")[0]))])).filter((h) => h.startsWith(origin));
  test.info().annotations.push({ type: "sidebar links", description: String(links.length) });
  let n = 4;
  for (const link of links) {
    await expectPanelPage(page, link);
    await shot(page, "SOQ-21", n++);
  }
  await ctx.close();
  expectClean(problems);
});

test("SOQ-22 post a listing in Seller Center → review → live → pause → resume → sold", async ({ browser, problems }) => {
  test.setTimeout(180_000);
  const { seller } = souq();
  const ctx = await panelContext(browser, "partner", seller.email);
  const page = await ctx.newPage();
  page.on("console", (m) => m.type() === "error" && problems.console.push(`${page.url()} :: ${m.text()}`));
  const title = `E2E shop MacBook Air ${runId()}`;

  await page.goto(panel("partner", "marketplace/ads/create"));
  await page.locator('select[name="category"]').selectOption({ label: "Mobiles & electronics" });
  await page.getByRole("button", { name: "Continue" }).click();
  await expect(page.locator('input[name="title"]')).toBeVisible();
  await page.locator('input[name="title"]').fill(title);
  await page.locator('textarea[name="description"]').fill("M3, 16 GB, 512 GB. Shop warranty. Browser test listing.");
  await page.locator("#photos").setInputFiles([png("front.png", [30, 90, 200]), png("side.png", [200, 90, 30])]);
  await page.locator('input[name="city"]').fill("Dubai");
  await page.locator('input[name="area"]').fill("Al Quoz");
  await page.locator('input[name="latitude"]').fill("25.1400");
  await page.locator('input[name="longitude"]').fill("55.2300");
  await page.locator('input[name="price"]').fill("4200");
  await page.locator('input[name="is_negotiable"]').check();
  await page.locator('input[name="contact_phone"]').fill("+971500000098");
  await shot(page, "SOQ-22", 1);
  await page.getByRole("button", { name: "Submit for review" }).click();
  await expect(page.locator("body")).toContainText("Ad sent to review. It goes live once approved.");
  const row = page.locator("tr").filter({ hasText: title });
  await expect(row).toBeVisible();
  await shot(page, "SOQ-22", 2);

  // Admin approves it in the review queue (found by title).
  const adminCtx = await browser.newContext({ storageState: panelState("admin") });
  const admin = await adminCtx.newPage();
  await admin.goto(panel("admin", "classifieds"));
  const adminRow = admin.locator("tr, article, li").filter({ hasText: title }).first();
  await expect(adminRow).toBeVisible();
  const action = await adminRow.locator('form[action*="/approve"]').getAttribute("action");
  await adminCtx.close();
  const adId = Number(/classifieds\/(\d+)\/approve/.exec(action ?? "")?.[1]);
  expect(adId).toBeGreaterThan(0);
  await adminApproveById(browser, adId);

  await page.reload();
  await expect(row).toContainText(/Active|Live/i);
  await shot(page, "SOQ-22", 3);
  await row.getByRole("button", { name: "Pause" }).click();
  await expect(page.locator("body")).toContainText("Ad updated.");
  await expect(row).toContainText(/Paused/i);
  await row.getByRole("button", { name: "Resume" }).click();
  await expect(row).toContainText(/Active|Live/i);
  await row.getByRole("link", { name: "Mark as sold" }).click();
  await expect(page).toHaveURL(new RegExp(`marketplace/ads/${adId}/sold`));
  await page.locator('input[name="final_price"]').fill("4000");
  const buyerNote = page.locator('[name="buyer_note"]');
  if (await buyerNote.count()) await buyerNote.fill("Walk-in buyer");
  await shot(page, "SOQ-22", 4);
  await page.locator('form[action$="/sold"] button[type="submit"]').click();
  await expect(page.locator("body")).toContainText("Marked as sold.");
  await expect(page.locator("tr").filter({ hasText: title })).toContainText(/Sold · 4,000\.00/);
  await shot(page, "SOQ-22", 5);
  await ctx.close();
  expectClean(problems);
});

test("SOQ-23 viewing & reserve requests list (D12 records only) and D13 seller mail", async ({ browser, problems }) => {
  const { seller, ads } = souq();
  const id = runId();
  const contact = { name: `E2E Viewer ${id}`, email: `e2e-souq-viewer-${id}@nawy.test`, phone: "+971502223344" };
  const mark = mailMark();
  const preferred = new Date(Date.now() + 5 * 86_400_000);
  preferred.setUTCHours(10, 0, 0, 0);
  const res = await (await browser.newContext()).request.post(`${API}/classifieds/ads/${ads.shop}/viewings`, {
    headers: { Accept: "application/json" },
    data: { ...contact, type: "reserve", preferred_at: preferred.toISOString(), message: `Hold it for me (${id})` },
  });
  expect(res.status(), await res.text()).toBe(201);
  // D13: one "new request" mail to the seller; none to the customer.
  const mail = await waitForMail(seller.email, mark, /Reserve request for/);
  expect(mail).toContain(contact.email);
  await new Promise((r) => setTimeout(r, 1500));
  expect(mailsTo(contact.email, mark)).toEqual([]);

  const ctx = await panelContext(browser, "partner", seller.email);
  const page = await ctx.newPage();
  page.on("console", (m) => m.type() === "error" && problems.console.push(`${page.url()} :: ${m.text()}`));
  await page.goto(panel("partner", "marketplace/requests"));
  await expect(page.locator("body")).toContainText("Nawy keeps these requests as records only.");
  const row = page.locator("tr").filter({ hasText: contact.name });
  await expect(row).toContainText("Reserve");
  await expect(row).toContainText("Galaxy S24 Ultra");
  await expect(row.getByRole("link", { name: contact.email })).toHaveAttribute("href", `mailto:${contact.email}`);
  await expect(row.getByRole("link", { name: contact.phone })).toHaveAttribute("href", `tel:${contact.phone}`);
  await expect(row).toContainText(`Hold it for me (${id})`);
  // No status column and no confirm / reject actions.
  await expect(row.locator("button, form")).toHaveCount(0);
  await expect(page.locator("thead")).not.toContainText(/Status/i);
  await shot(page, "SOQ-23", 1);
  await page.getByRole("link", { name: "Viewing", exact: true }).click();
  await expect(page).toHaveURL(/type=viewing/);
  await expect(page.locator("tr").filter({ hasText: contact.name })).toHaveCount(0);
  await shot(page, "SOQ-23", 2);
  await ctx.close();
  expectClean(problems);
});

test("SOQ-24 buyer inquiry → seller replies in Seller Center → buyer sees the reply", async ({ browser, problems }) => {
  const { seller, ads } = souq();
  const id = runId();
  const ask = `E2E inquiry ${id}: can you deliver to Sharjah?`;
  const buyer = await browser.newContext({ storageState: CUSTOMER_STATE });
  const res = await buyer.request.post(`${API}/classifieds/ads/${ads.shop}/inquiries`, {
    headers: { Accept: "application/json", Authorization: `Bearer ${customerToken()}` },
    data: { name: "E2E Customer", email: CUSTOMER.email, phone: "+971501234567", message: ask },
  });
  expect(res.status(), await res.text()).toBe(201);
  const threadId = ((await res.json()) as { data: { thread_id: number } }).data.thread_id;

  const ctx = await panelContext(browser, "partner", seller.email);
  const page = await ctx.newPage();
  page.on("console", (m) => m.type() === "error" && problems.console.push(`${page.url()} :: ${m.text()}`));
  await page.goto(panel("partner", "marketplace/inquiries"));
  await expect(page.locator("body")).toContainText(ask);
  const form = page.locator(`form[action$="/marketplace/inquiries/${threadId}/reply"]`);
  const reply = `Yes, delivery to Sharjah is free (${id}).`;
  await form.locator('[name="body"]').fill(reply);
  await shot(page, "SOQ-24", 1);
  await form.locator('button[type="submit"]').click();
  await expect(page.locator("body")).toContainText("Reply sent.");
  await expect(page.locator("body")).toContainText(reply);
  await shot(page, "SOQ-24", 2);
  await ctx.close();

  const web = await buyer.newPage();
  await rejectOptionalCookies(web);
  await web.goto(`/uae-en/account/inquiries?thread=${threadId}`);
  await settle(web);
  await expect(web.locator("main")).toContainText(reply);
  await shot(web, "SOQ-24", 3);
  // The Requests tab of the same page lists the customer's own viewing / reserve requests.
  await web.getByRole("link", { name: "Viewing & reserve requests" }).click();
  await expect(web).toHaveURL(/tab=requests/);
  await settle(web);
  await shot(web, "SOQ-24", 4);
  await buyer.close();
  expectClean(problems);
});
