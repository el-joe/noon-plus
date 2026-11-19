import { execFileSync } from "node:child_process";
import { mkdirSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import path from "node:path";
import type { Fixtures } from "./routes";
import { CUSTOMER, CUSTOMER_STATE } from "./accounts";
import { reuseStoredSession } from "./setup-options";

export const GENERATED = path.resolve(__dirname, "../.generated");
const BACKEND = path.resolve(__dirname, "../../../backend");
const WEBSITE = path.resolve(__dirname, "../..");
export const API_ROOT = (process.env.E2E_API_URL ?? "http://localhost:8000") + "/api/v1";
const STOREFRONT = new URL(process.env.E2E_STOREFRONT_URL ?? "http://localhost:3000");

/**
 * 1. Seeds the e2e test records (E2eSeeder, refuses production) unless E2E_SKIP_SEED.
 * 2. Copies the seeded identifiers to e2e/.generated/fixtures.json.
 * 3. Signs the e2e customer in through the API and stores a browser session
 *    (the storefront's own httpOnly token cookie) for the signed-in crawl.
 *    A stored session whose token still works is reused without signing in
 *    (QA-C07: the password sign-in is rate limited); E2E_FRESH_LOGIN forces
 *    a new sign-in. E2E_NO_GLOBAL_SETUP skips this whole file (setup-options.ts).
 */
export default async function globalSetup() {
  if (!process.env.E2E_SKIP_SEED) {
    execFileSync("php", ["artisan", "db:seed", "--class=E2eSeeder", "--no-interaction"], { cwd: BACKEND, stdio: "inherit" });
  }
  const fixtures = JSON.parse(readFileSync(path.join(BACKEND, "storage/app/private/e2e-fixtures.json"), "utf8")) as Fixtures;

  rmSync(path.join(GENERATED, "links"), { recursive: true, force: true });
  mkdirSync(path.join(GENERATED, "links"), { recursive: true });
  mkdirSync(path.join(WEBSITE, "e2e/.auth"), { recursive: true });
  writeFileSync(path.join(GENERATED, "fixtures.json"), JSON.stringify(fixtures, null, 2));

  const reused = reuseStoredSession(process.env) ? await storedCustomerToken() : null;
  if (reused) {
    writeFileSync(path.join(GENERATED, "customer-token.txt"), reused);
    return;
  }

  const login = await fetch(`${API_ROOT}/uae-en/auth/password/login`, {
    method: "POST",
    headers: { Accept: "application/json", "Content-Type": "application/json" },
    body: JSON.stringify({ email: CUSTOMER.email, password: CUSTOMER.password, device_name: "e2e-crawl" }),
  });
  if (!login.ok) {
    // The password sign-in allows 30 tries an hour per account (and 5 a minute): with several
    // runs in a row (or parallel QA runs) keep the stored session while its token still works.
    const stored = login.status === 429 ? await storedCustomerToken() : null;
    if (stored) {
      writeFileSync(path.join(GENERATED, "customer-token.txt"), stored);
      return;
    }
    throw new Error(`e2e customer API sign-in failed: ${login.status} ${await login.text()}`);
  }
  const { token } = ((await login.json()) as { data: { token: string } }).data;
  const cookie = (name: string, value: string, httpOnly: boolean) => ({
    name, value, domain: STOREFRONT.hostname, path: "/", expires: Math.floor(Date.now() / 1000) + 86_400, httpOnly, secure: false, sameSite: "Lax" as const,
  });
  writeFileSync(path.join(WEBSITE, CUSTOMER_STATE), JSON.stringify({ cookies: [cookie("nawy_token", token, true), cookie("nawy_signed_in", "1", false), cookie("nawy_consent", "v1:a0p0d0", false)], origins: [] }, null, 2));
  writeFileSync(path.join(GENERATED, "customer-token.txt"), token);
}

/** The token in the stored customer session when the API still accepts it, else null. */
async function storedCustomerToken(): Promise<string | null> {
  try {
    const state = JSON.parse(readFileSync(path.join(WEBSITE, CUSTOMER_STATE), "utf8")) as { cookies: { name: string; value: string }[] };
    const token = state.cookies.find((c) => c.name === "nawy_token")?.value;
    if (!token) return null;
    const me = await fetch(`${API_ROOT}/uae-en/me`, { headers: { Accept: "application/json", Authorization: `Bearer ${token}` } });
    return me.ok ? token : null;
  } catch {
    return null;
  }
}
