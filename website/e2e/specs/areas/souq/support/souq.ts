import { execFileSync } from "node:child_process";
import { existsSync, mkdirSync, readFileSync, rmSync, statSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";
import type { Browser, BrowserContext, Page } from "@playwright/test";
import { expect } from "../../../../support/fixtures";
import { BASE_DOMAIN, API_PORT, PASSWORD } from "../../../../support/accounts";
import { mailMark, waitForCode } from "../../../../support/mail";

/**
 * Open Souq browser-test helpers. `souq()` runs support/setup.php once per
 * run through `artisan tinker` (idempotent test records: a classified shop
 * seller, a broker, a contract category, a 2-photo category and three live
 * ads) and caches the JSON it prints.
 */
const BACKEND = path.resolve(__dirname, "../../../../../../backend");
const CACHE = path.join(os.tmpdir(), "nawy-e2e-souq");
export const API = (process.env.E2E_API_URL ?? "http://localhost:8000") + "/api/v1/uae-en";
export const SHOTS = path.resolve(__dirname, "../../../../docx/shots/open-souq");

export type SouqFixtures = {
  seller: { email: string; shop_slug: string; shop_id: number };
  broker: { email: string; profile_id: number; slug: string };
  categories: { contract: string; contract_id: number; few: string; few_id: number };
  ads: { shop: string; broker: string; contract: string };
};

export function souq(): SouqFixtures {
  mkdirSync(CACHE, { recursive: true });
  const file = path.join(CACHE, "fixtures.json");
  // Re-run the setup when the cache is older than 10 minutes (one run of the suite).
  if (!existsSync(file) || Date.now() - statSync(file).mtimeMs > 10 * 60_000) {
    const php = readFileSync(path.join(__dirname, "setup.php"), "utf8").replace(/^<\?php\s*/, "");
    const out = execFileSync("php", ["artisan", "tinker", "--execute", php], { cwd: BACKEND, encoding: "utf8" });
    const line = out.split("\n").find((l) => l.startsWith("SOUQ_FIXTURES="));
    if (!line) throw new Error(`souq setup failed:\n${out}`);
    writeFileSync(file, line.slice("SOUQ_FIXTURES=".length));
  }
  return JSON.parse(readFileSync(file, "utf8")) as SouqFixtures;
}

/** Runs `php artisan tinker --execute` in the uae country context (test records only). */
export function tinkerUae(php: string): string {
  const wrapped = `app(App\\Domains\\Countries\\Support\\CountryContext::class)->runAs(App\\Domains\\Countries\\Models\\Country::where('code','uae')->sole(), function () { ${php} });`;
  return execFileSync("php", ["artisan", "tinker", "--execute", wrapped], { cwd: BACKEND, encoding: "utf8" });
}

export const shot = (page: Page, flow: string, n: number, fullPage = true) =>
  page.screenshot({ path: path.join(SHOTS, `${flow}-${n}.png`), fullPage, animations: "disabled" });

export const customerToken = () => readFileSync(path.resolve(__dirname, "../../../../.generated/customer-token.txt"), "utf8").trim();

export const panel = (name: "partner" | "marketer" | "admin", p = "") => `http://${name}.${BASE_DOMAIN}:${API_PORT}/${p.replace(/^\//, "")}`;

/**
 * Signs a 2FA panel user in (password + emailed code from the Laravel log)
 * and keeps the session in the OS temp dir for the rest of the run (the
 * shared e2e/.auth files are not touched).
 */
export async function panelContext(browser: Browser, host: "partner" | "marketer", email: string): Promise<BrowserContext> {
  mkdirSync(CACHE, { recursive: true });
  // One sign-in at a time per user: a second emailed code would replace the first.
  const lock = path.join(CACHE, `${host}-${email}.lock`);
  for (let i = 0; ; i++) {
    try {
      mkdirSync(lock);
      break;
    } catch {
      if (i > 240) rmSync(lock, { recursive: true, force: true });
      await new Promise((r) => setTimeout(r, 250));
    }
  }
  try {
    return await signedInPanel(browser, host, email);
  } finally {
    rmSync(lock, { recursive: true, force: true });
  }
}

async function signedInPanel(browser: Browser, host: "partner" | "marketer", email: string): Promise<BrowserContext> {
  // One session per Playwright worker: tests of one worker run one after another, so a flash
  // message ("Reply sent.") is never consumed by a parallel test sharing the session.
  const state = path.join(CACHE, `${host}-${email}-w${process.env.TEST_WORKER_INDEX ?? "0"}.json`);
  if (existsSync(state)) {
    const ctx = await browser.newContext({ storageState: state });
    const page = await ctx.newPage();
    await page.goto(panel(host));
    const ok = !/\/(login|two-factor)/.test(page.url());
    await page.close();
    if (ok) return ctx;
    await ctx.close();
  }
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  await page.goto(panel(host, "login"));
  await page.locator('input[name="email"]').fill(email);
  await page.locator('input[name="password"]').fill(PASSWORD);
  const mark = mailMark();
  await page.locator('form button[type="submit"]').first().click();
  await expect(page).toHaveURL(/two-factor/);
  const code = await waitForCode(email, mark);
  await page.locator('input[name="code"]').fill(code);
  await page.locator('form button[type="submit"]').first().click();
  await expect(page).not.toHaveURL(/two-factor|login/);
  await ctx.storageState({ path: state });
  await page.close();
  return ctx;
}

/** A tiny valid PNG of the given colour (photo uploads). */
export function png(name: string, rgb: [number, number, number] = [200, 40, 40]) {
  // 2x2 truecolour PNG built by hand (zlib stored blocks).
  const zlib = require("node:zlib") as typeof import("node:zlib");
  const crcTable = Array.from({ length: 256 }, (_, n) => {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    return c >>> 0;
  });
  const crc = (buf: Buffer) => {
    let c = 0xffffffff;
    for (const b of buf) c = crcTable[(c ^ b) & 0xff] ^ (c >>> 8);
    return (c ^ 0xffffffff) >>> 0;
  };
  const chunk = (type: string, data: Buffer) => {
    const len = Buffer.alloc(4);
    len.writeUInt32BE(data.length);
    const td = Buffer.concat([Buffer.from(type), data]);
    const c = Buffer.alloc(4);
    c.writeUInt32BE(crc(td));
    return Buffer.concat([len, td, c]);
  };
  const w = 64, h = 48;
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(w, 0);
  ihdr.writeUInt32BE(h, 4);
  ihdr[8] = 8; ihdr[9] = 2; ihdr[10] = 0; ihdr[11] = 0; ihdr[12] = 0;
  const row = Buffer.concat([Buffer.from([0]), Buffer.alloc(w * 3).map((_, i) => rgb[i % 3])]);
  const raw = Buffer.concat(Array.from({ length: h }, () => row));
  const buffer = Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk("IHDR", ihdr), chunk("IDAT", zlib.deflateSync(raw)), chunk("IEND", Buffer.alloc(0))]);
  return { name, mimeType: "image/png", buffer };
}

