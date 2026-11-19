import type { Page } from "@playwright/test";
import { test, expect, expectClean, settle } from "../../../support/fixtures";
import { CUSTOMER_STATE } from "../../../support/accounts";
import { mailMark, mailsTo, waitForMail } from "../../../support/mail";
import { rejectOptionalCookies } from "../../../support/storefront";
import { API, panel, panelContext, runId, shot, souq } from "./support/souq";

/**
 * Open Souq brokers (Creator Studio, broker job type): the Broker section
 * pages (leads pipeline, viewing & reserve requests, special requests,
 * properties), a request on the broker's ad opening a lead, adding and
 * moving a lead, and a customer's special request claimed by the broker.
 */
async function expectPanelPage(page: Page, url: string) {
  const res = await page.goto(url, { waitUntil: "load" });
  expect.soft(res?.status(), `HTTP status of ${url}`).toBe(200);
  expect.soft(await page.locator("body").innerText(), `500 text on ${url}`).not.toMatch(/Server Error|Whoops|ErrorException|SQLSTATE/);
}

test("SOQ-25 broker: Creator Studio broker pages and the viewing request → lead", async ({ browser, problems }) => {
  test.setTimeout(180_000);
  const { broker, ads } = souq();
  const id = runId();
  const contact = { name: `E2E Lead ${id}`, email: `e2e-souq-lead-${id}@nawy.test`, phone: "+971503334455" };
  const mark = mailMark();
  const when = new Date(Date.now() + 6 * 86_400_000);
  const guest = await browser.newContext();
  const res = await guest.request.post(`${API}/classifieds/ads/${ads.broker}/viewings`, {
    headers: { Accept: "application/json" },
    data: { ...contact, type: "viewing", preferred_at: when.toISOString(), message: `Weekend viewing (${id})` },
  });
  expect(res.status(), await res.text()).toBe(201);
  await guest.close();
  expect(await waitForMail(broker.email, mark, /Viewing request for/)).toContain(contact.email);
  await new Promise((r) => setTimeout(r, 1500));
  expect(mailsTo(contact.email, mark), "D13: no receipt to the customer").toEqual([]);

  const ctx = await panelContext(browser, "marketer", broker.email);
  const page = await ctx.newPage();
  page.on("console", (m) => m.type() === "error" && problems.console.push(`${page.url()} :: ${m.text()}`));
  await expectPanelPage(page, panel("marketer"));
  const sidebar = page.locator("#panel-sidebar");
  for (const label of ["Leads pipeline", "Viewing & reserve requests", "Special requests", "Properties"]) await expect(sidebar.getByRole("link", { name: label, exact: true })).toBeVisible();
  await shot(page, "SOQ-25", 1);

  // Every sidebar page of the broker's Creator Studio.
  const origin = new URL(panel("marketer")).origin;
  const links = (await page.$$eval("#panel-sidebar a[href]", (as) => [...new Set(as.map((a) => (a as HTMLAnchorElement).href.split("#")[0]))])).filter((h) => h.startsWith(origin));
  test.info().annotations.push({ type: "sidebar links", description: String(links.length) });
  for (const link of links) await expectPanelPage(page, link);

  await page.goto(panel("marketer", "broker/requests"));
  const row = page.locator("tr").filter({ hasText: contact.name });
  await expect(row).toContainText("Viewing");
  await expect(row).toContainText("2-bed in Business Bay");
  await expect(row).toContainText(`Weekend viewing (${id})`);
  await expect(row.locator("button, form")).toHaveCount(0);
  await shot(page, "SOQ-25", 2);

  await page.goto(panel("marketer", "broker/properties"));
  await expect(page.locator("body")).toContainText("2-bed in Business Bay");
  await shot(page, "SOQ-25", 3);

  // The request opened a lead in the pipeline ("New").
  await page.goto(panel("marketer", "broker/leads"));
  const lead = page.locator("article").filter({ hasText: contact.name }).first();
  await expect(lead).toBeVisible();
  await shot(page, "SOQ-25", 4);
  await ctx.close();
  expectClean(problems);
});

