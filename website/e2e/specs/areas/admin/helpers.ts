import { test as base, expect, type Browser, type BrowserContext, type Locator, type Page, type TestInfo } from "@playwright/test";
import { appendFileSync, existsSync, mkdirSync, rmSync } from "node:fs";
import os from "node:os";
import path from "node:path";
import { PANELS, PASSWORD, panelState, panelUrl, type PanelKey } from "../../../support/accounts";
import { mailMark, waitForCode, waitForMail } from "../../../support/mail";

/**
 * Admin-panel browser test kit. Each test is one flow (ADM-xx) made of
 * recorded steps: every step runs, takes a screenshot under
 * e2e/docx/shots/<report>/ and appends a line to e2e/docx/reports/_log/<report>.jsonl.
 * e2e/docx/admin-report.mjs turns the log (latest run per flow) into the
 * report JSON for build-docx.mjs, so a report only shows steps that really ran.
 */
export type Report =
  | "admin-panel-catalog-and-vendors"
  | "admin-panel-orders-delivery-and-fulfilment"
  | "admin-panel-finance"
  | "admin-panel-marketing-content-and-souq"
  | "admin-panel-system-countries-staff-and-migration";

const E2E_ROOT = path.resolve(__dirname, "../../..");
const SHOTS = path.join(E2E_ROOT, "docx/shots");
const LOG = path.join(E2E_ROOT, "docx/reports/_log");

/** Unique per worker, so data created here never collides with other agents' runs. */
export const RUN = `${Date.now().toString(36).slice(-5)}${Math.floor(Math.random() * 36 * 36).toString(36)}`;

export const ADMIN = (p = "") => panelUrl("admin", p);
export const ADMIN_STATE = panelState("admin");
export const ADMIN_EMAIL = PANELS.admin.email;

/*
 * Each worker signs the e2e admin in once with its own session (password + emailed code)
 * and keeps it in the OS temp dir for later runs. Sharing one session between parallel
 * workers (or other suites) loses flash messages and validation errors to the other
 * requests, so the shared .auth/admin.json is never used here (and never rewritten).
 */
/** Playwright Test applies `use` options (incl. storageState) to browser.newContext(); a fresh context must say so. */
export const FRESH = { storageState: { cookies: [], origins: [] } };

const SESSION_DIR = path.join(os.tmpdir(), "nawy-admin-qa-sessions");

async function withLock<T>(fn: () => Promise<T>): Promise<T> {
  const lock = path.join(SESSION_DIR, "signin.lock");
  mkdirSync(SESSION_DIR, { recursive: true });
  const end = Date.now() + 120_000;
  for (;;) {
    try { mkdirSync(lock); break; } catch {
      if (Date.now() > end) { rmSync(lock, { recursive: true, force: true }); continue; }
      await new Promise((r) => setTimeout(r, 300));
    }
  }
  try { return await fn(); } finally { rmSync(lock, { recursive: true, force: true }); }
}

/** A cached, signed-in session of `panel` for this worker (own session, never the shared .auth file). */
export async function panelSession(browser: Browser, panel: PanelKey, slot: number): Promise<string> {
  const file = path.join(SESSION_DIR, `${panel}-${slot}.json`);
  let valid = false;
  if (existsSync(file)) {
    const ctx = await browser.newContext({ storageState: file });
    const pg = await ctx.newPage();
    await pg.goto(panelUrl(panel, "/")).catch(() => undefined);
    valid = !/login|two-factor/.test(pg.url()) && (await pg.locator("#panel-sidebar").count()) > 0;
    await ctx.close();
  }
  if (!valid) {
    await withLock(async () => {
      const { context } = await signIn(browser, PANELS[panel].email, PASSWORD, panel);
      await context.storageState({ path: file });
      await context.close();
    });
  }
  return file;
}

export const test = base.extend<{}, { adminState: string }>({
  adminState: [async ({ browser }, use, workerInfo) => use(await panelSession(browser, "admin", workerInfo.parallelIndex)), { scope: "worker" }],
  storageState: async ({ adminState }, use) => use(adminState),
});

/** Opens a page in this worker's own seller (or other panel) session. */
export async function panelPage(browser: Browser, panel: PanelKey) {
  const context = await browser.newContext({ storageState: await panelSession(browser, panel, test.info().parallelIndex) });
  return { context, page: await context.newPage() };
}

/** Server-side validation answered (field message or action banner). */
export async function expectValidation(page: Page, re: RegExp = /required|must|invalid|already been taken|may not|not be greater|at least|is not|select|choose|cannot|can't/i) {
  await expect(page.locator("main")).toContainText(re);
}

