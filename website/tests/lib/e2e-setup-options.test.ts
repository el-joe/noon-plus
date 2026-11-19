import { describe, expect, it } from "vitest";
import { GLOBAL_SETUP, globalSetupFor, reuseStoredSession, seedRecords } from "@/e2e/support/setup-options";

/**
 * QA-C07 follow-up: `npx playwright test --project=areas --no-deps` must be
 * able to run without the global setup signing the shared e2e customer in
 * (the password sign-in answers 429 after 30 tries an hour).
 */
describe("e2e global setup switches", () => {
  it("runs the global setup by default", () => {
    expect(globalSetupFor({})).toBe(GLOBAL_SETUP);
    expect(globalSetupFor({ E2E_NO_GLOBAL_SETUP: "0" })).toBe(GLOBAL_SETUP);
    expect(globalSetupFor({ E2E_NO_GLOBAL_SETUP: "false" })).toBe(GLOBAL_SETUP);
  });

  it("skips it with E2E_NO_GLOBAL_SETUP", () => {
    expect(globalSetupFor({ E2E_NO_GLOBAL_SETUP: "1" })).toBeUndefined();
    expect(globalSetupFor({ E2E_NO_GLOBAL_SETUP: "true" })).toBeUndefined();
  });

  it("reuses the stored customer session unless E2E_FRESH_LOGIN is set", () => {
    expect(reuseStoredSession({})).toBe(true);
    expect(reuseStoredSession({ E2E_FRESH_LOGIN: "1" })).toBe(false);
    expect(reuseStoredSession({ E2E_FRESH_LOGIN: "off" })).toBe(true);
  });

  it("seeds unless E2E_SKIP_SEED is truthy: E2E_SKIP_SEED=0 still seeds", () => {
    expect(seedRecords({})).toBe(true);
    expect(seedRecords({ E2E_SKIP_SEED: "0" })).toBe(true);
    expect(seedRecords({ E2E_SKIP_SEED: "false" })).toBe(true);
    expect(seedRecords({ E2E_SKIP_SEED: "1" })).toBe(false);
    expect(seedRecords({ E2E_SKIP_SEED: "yes" })).toBe(false);
  });
});
