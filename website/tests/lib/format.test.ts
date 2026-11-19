import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { discountPercent, formatAmount, formatDateTime, formatDay, formatMoney, humanize, isZero, withoutBrandSuffix } from "@/lib/format";

const m = (amount: string, currency = "AED") => ({ amount, currency });

describe("formatAmount", () => {
  it.each([
    ["0.00", "0.00"],
    ["999.99", "999.99"],
    ["1000.00", "1,000.00"],
    ["1234567.89", "1,234,567.89"],
    ["100", "100"],
    ["1000000", "1,000,000"],
  ])("groups %s as %s", (input, out) => {
    expect(formatAmount(input)).toBe(out);
  });

  it("keeps 3-decimal minor units untouched (OMR/KWD/BHD/JOD)", () => {
    expect(formatAmount("1234.500")).toBe("1,234.500");
    expect(formatAmount("0.125")).toBe("0.125");
  });

  it("formats negatives with the sign before the grouped digits", () => {
    expect(formatAmount("-1234.50")).toBe("-1,234.50");
    expect(formatAmount("-999.000")).toBe("-999.000");
    expect(formatAmount("-1000")).toBe("-1,000");
  });

  it("never groups the fraction", () => {
    expect(formatAmount("1.2345")).toBe("1.2345");
  });
});

describe("formatMoney", () => {
  it("prefixes the currency code", () => {
    expect(formatMoney(m("2499.00"))).toBe("AED 2,499.00");
    expect(formatMoney(m("12345.750", "KWD"))).toBe("KWD 12,345.750");
    expect(formatMoney(m("-5.250", "BHD"))).toBe("BHD -5.250");
  });

  it("renders nothing for null / undefined", () => {
    expect(formatMoney(null)).toBe("");
    expect(formatMoney(undefined)).toBe("");
  });
});

describe("isZero", () => {
  it("treats missing and zero amounts as zero", () => {
    expect(isZero(null)).toBe(true);
    expect(isZero(undefined)).toBe(true);
    expect(isZero(m("0.00"))).toBe(true);
    expect(isZero(m("0.000", "OMR"))).toBe(true);
    expect(isZero(m("0.001", "OMR"))).toBe(false);
  });
});

describe("discountPercent", () => {
  it("rounds the saving against the was-price", () => {
    expect(discountPercent(m("75.00"), m("100.00"))).toBe(25);
    expect(discountPercent(m("66.67"), m("100.00"))).toBe(33);
    expect(discountPercent(m("1.250", "OMR"), m("2.500", "OMR"))).toBe(50);
  });

  it("is null when there is no saving or no prices", () => {
    expect(discountPercent(m("100.00"), m("100.00"))).toBeNull();
    expect(discountPercent(m("120.00"), m("100.00"))).toBeNull();
    expect(discountPercent(null, m("100.00"))).toBeNull();
    expect(discountPercent(m("10.00"), null)).toBeNull();
    expect(discountPercent(m("-5.00"), m("0.00"))).toBeNull();
  });

  it("is null for non-numeric amounts", () => {
    expect(discountPercent(m("abc"), m("100.00"))).toBeNull();
  });
});

describe("formatDay", () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date("2026-11-19T08:00:00Z"));
  });
  afterEach(() => vi.useRealTimers());

  it("shows the year for a date in another year (gift card expiry a year out)", () => {
    expect(formatDay("2027-11-19T08:00:00Z", "en")).toBe("19 Nov 2027");
    expect(formatDay("2025-12-31", "en")).toBe("31 Dec 2025");
    expect(formatDay("2026-12-31", "en")).toBe("31 Dec");
  });

  it("formats a date-only string in English (UTC, no off-by-one)", () => {
    expect(formatDay("2026-11-15", "en")).toBe("15 Nov");
    expect(formatDay("2026-01-01", "en")).toBe("1 Jan");
  });

  it("adds the weekday when asked", () => {
    // ICU versions differ on the comma after the weekday.
    expect(formatDay("2026-11-14", "en", true)).toMatch(/^Sat,? 14 Nov$/);
  });

  it("uses Arabic month names with Latin digits", () => {
    const out = formatDay("2026-11-15", "ar");
    expect(out).toContain("15");
    expect(out).toContain("نوفمبر");
    expect(/[٠-٩]/.test(out)).toBe(false);
  });

  it("returns empty for empty input and echoes unparseable input", () => {
    expect(formatDay(null, "en")).toBe("");
    expect(formatDay("", "en")).toBe("");
    expect(formatDay("not-a-date", "en")).toBe("not-a-date");
  });

  it("accepts full timestamps", () => {
    expect(formatDay("2026-11-15T10:00:00Z", "en")).toBe("15 Nov");
  });
});

describe("formatDateTime", () => {
  it("formats in the given time zone", () => {
    const out = formatDateTime("2026-11-15T22:30:00Z", "en", "Asia/Dubai");
    expect(out).toContain("16 Nov 2026");
    expect(out).toContain("02:30");
  });

  it("handles empty and invalid values", () => {
    expect(formatDateTime(undefined, "en")).toBe("");
    expect(formatDateTime("nope", "ar")).toBe("nope");
  });
});

describe("humanize", () => {
  it.each([
    ["out_for_delivery", "Out for delivery"],
    ["ready-to-ship", "Ready to ship"],
    ["pending", "Pending"],
    ["__weird__", "Weird"],
    ["", ""],
  ])("%s -> %s", (input, out) => {
    expect(humanize(input)).toBe(out);
  });
});

describe("withoutBrandSuffix", () => {
  it("drops a trailing brand the title template adds again", () => {
    expect(withoutBrandSuffix("Privacy Policy | Nawy")).toBe("Privacy Policy");
    expect(withoutBrandSuffix("سياسة الخصوصية | ناوي")).toBe("سياسة الخصوصية");
    expect(withoutBrandSuffix("Deals — Nawy")).toBe("Deals");
  });
  it("keeps other titles and empty input", () => {
    expect(withoutBrandSuffix("Nawy One")).toBe("Nawy One");
    expect(withoutBrandSuffix("Electronics")).toBe("Electronics");
    expect(withoutBrandSuffix(null)).toBeUndefined();
  });
});
