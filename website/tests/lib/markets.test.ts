import { describe, expect, it } from "vitest";
import { COUNTRIES, DEFAULT_MARKET, LOCALES, MARKET_SLUGS, marketSlug, parseMarket, switchMarketPath } from "@/lib/markets";

describe("MARKET_SLUGS", () => {
  it("has one slug per country and locale (8 x 2)", () => {
    expect(MARKET_SLUGS).toHaveLength(COUNTRIES.length * LOCALES.length);
    expect(MARKET_SLUGS).toHaveLength(16);
    expect(new Set(MARKET_SLUGS).size).toBe(16);
  });

  it("includes every country in both languages", () => {
    for (const code of ["uae", "omn", "ksa", "egy", "kwt", "qat", "bhr", "jor"]) {
      expect(MARKET_SLUGS).toContain(`${code}-en`);
      expect(MARKET_SLUGS).toContain(`${code}-ar`);
    }
  });

  it("defaults to a valid market", () => {
    expect(MARKET_SLUGS).toContain(DEFAULT_MARKET);
  });
});

describe("COUNTRIES", () => {
  it("uses 3 decimals exactly for OMR, KWD, BHD and JOD", () => {
    const three = COUNTRIES.filter((c) => c.decimals === 3).map((c) => c.currency).sort();
    expect(three).toEqual(["BHD", "JOD", "KWD", "OMR"]);
    expect(COUNTRIES.filter((c) => c.decimals !== 3).every((c) => c.decimals === 2)).toBe(true);
  });
});

describe("parseMarket", () => {
  it("parses an English market as LTR", () => {
    const m = parseMarket("uae-en");
    expect(m?.slug).toBe("uae-en");
    expect(m?.locale).toBe("en");
    expect(m?.dir).toBe("ltr");
    expect(m?.country.currency).toBe("AED");
  });

  it("parses an Arabic market as RTL with the country's currency", () => {
    const m = parseMarket("omn-ar");
    expect(m?.dir).toBe("rtl");
    expect(m?.locale).toBe("ar");
    expect(m?.country.currency).toBe("OMR");
    expect(m?.country.decimals).toBe(3);
  });

  it("sets dir from the locale for every slug", () => {
    for (const slug of MARKET_SLUGS) {
      const m = parseMarket(slug)!;
      expect(m.dir).toBe(slug.endsWith("-ar") ? "rtl" : "ltr");
    }
  });

  it.each([
    ["unknown country", "usa-en"],
    ["unknown locale", "uae-fr"],
    ["upper case", "UAE-EN"],
    ["missing locale", "uae"],
    ["extra suffix", "uae-en-x"],
    ["empty", ""],
    ["trailing slash", "uae-en/"],
  ])("rejects %s (%s)", (_, slug) => {
    expect(parseMarket(slug)).toBeNull();
  });

  it("accepts null and undefined", () => {
    expect(parseMarket(null)).toBeNull();
    expect(parseMarket(undefined)).toBeNull();
  });

  it("builds slugs with marketSlug", () => {
    expect(marketSlug("kwt", "ar")).toBe("kwt-ar");
  });
});

describe("switchMarketPath", () => {
  it("swaps the market segment and keeps the rest", () => {
    expect(switchMarketPath("/uae-en/cart", "omn-ar")).toBe("/omn-ar/cart");
    expect(switchMarketPath("/uae-en/products/abc/reviews", "ksa-en")).toBe("/ksa-en/products/abc/reviews");
  });

  it("handles the market root with and without a trailing slash", () => {
    expect(switchMarketPath("/uae-en", "jor-ar")).toBe("/jor-ar");
    expect(switchMarketPath("/uae-en/", "jor-ar")).toBe("/jor-ar/");
  });

  it("falls back to the market root when the path has no market", () => {
    expect(switchMarketPath("/", "uae-ar")).toBe("/uae-ar");
    expect(switchMarketPath("/about/us", "uae-ar")).toBe("/uae-ar");
    expect(switchMarketPath("/xyz-en/cart", "uae-ar")).toBe("/uae-ar");
  });
});
