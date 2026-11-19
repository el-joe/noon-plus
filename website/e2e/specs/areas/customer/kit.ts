import { test, expect, type Browser, type BrowserContext, type Page } from "@playwright/test";
import { appendFileSync, mkdirSync } from "node:fs";
import path from "node:path";
import { PASSWORD, panelState, panelUrl, type PanelKey } from "../../../support/accounts";
import { mailMark, waitForCode, waitForMail } from "../../../support/mail";
import { formOf, rejectOptionalCookies, tinker } from "../../../support/storefront";

/**
 * Customer-side browser test kit (reports customer-profile-and-account,
 * website-pages, checkout, order-lifecycle-and-delivery). Each test is one
 * flow (CUS/WEB/CHK/ORD-xx) of recorded steps: every step runs, takes a
 * 1280×800 screenshot under e2e/docx/shots/<report>/ and appends a line to
 * e2e/docx/reports/_log/<report>.jsonl. e2e/docx/customer-report.mjs turns
 * the log (latest run per flow) into the report JSON, so a report only
 * shows steps that really ran.
 */
export type Report = "customer-profile-and-account" | "website-pages" | "checkout" | "order-lifecycle-and-delivery";

const E2E_ROOT = path.resolve(__dirname, "../../..");
const SHOTS = path.join(E2E_ROOT, "docx/shots");
const LOG = path.join(E2E_ROOT, "docx/reports/_log");
export const API = "http://localhost:8000/api/v1";
export const MARKET = "uae-en";

/** Unique per worker, so data created here never collides with other agents' runs. */
export const RUN = `${Date.now().toString(36).slice(-5)}${Math.floor(Math.random() * 36 * 36).toString(36)}`;
let seq = 0;
export const uniqueEmail = (tag: string) => `qa-${tag}-${RUN}${++seq}@nawy.test`;

function log(report: Report, record: Record<string, unknown>) {
  mkdirSync(LOG, { recursive: true });
  appendFileSync(path.join(LOG, `${report}.jsonl`), `${JSON.stringify({ ...record, ts: Date.now() })}\n`);
}