function log(report: Report, record: Record<string, unknown>) {
  mkdirSync(LOG, { recursive: true });
  appendFileSync(path.join(LOG, `${report}.jsonl`), `${JSON.stringify({ ...record, ts: Date.now() })}\n`);
}

/** Records the test outcome of every flow in this file (counts feed the report totals). */
export function recordOutcomes(report: Report) {
  test.afterEach(async ({}, info) => {
    const id = info.annotations.find((a) => a.type === "flow")?.description;
    log(report, {
      type: "test", report, id, title: info.title, spec: path.relative(path.resolve(E2E_ROOT, ".."), info.file),
      status: info.status, expected: info.expectedStatus,
      error: info.error?.message?.split("\n")[0]?.slice(0, 400),
    });
  });
}

export class Flow {
  private n = 0;
  constructor(public page: Page, private report: Report, public id: string) {}

  /**
   * One recorded step. `fn` may return a string: it becomes the step note.
   * Throws on failure after recording it (the flow stops there).
   */
  async step(action: string, expected: string, fn: () => Promise<string | void>, opts: { page?: Page; fullPage?: boolean } = {}) {
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
        note = String((e as Error)?.message ?? e).split("\n").slice(0, 3).join(" ").slice(0, 400);
      }
    });
    const page = opts.page ?? this.page;
    const rel = `${this.report}/${this.id.toLowerCase()}-${n}.png`;
    mkdirSync(path.join(SHOTS, this.report), { recursive: true });
    await page.screenshot({ path: path.join(SHOTS, rel), fullPage: opts.fullPage ?? false, animations: "disabled" }).catch(() => undefined);
    log(this.report, { type: "step", report: this.report, id: this.id, n, action, expected, result, note, screenshot: `../shots/${rel}` });
    if (error) throw error;
  }

  /** A step that could not run in dev (recorded as skipped, with the reason). */
  skip(action: string, expected: string, reason: string) {
    const n = ++this.n;
    log(this.report, { type: "step", report: this.report, id: this.id, n, action, expected, result: "skip", note: reason });
  }
}

export function flow(page: Page, report: Report, id: string, name: string, goal: string, preconditions = "Signed in as the e2e super admin (e2e-admin@nawy.test) in its own session (password + emailed two-step code).") {
  const info = test.info();
  info.annotations.push({ type: "flow", description: id });
  log(report, { type: "flow", report, id, name, goal, preconditions, spec: path.relative(path.resolve(E2E_ROOT, ".."), info.file), title: info.title });
  return new Flow(page, report, id);
}

/** Opens an admin page and checks it rendered (200, no server error text, sidebar present). */
export async function open(page: Page, p: string, status = 200) {
  const res = await page.goto(p.startsWith("http") ? p : ADMIN(p), { waitUntil: "load" });
  expect(res?.status(), `HTTP status of ${p}`).toBe(status);
  if (status === 200) await expectHealthy(page);
  return res;
}

export async function expectHealthy(page: Page) {
  const text = await page.locator("body").innerText();
  expect(text, `server error on ${page.url()}`).not.toMatch(/Server Error|Internal Server Error|Whoops|ErrorException|SQLSTATE|Undefined (variable|array key)/);
  expect(text, `raw translation keys on ${page.url()}`).not.toMatch(/\b(?:validation|passwords|auth|pagination|panels?|messages)\.[a-z_]+\.[a-z_]+\b/);
}

/** Main content area (excludes sidebar/header). */
export const main = (page: Page) => page.locator("main");

/** The success flash (`session('status')`). */
export const flash = (page: Page) => page.locator('main [role="status"]').first();
/** The action error banner (`@error('action')`). */
export const alertBox = (page: Page) => page.locator('main [role="alert"]').first();
/** Field-level validation messages. */
export const fieldErrors = (page: Page) => page.locator("main .text-fg-danger, main [aria-invalid=true]");

/** Form by (partial) action path and method override, e.g. form(page, "/catalog/brands"). */
export function form(page: Page, actionEndsWith: string, scope?: Locator): Locator {
  return (scope ?? page.locator("main")).locator(`form[action$="${actionEndsWith}"]`);
}

/** Submits a form with browser validation off, so the server-side rules answer. */
export async function submitNoValidate(f: Locator, button?: string | RegExp) {
  await f.evaluate((el) => (el as HTMLFormElement).setAttribute("novalidate", ""));
  const btn = button ? f.getByRole("button", { name: button }) : f.locator('button[type="submit"], button:not([type])').last();
  await Promise.all([f.page().waitForLoadState("load"), btn.first().click()]);
}

