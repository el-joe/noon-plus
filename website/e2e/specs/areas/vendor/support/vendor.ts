import { execFileSync } from "node:child_process";
import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";
import type { Browser, BrowserContext, Page } from "@playwright/test";
import { expect } from "../../../../support/fixtures";
import { PANELS, PASSWORD, panelState, panelUrl, type PanelKey } from "../../../../support/accounts";
import { mailMark, waitForCode } from "../../../../support/mail";
import { fakeGatewayReports } from "../../../../support/storefront";

/**
 * Seller Center browser-test helpers (reports vendor-panel-*). `vendorFixtures()`
 * runs support/setup.php once per Playwright run (cached per runner process):
 * fresh sub-orders, a return, a review and a question on the e2e store's own
 * e2e variant, two new catalogue variants to list, a brand-new uae store and a
 * Qatar store behind the Seller Agreement gate.
 */
const BACKEND = path.resolve(__dirname, "../../../../../../backend");
const SHOTS_ROOT = path.resolve(__dirname, "../../../../docx/shots");

export type VendorFixtures = {
  run: string;
  ad_slot_id: number;
  flash_event_id: number;
  store_id: number;
  product: { id: number; title: string };
  order_listing: { id: number; sku: string };
  fbn_listing: { id: number; sku: string };
  warehouse: { id: number; code: string };
  new_variants: { id: number; sku: string }[];
  to_ship: { id: number; number: string };
  to_cancel: { id: number; number: string };
  review: { id: number; title: string };
  question: { id: number; body: string };
  return: { id: number; number: string };
  fresh: { email: string; store_id: number };
  acquirer: { code: string; user_id: number };
  gate: { email: string; store_id: number; version_id: number };
};

export function vendorFixtures(): VendorFixtures {
  const dir = path.join(os.tmpdir(), "nawy-e2e-vendor");
  mkdirSync(dir, { recursive: true });
  const file = path.join(dir, `fixtures-${process.env.VEN_RUN ?? process.ppid}.json`);
  const lock = `${file}.lock`;
  for (let i = 0; i < 240 && !existsSync(file); i++) {
    try {
      mkdirSync(lock);
    } catch {
      execFileSync("sleep", ["0.5"]);
      continue;
    }
    try {
      const php = readFileSync(path.join(__dirname, "setup.php"), "utf8").replace(/^<\?php\s*/, "");
      const run = Date.now().toString(36);
      const out = execFileSync("php", ["artisan", "tinker", "--execute", php], { cwd: BACKEND, encoding: "utf8", env: { ...process.env, VEN_RUN: run } });
      const line = out.split("\n").find((l) => l.startsWith("VENDOR_FIXTURES="));
      if (!line) throw new Error(`vendor setup failed:\n${out}`);
      writeFileSync(file, line.slice("VENDOR_FIXTURES=".length));
    } finally {
      rmSync(lock, { recursive: true, force: true });
    }
  }
  return JSON.parse(readFileSync(file, "utf8")) as VendorFixtures;
}

/** Runs PHP in a country context through `artisan tinker` (test records only); stdout trimmed. */
export function tinkerIn(country: string, php: string): string {
  const wrapped = `app(App\\Domains\\Countries\\Support\\CountryContext::class)->runAs(App\\Domains\\Countries\\Models\\Country::where('code','${country}')->sole(), function () { ${php} });`;
  return execFileSync("php", ["artisan", "tinker", "--execute", wrapped], { cwd: BACKEND, encoding: "utf8" }).trim();
}

/** e2e/docx/shots/{report}/{flow}-{n}.png (full page). */
export function shooter(report: string) {
  const dir = path.join(SHOTS_ROOT, report);
  return async (page: Page, flow: string, n: number | string) => {
    mkdirSync(dir, { recursive: true });
    await page.screenshot({ path: path.join(dir, `${flow}-${n}.png`), fullPage: true, animations: "disabled" });
  };
}

export const sc = (p = "") => panelUrl("partner", p);

