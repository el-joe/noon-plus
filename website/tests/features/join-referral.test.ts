import { beforeEach, describe, expect, it } from "vitest";
import { captureReferral, clearReferral, isReferralCode, storedReferral } from "@/features/portal/referral";

describe("join referral codes (FR-FIN-6)", () => {
  beforeEach(() => window.localStorage.clear());

  it("accepts only the account-manager code shape", () => {
    expect(isReferralCode("AM12-abcd1234")).toBe(true);
    expect(isReferralCode("AM12-abc")).toBe(false);
    expect(isReferralCode("X12-abcd1234")).toBe(false);
    expect(isReferralCode(null)).toBe(false);
  });

  it("captures ?ref= and keeps it until cleared", () => {
    expect(captureReferral("?ref=AM7-0a1b2c3d&role=affiliate", 1000)).toBe("AM7-0a1b2c3d");
    expect(storedReferral(2000)).toBe("AM7-0a1b2c3d");
    clearReferral();
    expect(storedReferral(2000)).toBeNull();
  });

  it("ignores junk and forgets codes after 30 days", () => {
    expect(captureReferral("?ref=<script>")).toBeNull();
    expect(storedReferral()).toBeNull();
    captureReferral("?ref=AM7-0a1b2c3d", 0);
    expect(storedReferral(31 * 24 * 60 * 60 * 1000)).toBeNull();
  });
});