test("SOQ-26 broker: add a lead, schedule a follow-up, move it through the pipeline", async ({ browser, problems }) => {
  const { broker } = souq();
  const name = `E2E Walk-in ${runId()}`;
  const ctx = await panelContext(browser, "marketer", broker.email);
  const page = await ctx.newPage();
  page.on("console", (m) => m.type() === "error" && problems.console.push(`${page.url()} :: ${m.text()}`));
  await page.goto(panel("marketer", "broker/leads"));
  const add = page.locator('form[action$="/broker/leads"]');
  await add.locator('input[name="name"]').fill(name);
  await add.locator('input[name="phone"]').fill("+971504445566");
  await add.locator('input[name="email"]').fill("walk-in@nawy.test");
  await add.locator('input[name="budget"]').fill("150000");
  await add.getByRole("button", { name: "Add lead" }).click();
  await expect(page.locator("body")).toContainText("Lead added.");
  const lead = page.locator("article").filter({ hasText: name }).first();
  await expect(lead).toBeVisible();
  await shot(page, "SOQ-26", 1);

  const follow = lead.locator('form[action*="/follow-ups"]');
  const due = new Date(Date.now() + 2 * 86_400_000);
  await follow.locator('select[name="channel"]').selectOption("whatsapp");
  await follow.locator('input[name="due_at"]').fill(`${due.toISOString().slice(0, 10)}T11:00`);
  await follow.getByRole("button", { name: "Follow-up" }).click();
  await expect(page.locator("body")).toContainText("Follow-up scheduled.");
  await expect(page.locator("article").filter({ hasText: name }).first()).toContainText("Follow up");

  const move = page.locator("article").filter({ hasText: name }).first().locator('form[action*="/status"]');
  await move.locator('select[name="status"]').selectOption("contacted");
  await move.getByRole("button", { name: "Move" }).click();
  await expect(page.locator("body")).toContainText("Lead moved.");
  await shot(page, "SOQ-26", 2);
  await ctx.close();
  expectClean(problems);
});

test("SOQ-27 special request: customer posts it on the website, the broker starts it", async ({ browser, problems }) => {
  test.setTimeout(150_000);
  const { broker } = souq();
  const details = `E2E special ${runId()}: 3-bed villa near a school, garden, move in March`;
  const customer = await browser.newContext({ storageState: CUSTOMER_STATE });
  const web = await customer.newPage();
  web.on("console", (m) => m.type() === "error" && problems.console.push(`${web.url()} :: ${m.text()}`));
  await rejectOptionalCookies(web);
  await web.goto("/uae-en/account/special-requests");
  await settle(web);
  await web.getByRole("button", { name: "New request" }).click();
  await web.getByLabel("Looking to").selectOption({ index: 2 });
  await web.getByLabel("City or area").fill("Arabian Ranches");
  await web.getByLabel("Bedrooms").fill("3");
  await web.getByLabel(/Budget up to/).fill("250000");
  await web.getByLabel(/What are you looking for\?/).fill(details);
  await shot(web, "SOQ-27", 1);
  await web.getByRole("button", { name: "Post request" }).click();
  await expect(web.locator("main")).toContainText(/Request SR-[A-Z0-9-]+ posted|posted\. Brokers will be in touch/);
  const number = /(SR-[A-Z0-9-]+)/.exec(await web.locator("main").innerText())?.[1] ?? "";
  await shot(web, "SOQ-27", 2);

  const ctx = await panelContext(browser, "marketer", broker.email);
  const page = await ctx.newPage();
  page.on("console", (m) => m.type() === "error" && problems.console.push(`${page.url()} :: ${m.text()}`));
  await page.goto(panel("marketer", "broker/special-requests"));
  const open = page.locator("tr").filter({ hasText: details.slice(0, 60) });
  await expect(open).toBeVisible();
  await shot(page, "SOQ-27", 3);
  await open.getByRole("button", { name: "Start" }).click();
  await expect(page.locator("body")).toContainText("Request started — the customer is now one of your leads.");
  const mine = page.locator("tr").filter({ hasText: number || "SR-" }).filter({ has: page.getByRole("button", { name: "Fulfilled" }) }).first();
  await expect(mine).toBeVisible();
  await shot(page, "SOQ-27", 4);

  await web.reload();
  await settle(web);
  await expect(web.locator("main")).toContainText("A broker is working on it");
  await shot(web, "SOQ-27", 5);

  await mine.getByRole("button", { name: "Fulfilled" }).click();
  await expect(page.locator("body")).toContainText("Request updated.");
  await ctx.close();
  await customer.close();
  expectClean(problems);
});

test("SOQ-28 broker profile on the website lists the broker's ads", async ({ page, problems }) => {
  const { broker } = souq();
  await rejectOptionalCookies(page);
  await page.goto(`/uae-en/brokers/${broker.slug}`);
  await settle(page);
  await expect(page.locator("main")).toContainText("E2E Souq Broker");
  await expect(page.locator("main")).toContainText("2-bed in Business Bay");
  await shot(page, "SOQ-28", 1);
  expectClean(problems);
});