/** Runs `fn` while holding a cross-process lock (a directory in the OS temp dir). */
export async function withLock<T>(name: string, fn: () => Promise<T>): Promise<T> {
  const dir = path.join(os.tmpdir(), "nawy-e2e-vendor", `${name}.lock`);
  mkdirSync(path.dirname(dir), { recursive: true });
  for (let i = 0; ; i++) {
    try {
      mkdirSync(dir);
      break;
    } catch {
      if (i > 240) rmSync(dir, { recursive: true, force: true });
      await new Promise((r) => setTimeout(r, 500));
    }
  }
  try {
    return await fn();
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
}

/**
 * Password + emailed 2FA code on a panel's /login. Sign-ins of one email are
 * serialised: two parallel ones would read each other's code from the mail log.
 */
export async function panelSignIn(page: Page, panel: PanelKey, email = PANELS[panel].email, password = PASSWORD) {
  await withLock(`signin-${email}`, () => signInOnce(page, panel, email, password));
}

/**
 * The panels share rate limits per IP (every agent's browser is 127.0.0.1): a
 * 429 or a bounced attempt waits and tries again (at most 4 attempts).
 */
async function signInOnce(page: Page, panel: PanelKey, email: string, password: string) {
  let last = "";
  for (let attempt = 0; attempt < 4; attempt++) {
    if (attempt) await page.waitForTimeout(20_000 * attempt);
    await page.goto(panelUrl(panel, "login"));
    if (!/\/login/.test(page.url())) return; // already signed in
    await page.locator('input[name="email"]').fill(email);
    await page.locator('input[name="password"]').fill(password);
    const mark = mailMark();
    await page.locator('form button[type="submit"]').first().click();
    await page.waitForLoadState("load");
    if (/two-factor/.test(page.url())) {
      const code = await waitForCode(email, mark).catch(() => "");
      if (code) {
        await page.locator('input[name="code"]').fill(code);
        await page.locator('form button[type="submit"]').first().click();
        await page.waitForLoadState("load");
      }
    }
    if (!/two-factor|\/login/.test(page.url())) return;
    last = `${page.url()} :: ${(await page.locator("body").innerText()).slice(0, 300)}`;
  }
  throw new Error(`Sign-in of ${email} failed: ${last}`);
}

/** A context on the saved panel session; signs in again (in memory only) when it expired. */
export async function panelContext(browser: Browser, panel: PanelKey): Promise<{ context: BrowserContext; page: Page }> {
  const context = await browser.newContext({ storageState: panelState(panel) });
  const page = await context.newPage();
  await page.goto(panelUrl(panel));
  if (/\/login/.test(page.url())) await panelSignIn(page, panel);
  return { context, page };
}

/** Goes to `url`, signing the e2e seller in again when the session bounced to /login. */
export async function go(page: Page, url: string, panel: PanelKey = "partner", email?: string) {
  const res = await page.goto(url);
  if (/\/login(\?|$)/.test(page.url())) {
    await panelSignIn(page, panel, email);
    return page.goto(url);
  }
  return res;
}

/** The panel flash message. */
export async function expectFlash(page: Page, text: RegExp | string) {
  await expect(page.locator('main [role="status"]').first()).toContainText(text);
}

/** The error summary / alert. */
export async function expectAlert(page: Page, text: RegExp | string) {
  await expect(page.locator('main [role="alert"]').filter({ hasText: text }).first()).toBeVisible();
}

export async function expectNoServerError(page: Page) {
  const text = await page.locator("body").innerText();
  expect.soft(text).not.toMatch(/Server Error|Whoops|ErrorException|SQLSTATE|Page Expired/);
}

/**
 * Panel card payments through the fake gateway. The panel answers the pay
 * POST with a 302 to https://pay.fake.test/{method}/fake_{method}_{ref}; a
 * browser-followed redirect is not routable in Playwright, so the POST is
 * fetched here without following redirects, the provider's answer is
 * scripted (pending = none yet) and the browser goes on to Laravel's payment
 * return URL, which sends it back to the panel page.
 */
export async function routePayPost(page: Page, pathPattern: RegExp, outcome: "captured" | "failed" | "pending", market = "uae-en") {
  await page.unrouteAll({ behavior: "wait" });
  await page.route((url) => pathPattern.test(url.pathname) && url.hostname.startsWith("partner."), async (route) => {
    if (route.request().method() !== "POST") return route.fallback();
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
    // A captured card payment also leaves a saved card (the gateway's token only), as a real 3-D Secure payment does when asked.
    if (outcome === "captured") {
      execFileSync("php", ["artisan", "tinker", "--execute", `App\\Domains\\Payments\\Support\\Gateways\\FakeGateway::willTokenise("${gatewayReference}", ["token" => "pm_e2e_4242", "last4" => "4242", "brand" => "Visa", "exp_month" => 12, "exp_year" => ${new Date().getFullYear() + 3}, "customer_ref" => "cus_e2e"]);`], { cwd: BACKEND, stdio: "pipe" });
    }
    // Laravel's return URL (it settles the payment and answers with the panel page to show); the browser goes straight there.
    const back = await fetch(`http://localhost:8000/api/v1/${market}/payments/${reference}/return`, { redirect: "manual" });
    await route.fulfill({ status: 302, headers: { location: back.headers.get("location") ?? panelUrl("partner") } });
  });
}

// 1×1 PNG and a small PDF for uploads.
export const PNG = Buffer.from("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=", "base64");
export const PDF = Buffer.from("%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

/** A real 400×400 JPEG made in the browser (the image validator wants real dimensions). */
export async function jpeg(page: Page, color = "#3b82f6"): Promise<Buffer> {
  const data = await page.evaluate((fill) => {
    const canvas = document.createElement("canvas");
    canvas.width = 400;
    canvas.height = 400;
    const ctx = canvas.getContext("2d")!;
    ctx.fillStyle = fill;
    ctx.fillRect(0, 0, 400, 400);
    ctx.fillStyle = "#fff";
    ctx.font = "40px sans-serif";
    ctx.fillText("E2E", 150, 210);
    return canvas.toDataURL("image/jpeg", 0.9).split(",")[1];
  }, color);
  return Buffer.from(data, "base64");
}

export const inDays = (days: number) => new Date(Date.now() + days * 86_400_000).toISOString().slice(0, 10);
/** yyyy-mm-ddThh:mm for datetime-local inputs, `days` from now at 10:00. */
export const localAt = (days: number, hour = 10) => `${inDays(days)}T${String(hour).padStart(2, "0")}:00`;

/** GET `url` from inside the page (same panel origin and session): status, content type, size and the first bytes. */
export async function fetchIn(page: Page, url: string) {
  return page.evaluate(async (u) => {
    const res = await fetch(u, { credentials: "include" });
    const buf = new Uint8Array(await res.arrayBuffer());
    return { status: res.status, type: res.headers.get("content-type") ?? "", disposition: res.headers.get("content-disposition") ?? "", size: buf.length, head: String.fromCharCode(...buf.slice(0, 5)), text: new TextDecoder().decode(buf.slice(0, 200_000)) };
  }, url);
}

/**
 * Dev only: uploaded images are served from APP_URL (http://localhost:8000/storage/…)
 * while the panel CSP allows `img-src 'self' https:`; on the https production
 * hosts they load. Those console lines are dropped (noted in the report).
 */
/**
 * The 429s of the shared per-IP sign-in limits (`login`, `two-factor`: throttle 6/min, every e2e browser
 * is 127.0.0.1) that `panelSignIn` waited out and retried. Only those two panel URLs, only status 429.
 */
export function dropRetriedSignIn429(problems: { console: string[] }) {
  problems.console = problems.console.filter((m) => !/^http:\/\/[a-z]+\.nawy\.test:8000\/(login|two-factor) :: Failed to load resource: the server responded with a status of 429 /.test(m));
}

export function dropDevImageCsp(problems: { console: string[] }) {
  problems.console = problems.console.filter((m) => !/Loading the image 'http:\/\/localhost:8000\/storage\/.*Content Security Policy/.test(m));
}

/** A new browser context signed in to Seller Center as `email` (password + emailed 2FA code). */
export async function signedInAs(browser: Browser, email: string, password = PASSWORD): Promise<{ context: BrowserContext; page: Page }> {
  const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  const page = await context.newPage();
  await page.goto(panelUrl("partner", "login"));
  await panelSignIn(page, "partner", email, password);
  return { context, page };
}
