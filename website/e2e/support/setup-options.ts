/**
 * QA-C07 follow-up: switches for the Playwright global setup (seed + customer
 * sign-in). The e2e customer's password sign-in allows 30 tries an hour (and
 * 5 a minute), so repeated or parallel QA runs must not sign in every time.
 *
 * - `E2E_NO_GLOBAL_SETUP=1` skips the global setup entirely: no seeding, no
 *   sign-in. The run uses the files a previous run left behind
 *   (e2e/.generated/fixtures.json, customer-token.txt, e2e/.auth/*.json).
 * - By default the stored customer session is reused while its token still
 *   works; the password sign-in runs only when there is none (or it expired).
 *   `E2E_FRESH_LOGIN=1` forces a new sign-in.
 * - `E2E_SKIP_SEED=1` skips seeding only; `E2E_SKIP_SEED=0` (or false / no /
 *   off) seeds, like leaving it unset.
 */
type Env = Record<string, string | undefined>;

const truthy = (value: string | undefined) => !!value && !["0", "false", "no", "off", ""].includes(value.trim().toLowerCase());

export const GLOBAL_SETUP = "./e2e/support/global-setup.ts";

/** The `globalSetup` entry for playwright.config.ts (undefined when E2E_NO_GLOBAL_SETUP is set). */
export function globalSetupFor(env: Env): string | undefined {
  return truthy(env.E2E_NO_GLOBAL_SETUP) ? undefined : GLOBAL_SETUP;
}

/** Whether global setup may reuse the stored customer session before signing in with the password. */
export function reuseStoredSession(env: Env): boolean {
  return !truthy(env.E2E_FRESH_LOGIN);
}

/** Whether global setup seeds the e2e records (E2eSeeder): yes unless E2E_SKIP_SEED is truthy ("0" still seeds). */
export function seedRecords(env: Env): boolean {
  return !truthy(env.E2E_SKIP_SEED);
}