/** Clicks a submit button and accepts any confirm() dialog. */
export async function submit(f: Locator, button?: string | RegExp) {
  const page = f.page();
  page.once("dialog", (d) => d.accept().catch(() => undefined));
  const btn = button ? f.getByRole("button", { name: button }) : f.locator('button[type="submit"], button:not([type])').last();
  await Promise.all([page.waitForLoadState("load"), btn.first().click()]);
}

/** First link in main whose href matches (e.g. a row's detail link). */
export async function firstDetailHref(page: Page, re: RegExp): Promise<string | null> {
  const hrefs = await page.locator("main a[href]").evaluateAll((as) => as.map((a) => (a as HTMLAnchorElement).href));
  return hrefs.find((h) => re.test(new URL(h).pathname)) ?? null;
}

/** Sidebar link texts of the current page. */
export async function sidebarLinks(page: Page): Promise<string[]> {
  return page.locator("#panel-sidebar a[href]").evaluateAll((as) => as.map((a) => (a as HTMLAnchorElement).pathname.replace(/\/$/, "") || "/"));
}

/** Signs a panel user in with password + emailed 2FA in a fresh context (never touches .auth files). */
export async function signIn(browser: Browser, email: string, password = PASSWORD, panel: PanelKey = "admin"): Promise<{ context: BrowserContext; page: Page }> {
  const context = await browser.newContext(FRESH);
  const page = await context.newPage();
  await page.goto(panelUrl(panel, "login"));
  await page.locator('input[name="email"]').fill(email);
  await page.locator('input[name="password"]').fill(password);
  const mark = mailMark();
  await Promise.all([page.waitForLoadState("load"), page.locator('form[action$="/login"] button[type="submit"]').first().click()]);
  await page.waitForURL((u) => !u.pathname.endsWith("/login"), { timeout: 15_000 });
  if (!/two-factor/.test(page.url())) return { context, page };
  const code = await waitForCode(email, mark);
  await page.locator('input[name="code"]').fill(code);
  await page.locator('form[action$="/two-factor"] button[type="submit"]').first().click();
  await expect(page).not.toHaveURL(/two-factor|login/);
  return { context, page };
}

export { expect, mailMark, waitForMail, waitForCode, PANELS, PASSWORD };
export type { Page, Locator, TestInfo };

/**
 * Invites a platform staff member through Staff → Send invitation, opens the emailed
 * link and accepts it with a password. Returns the new account's email.
 */
export async function inviteStaff(page: Page, browser: Browser, role: string, label: string): Promise<string> {
  const email = `qa-${label}-${RUN}-${Math.floor(Math.random() * 1e6)}@nawy.test`.toLowerCase();
  await open(page, "/staff");
  const fm = form(page, "/staff/invitations");
  await fm.locator('[name="name"]').fill(`QA ${label} ${RUN}`);
  await fm.locator('[name="email"]').fill(email);
  await fm.locator('select[name="role"]').selectOption(role);
  const mark = mailMark();
  await submit(fm);
  const mail = await waitForMail(email, mark, /team-invitations/);
  const link = /https?:\/\/[^\s"<>]*team-invitations\/[a-z]+\/[A-Za-z0-9]{40,64}/.exec(mail)?.[0];
  if (!link) throw new Error("no invitation link in the mail");
  const ctx = await browser.newContext(FRESH);
  const p = await ctx.newPage();
  await p.goto(link.replace(/^https:/, "http:"));
  if (await p.locator('input[name="password"]').count()) {
    await p.locator('input[name="password"]').fill(PASSWORD);
    await p.locator('input[name="password_confirmation"]').fill(PASSWORD);
  }
  await Promise.all([p.waitForLoadState("load"), p.locator('form[action*="team-invitations"] button[type=submit]').first().click()]);
  await expect(p).toHaveURL(/login/);
  await ctx.close();
  return email;
}

/** Staff option values available as account manager (invites one if none exists). */
export async function ensureAccountManager(page: Page, browser: Browser): Promise<string> {
  await open(page, "/staff?role=account_manager&status=active");
  const rows = await page.locator("main tbody tr").filter({ hasText: "@" }).count();
  if (rows === 0) return inviteStaff(page, browser, "account_manager", "am");
  return (await page.locator("main tbody tr").filter({ hasText: "@" }).first().innerText()).match(/\S+@\S+/)?.[0] ?? "";
}

/** Fetches a file through the page (same origin, signed-in cookies); returns status, type and the first bytes. */
export async function fetchFile(page: Page, url: string) {
  return page.evaluate(async (u) => {
    const r = await fetch(u, { credentials: "include" });
    const b = new Uint8Array(await r.arrayBuffer());
    return { status: r.status, type: r.headers.get("content-type") ?? "", head: String.fromCharCode(...b.slice(0, 5)), size: b.length, disposition: r.headers.get("content-disposition") ?? "" };
  }, url);
}
