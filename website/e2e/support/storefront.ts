import { execFileSync } from "node:child_process";
import path from "node:path";
import type { Page } from "@playwright/test";
import { expect } from "./fixtures";
import { mailMark, waitForCode } from "./mail";

const BACKEND = path.resolve(__dirname, "../../../backend");

/** Email-code sign-in on /{market}/login (D9): the code is read from the Laravel mail log. */
export async function signInWithCode(page: Page, market: string, email: string, next?: string) {
  await page.goto(`/${market}/login${next ? `?next=${encodeURIComponent(next)}` : ""}`);
  await page.locator('input[type="email"]').fill(email);
  const mark = mailMark();
  await formOf(page, 'input[type="email"]').locator('button[type="submit"]').click();
  const code = await waitForCode(email, mark);
  await page.locator('input[autocomplete="one-time-code"]').fill(code);
  await formOf(page, 'input[autocomplete="one-time-code"]').locator('button[type="submit"]').click();
  await expect(page).not.toHaveURL(/\/login/, { timeout: 20_000 });
}

/** The <form> holding `selector` (the header search is a form too). */
export const formOf = (page: Page, selector: string) => page.locator("form").filter({ has: page.locator(selector) });

/** Essential cookies only (the consent banner otherwise covers the bottom of every page). */
export async function rejectOptionalCookies(page: Page) {
  await page.context().addCookies([{ name: "nawy_consent", value: "v1:a0p0d0", domain: "localhost", path: "/" }]);
}

/** Password sign-in on /{market}/login. */
export async function signInWithPassword(page: Page, market: string, email: string, password: string, next?: string) {
  await page.goto(`/${market}/login${next ? `?next=${encodeURIComponent(next)}` : ""}`);
  await page.locator('input[type="email"]').fill(email);
  await page.getByRole("button", { name: market.endsWith("-ar") ? /كلمة المرور/ : /Sign in with password/ }).click();
  await page.locator('input[type="password"]').fill(password);
  await formOf(page, 'input[type="password"]').locator('button[type="submit"]').click();
  await expect(page).not.toHaveURL(/\/login/, { timeout: 20_000 });
}

export async function isSignedIn(page: Page) {
  return (await page.context().cookies()).some((c) => c.name === "nawy_signed_in" && c.value === "1");
}

/**
 * The fake payment gateway (NAWY_PAYMENTS_DRIVER=fake) sends the shopper to
 * https://pay.fake.test/{method}/fake_{method}_{reference}. Stand in for that
 * page: script the outcome the gateway reports, then go to Laravel's return URL.
 */
export async function routeFakeGateway(page: Page, market: string, outcome: "captured" | "failed" = "captured") {
  await page.route("https://pay.fake.test/**", async (route) => {
    const [, method, gatewayReference] = new URL(route.request().url()).pathname.split("/");
    const reference = gatewayReference.replace(`fake_${method}_`, "");
    fakeGatewayReports(gatewayReference, outcome);
    await route.fulfill({ status: 302, headers: { location: `${process.env.E2E_API_URL ?? "http://localhost:8000"}/api/v1/${market}/payments/${reference}/return` } });
  });
}

export function fakeGatewayReports(gatewayReference: string, outcome: string) {
  if (!/^[A-Za-z0-9_-]+$/.test(gatewayReference) || !/^[a-z_]+$/.test(outcome)) throw new Error("bad reference");
  const php = `App\\Domains\\Payments\\Support\\Gateways\\FakeGateway::willReport("${gatewayReference}", App\\Domains\\Payments\\Enums\\PaymentStatus::from("${outcome}"));`;
  execFileSync("php", ["artisan", "tinker", "--execute", php], { cwd: BACKEND, stdio: "pipe" });
}

/** Runs `php artisan tinker --execute` and returns stdout (test records only). */
export function tinker(php: string): string {
  return execFileSync("php", ["artisan", "tinker", "--execute", php], { cwd: BACKEND, encoding: "utf8" });
}