/** Records the outcome of every test in the file (feeds the report totals) and rejects optional cookies. */
export function setup(report: Report) {
  test.beforeEach(async ({ page }) => rejectOptionalCookies(page));
  test.afterEach(async ({}, info) => {
    const id = info.annotations.find((a) => a.type === "flow")?.description;
    log(report, {
      type: "test", report, id, title: info.title, spec: path.relative(path.resolve(E2E_ROOT, ".."), info.file),
      status: info.status, expected: info.expectedStatus, duration: info.duration,
      error: info.error?.message?.replace(/\u001b\[\d+m/g, "").split("\n").slice(0, 2).join(" ").slice(0, 400),
    });
  });
}

export class Flow {
  private n = 0;
  failures: string[] = [];
  constructor(public page: Page, private report: Report, public id: string) {}

  /**
   * One recorded step. `fn` may return a string: it becomes the step note.
   * A failed step is recorded, then thrown (the flow stops) unless `soft`,
   * in which case the flow goes on and `end()` fails the test.
   */
  async step(action: string, expected: string, fn: () => Promise<string | void>, opts: { page?: Page; fullPage?: boolean; soft?: boolean; shot?: boolean } = {}) {
    const n = ++this.n;
    let result: "pass" | "fail" = "pass";
    let note = "";
    let error: unknown;
    await test.step(`${this.id}.${n} ${action}`, async () => {
      try {
        note = (await fn()) ?? "";
      } catch (e) {
        result = "fail";
        error = e;
        note = String((e as Error)?.message ?? e).replace(/\u001b\[\d+m/g, "").split("\n").filter(Boolean).slice(0, 4).join(" ").slice(0, 500);
      }
    });
    const page = opts.page ?? this.page;
    let screenshot: string | undefined;
    if (opts.shot !== false) {
      const rel = `${this.report}/${this.id.toLowerCase()}-${n}.png`;
      mkdirSync(path.join(SHOTS, this.report), { recursive: true });
      const ok = await page.screenshot({ path: path.join(SHOTS, rel), fullPage: opts.fullPage ?? false, animations: "disabled", timeout: 10_000 }).then(() => true).catch(() => false);
      if (ok) screenshot = `../shots/${rel}`;
    }
    log(this.report, { type: "step", report: this.report, id: this.id, n, action, expected, result, note, screenshot });
    if (error) {
      if (opts.soft) this.failures.push(`${this.id}.${n} ${action}: ${note}`);
      else throw error;
    }
  }

  /** A step that could not run in dev (recorded as skipped, with the reason). */
  skip(action: string, expected: string, reason: string) {
    const n = ++this.n;
    log(this.report, { type: "step", report: this.report, id: this.id, n, action, expected, result: "skip", note: reason });
  }

  /** Fails the test when a soft step failed. */
  end() {
    expect(this.failures, "failed steps").toEqual([]);
  }
}

export function flow(page: Page, report: Report, id: string, name: string, goal: string, preconditions = "Guest browser (optional cookies rejected).") {
  const info = test.info();
  info.annotations.push({ type: "flow", description: id });
  log(report, { type: "flow", report, id, name, goal, preconditions, spec: path.relative(path.resolve(E2E_ROOT, ".."), info.file), title: info.title });
  return new Flow(page, report, id);
}

/** Lets client components hydrate and fetch: network idle, bounded. */
export async function settle(page: Page, ms = 6_000) {
  await page.waitForLoadState("load");
  await page.waitForLoadState("networkidle", { timeout: ms }).catch(() => undefined);
}

/** Opens a storefront page, expects the status and no Next.js error screen. */
export async function visit(page: Page, p: string, status = 200) {
  const res = await page.goto(p.startsWith("http") ? p : `/${MARKET}${p === "/" ? "" : p}`.replace(/^\/uae-en\/(uae|omn|ksa|egy|kwt|qat|bhr|jor)-/, "/$1-"), { waitUntil: "load" });
  expect(res?.status(), `HTTP status of ${p}`).toBe(status);
  await settle(page);
  const text = await page.locator("body").innerText();
  expect(text, `error screen on ${page.url()}`).not.toMatch(/Application error|Unhandled Runtime Error|digest:/);
  return res;
}

export const main = (page: Page) => page.locator("main");

/**
 * Email-code sign-in / sign-up on /{market}/login (the code comes from the
 * Laravel mail log). A new email creates the account.
 */
export async function signInWithCode(page: Page, email: string, market = MARKET, next?: string) {
  await page.goto(`/${market}/login${next ? `?next=${encodeURIComponent(next)}` : ""}`);
  await page.locator('input[type="email"]').fill(email);
  const mark = mailMark();
  await formOf(page, 'input[type="email"]').locator('button[type="submit"]').click();
  const code = await waitForCode(email, mark);
  await page.locator('input[autocomplete="one-time-code"]').fill(code);
  await formOf(page, 'input[autocomplete="one-time-code"]').locator('button[type="submit"]').click();
  await expect(page).not.toHaveURL(/\/login/, { timeout: 20_000 });
  return code;
}

/**
 * A brand-new shopper, signed in in this browser context. The account and its
 * token are made with artisan tinker (SignInCustomer, the same action the
 * email-code sign-in ends with): the email-code endpoint allows 5 requests a
 * minute per IP, shared by every QA agent on 127.0.0.1. Sign-up through the
 * UI itself is covered by CUS-01.
 */
export async function newCustomer(page: Page, tag = "cust", opts: { password?: boolean } = {}) {
  const { email, token } = createCustomer(tag, opts);
  const cookie = (name: string, value: string, httpOnly: boolean) => ({ name, value, domain: "localhost", path: "/", expires: Math.floor(Date.now() / 1000) + 86_400, httpOnly, secure: false, sameSite: "Lax" as const });
  await page.context().addCookies([cookie("nawy_token", token, true), cookie("nawy_signed_in", "1", false)]);
  return email;
}

/** A shopper account made with artisan tinker (optionally with the e2e password); returns its email and an API token. */
export function createCustomer(tag = "cust", opts: { password?: boolean } = {}) {
  const email = uniqueEmail(tag);
  // Code-only accounts are made like the email-code sign-up (User::createPasswordless: random password, password_set_at null).
  const pw = opts.password ? `$u->forceFill(["password" => bcrypt("${PASSWORD}"), "password_set_at" => now()]);` : "";
  const out = tinker(`$u = App\\Models\\User::createPasswordless("${email}", "QA ${tag} ${RUN}", verified: true); ${pw} $u->forceFill(["preferred_locale" => "en"])->save(); echo "TOKEN=".app(App\\Domains\\Identity\\Actions\\SignInCustomer::class)->handle($u, "qa-e2e");`);
  const token = /TOKEN=(\S+)/.exec(out)?.[1];
  if (!token) throw new Error(`could not create ${email}: ${out.slice(0, 300)}`);
  return { email, token };
}

/** The storefront session token (httpOnly cookie) for API calls as the signed-in shopper. */
export async function tokenOf(page: Page) {
  return (await page.context().cookies()).find((c) => c.name === "nawy_token")?.value ?? "";
}

export async function api(page: Page, method: "GET" | "POST" | "DELETE" | "PATCH" | "PUT", p: string, data?: unknown) {
  const token = await tokenOf(page);
  const res = await page.request.fetch(`${API}/${MARKET}${p}`, {
    method, data: data as never,
    headers: { Accept: "application/json", ...(token ? { Authorization: `Bearer ${token}` } : {}) },
  });
  const body = await res.text();
  let json: any = null;
  try { json = JSON.parse(body); } catch { /* not JSON */ }
  return { status: res.status(), json, body };
}

/** Panel page in a separate context: the stored session if it still works, else password + emailed 2FA. */
export async function panel(browser: Browser, key: PanelKey, email: string): Promise<{ context: BrowserContext; page: Page }> {
  let context = await browser.newContext({ storageState: panelState(key), viewport: { width: 1280, height: 800 } });
  let page = await context.newPage();
  await page.goto(panelUrl(key, "/"));
  if (!/\/login|two-factor/.test(page.url())) return { context, page };
  await context.close();
  context = await browser.newContext({ viewport: { width: 1280, height: 800 } });
  page = await context.newPage();
  await page.goto(panelUrl(key, "login"));
  await page.locator('input[name="email"]').fill(email);
  await page.locator('input[name="password"]').fill(PASSWORD);
  const mark = mailMark();
  await page.locator('form button[type="submit"]').first().click();
  await expect(page).toHaveURL(/two-factor/);
  await page.locator('input[name="code"]').fill(await waitForCode(email, mark));
  await page.locator('form button[type="submit"]').first().click();
  await expect(page).not.toHaveURL(/two-factor|login/);
  return { context, page };
}

/** Runs PHP inside the UAE country context through artisan tinker (test records only); returns stdout. */
export function uaePhp(php: string): string {
  return tinker(`app(App\\Domains\\Countries\\Support\\CountryContext::class)->runAs(App\\Domains\\Countries\\Models\\Country::where("code","uae")->firstOrFail(), function () { ${php} });`).trim();
}

export { test, expect, mailMark, waitForCode, waitForMail, formOf, PASSWORD, tinker as tinkerPhp };
export type { Page, BrowserContext };