export const runId = () => `${Date.now().toString(36)}${Math.floor(Math.random() * 1e3)}`;

/**
 * A fresh customer (test account, created through tinker) signed in on the
 * storefront with an API token cookie — the shared e2e customer has already
 * accepted the e2e buyer terms, and the email-code sign-in is rate limited
 * on the shared loopback address.
 */
export async function freshCustomer(page: Page, email: string) {
  const php = `$u = App\\Models\\User::query()->firstOrNew(['email' => '${email}']); $u->forceFill(['name' => 'E2E Souq Buyer', 'password' => 'E2e-Passw0rd!', 'email_verified_at' => now(), 'status' => 'active'])->save(); $u->memberships()->firstOrCreate(['role' => App\\Domains\\Identity\\Enums\\MembershipRole::Customer, 'country_id' => App\\Domains\\Countries\\Models\\Country::where('code','uae')->value('id')], ['status' => 'active']); echo 'TOKEN='.$u->createToken('e2e-souq')->plainTextToken.PHP_EOL;`;
  if (!/^[a-z0-9@.-]+$/.test(email)) throw new Error("bad email");
  const out = execFileSync("php", ["artisan", "tinker", "--execute", php], { cwd: BACKEND, encoding: "utf8" });
  const token = /TOKEN=(\S+)/.exec(out)?.[1];
  if (!token) throw new Error(`no token: ${out}`);
  const cookie = (name: string, value: string, httpOnly: boolean) => ({ name, value, domain: "localhost", path: "/", httpOnly, secure: false, sameSite: "Lax" as const });
  await page.context().addCookies([cookie("nawy_token", token, true), cookie("nawy_signed_in", "1", false)]);
}
