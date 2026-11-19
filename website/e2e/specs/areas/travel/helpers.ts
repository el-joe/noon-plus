import { mkdirSync } from "node:fs";
import path from "node:path";
import type { Browser, BrowserContext, Page } from "@playwright/test";
import { expect } from "../../../support/fixtures";
import { PANELS, PASSWORD, panelState, panelUrl, type PanelKey } from "../../../support/accounts";
import { mailMark, waitForCode } from "../../../support/mail";
import { fakeGatewayReports, tinker } from "../../../support/storefront";

/** Travel Partner panel helpers for the `areas/travel` browser test (report travel-agency-panel). */
export const REPORT = "travel-agency-panel";
const SHOTS = path.resolve(__dirname, "../../../docx/shots", REPORT);

/** e2e/docx/shots/travel-agency-panel/{flow}-{n}.png (full page). */
export async function snap(page: Page, flow: string, n: number) {
  mkdirSync(SHOTS, { recursive: true });
  await page.screenshot({ path: path.join(SHOTS, `${flow}-${n}.png`), fullPage: true, animations: "disabled" });
}

export const RUN = `${Date.now().toString(36)}`;

export const ta = (p = "") => panelUrl("travel-agency", p);

/** Password + emailed 2FA code on a panel's /login (the page must be on the login form). */
export async function panelSignIn(page: Page, panel: PanelKey, email = PANELS[panel].email, password = PASSWORD) {
  if (!/\/login/.test(page.url())) await page.goto(panelUrl(panel, "login"));
  await page.locator('input[name="email"]').fill(email);
  await page.locator('input[name="password"]').fill(password);
  const mark = mailMark();
  await page.locator('form button[type="submit"]').first().click();
  if (/two-factor/.test(page.url()) || (await page.waitForURL(/two-factor/, { timeout: 5_000 }).then(() => true).catch(() => false))) {
    const code = await waitForCode(email, mark);
    await page.locator('input[name="code"]').fill(code);
    await page.locator('form button[type="submit"]').first().click();
  }
  await expect(page).not.toHaveURL(/two-factor|login/);
}

/** A context on the saved panel session; signs in again (in memory only) when the session expired. */
export async function panelContext(browser: Browser, panel: PanelKey): Promise<{ context: BrowserContext; page: Page }> {
  const context = await browser.newContext({ storageState: panelState(panel) });
  const page = await context.newPage();
  await page.goto(panelUrl(panel));
  if (/\/login/.test(page.url())) await panelSignIn(page, panel);
  return { context, page };
}

/** Keeps the session alive for `page` (signs in when a request bounced to /login). */
export async function gotoPanel(page: Page, url: string, panel: PanelKey = "travel-agency") {
  const res = await page.goto(url);
  if (/\/login/.test(page.url())) {
    await panelSignIn(page, panel);
    return page.goto(url);
  }
  return res;
}

export async function expectFlash(page: Page, text: RegExp | string) {
  await expect(page.locator('main [role="status"]').first()).toContainText(text);
}

export async function expectNoServerError(page: Page) {
  const text = await page.locator("body").innerText();
  expect.soft(text).not.toMatch(/Server Error|Whoops|ErrorException|SQLSTATE|419|Page Expired/);
}

/** Runs PHP inside the uae country context (test records only); returns stdout trimmed. */
export function uae(php: string): string {
  return tinker(`app(App\\Domains\\Countries\\Support\\CountryContext::class)->runAs(App\\Domains\\Countries\\Models\\Country::where('code','uae')->sole(), function () { ${php} });`).trim();
}

/**
 * The fake gateway page for panel payments. The panel answers the "Pay by
 * card" POST with a 302 to https://pay.fake.test/{method}/fake_{method}_{reference};
 * a browser-followed redirect is not routable in Playwright, so the POST is
 * fetched here without following redirects, the provider answer is scripted
 * (none = still pending) and the browser goes straight to the panel's return URL.
 */
export async function routePanelGateway(page: Page, outcome: "captured" | "failed" | "pending") {
  const matcher = (url: URL) => /^\/promotions(\/\d+\/pay)?$/.test(url.pathname) && url.hostname.startsWith("travel-agency.");
  await page.unrouteAll({ behavior: "wait" });
  await page.route(matcher, async (route) => {
    if (route.request().method() !== "POST") return route.fallback();
    // Node does not resolve *.nawy.test (only Chromium maps it): send to 127.0.0.1 with the panel Host.
    const target = new URL(route.request().url());
    const host = target.host;
    target.hostname = "127.0.0.1";
    const response = await route.fetch({ url: target.toString(), headers: { ...(await route.request().allHeaders()), host }, maxRedirects: 0 });
    const location = response.headers()["location"] ?? "";
    const gateway = /^https:\/\/pay\.fake\.test\/([a-z_]+)\/(fake_[a-z_]+_(.+))$/.exec(location);
    if (!gateway) return route.fulfill({ response });
    const [, method, gatewayReference] = gateway;
    const reference = gatewayReference.replace(`fake_${method}_`, "");
    if (outcome !== "pending") fakeGatewayReports(gatewayReference, outcome);
    const headers = { ...response.headers(), location: ta(`promotions/return?payment=${encodeURIComponent(reference)}`) };
    await route.fulfill({ status: 302, headers });
  });
}

/** A date `days` from today, yyyy-mm-dd. */
export const inDays = (days: number) => new Date(Date.now() + days * 86_400_000).toISOString().slice(0, 10);
