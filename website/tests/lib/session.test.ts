import { afterEach, describe, expect, it, vi } from "vitest";
import { isCrossSite, secureCookies } from "@/lib/session";

const req = (headers: Record<string, string>, origin = "http://localhost:3000") => ({ headers: new Headers(headers), nextUrl: new URL(`${origin}/api/nawy/uae-en/cart`) });

describe("isCrossSite (BFF write check behind a reverse proxy)", () => {
  it("accepts requests without an Origin and same-origin direct requests", () => {
    expect(isCrossSite(req({}))).toBe(false);
    expect(isCrossSite(req({ origin: "http://localhost:3000", host: "localhost:3000" }))).toBe(false);
  });

  it("accepts the public host Apache forwards (ProxyPreserveHost / X-Forwarded-Host), http or https", () => {
    expect(isCrossSite(req({ origin: "http://noon-plus.loc", host: "noon-plus.loc" }))).toBe(false);
    expect(isCrossSite(req({ origin: "https://business.noon-plus.loc", host: "business.noon-plus.loc:443" }))).toBe(false);
    expect(isCrossSite(req({ origin: "https://www.nawy.com", host: "127.0.0.1:3000", "x-forwarded-host": "www.nawy.com" }))).toBe(false);
  });

  it("refuses another site or a malformed Origin", () => {
    expect(isCrossSite(req({ origin: "http://evil.example", host: "noon-plus.loc" }))).toBe(true);
    expect(isCrossSite(req({ origin: "http://admin.noon-plus.loc", host: "noon-plus.loc" }))).toBe(true);
    expect(isCrossSite(req({ origin: "null", host: "noon-plus.loc" }))).toBe(true);
  });
});

describe("secureCookies", () => {
  afterEach(() => vi.unstubAllEnvs());

  it("is off outside production builds", () => {
    vi.stubEnv("NODE_ENV", "development");
    expect(secureCookies({ headers: new Headers({ host: "www.nawy.com" }) })).toBe(false);
  });

  it("stays on in production except plain-http local dev hosts", () => {
    vi.stubEnv("NODE_ENV", "production");
    expect(secureCookies({ headers: new Headers({ host: "www.nawy.com", "x-forwarded-proto": "https" }) })).toBe(true);
    expect(secureCookies({ headers: new Headers({ host: "www.nawy.com", "x-forwarded-proto": "http" }) })).toBe(true);
    expect(secureCookies({ headers: new Headers({ host: "www.nawy.com" }) })).toBe(true);
    expect(secureCookies({ headers: new Headers({ host: "noon-plus.loc", "x-forwarded-proto": "http" }) })).toBe(false);
    expect(secureCookies({ headers: new Headers({ host: "noon-plus.loc", "x-forwarded-proto": "https" }) })).toBe(true);
    expect(secureCookies({ headers: new Headers({ host: "localhost:3000", "x-forwarded-proto": "http" }) })).toBe(false);
  });
});
