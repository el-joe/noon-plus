import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

type IsMissing = typeof import("@/lib/missing-routes").isMissing;
let isMissing: IsMissing;

type Reply = number | { status: number; body?: unknown } | Error;
let fetchMock: ReturnType<typeof vi.fn>;

/** Stub fetch: `replies` keyed by a path suffix ("products/x"), default 404. */
function stubFetch(replies: Record<string, Reply> = {}) {
  fetchMock = vi.fn(async (url: string) => {
    const path = new URL(url).pathname;
    const match = Object.entries(replies).find(([suffix]) => path.endsWith(`/${suffix}`));
    const reply = match ? match[1] : 404;
    if (reply instanceof Error) throw reply;
    const { status, body } = typeof reply === "number" ? { status: reply, body: { data: {} } } : reply;
    return new Response(JSON.stringify(body ?? {}), { status });
  });
  vi.stubGlobal("fetch", fetchMock);
}

const cookies = (values: Record<string, string> = {}) => ({ get: (name: string) => (name in values ? { value: values[name] } : undefined) });
const check = (path: string, opts: { search?: string; cookies?: Record<string, string> } = {}) =>
  isMissing("uae-en", path.split("/"), new URLSearchParams(opts.search ?? ""), cookies(opts.cookies));
const calledUrls = () => fetchMock.mock.calls.map((c) => String(c[0]));
const calledInit = (i = 0) => fetchMock.mock.calls[i][1] as RequestInit & { headers: Record<string, string> };

beforeEach(async () => {
  // Fresh module per test: the positive-result cache is module state.
  vi.resetModules();
  ({ isMissing } = await import("@/lib/missing-routes"));
  stubFetch();
});
afterEach(() => vi.unstubAllGlobals());

describe("isMissing: decisions without an API call", () => {
  it("renders pages without a lookup normally", async () => {
    for (const path of ["cart", "checkout", "account", "help", "search", "travel/search", "marketers", "marketers/broker", "join", "join/vendor", "legal/seller-agreement"]) {
      expect(await check(path), path).toBe(false);
    }
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("renders every static page of app/[market] (e.g. auth/add-email) without a lookup", async () => {
    const { readdirSync } = await import("node:fs");
    const { resolve } = await import("node:path");
    const root = resolve(__dirname, "../../app/[market]");
    const pages = (readdirSync(root, { recursive: true }) as string[])
      .map((file) => file.split("\\").join("/"))
      .filter((file) => file.endsWith("page.tsx") && !file.includes("["))
      .map((file) => file.replace(/\/?page\.tsx$/, ""))
      .filter((path) => path !== "" && path !== "missing");
    expect(pages).toContain("auth/add-email");
    for (const path of pages) expect(await check(path), path).toBe(false);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("answers unknown static-root paths and malformed ids as missing", async () => {
    for (const path of ["missing", "help/nope", "products/a/b", "advertise/x", "seller", "legal/terms", "marketers/ninja", "join/astronaut", "r/abc", "flash-sale/bad_slug!"]) {
      expect(await check(path), path).toBe(true);
    }
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("treats malformed or too deep catch-all paths as missing", async () => {
    expect(await check("Electronics")).toBe(true);
    expect(await check("a/b/c/d/e")).toBe(true);
    expect(await check("a%ZZ")).toBe(true);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("does not check account records for guests", async () => {
    expect(await check("account/orders/123")).toBe(false);
    expect(fetchMock).not.toHaveBeenCalled();
  });
});

describe("isMissing: detail lookups", () => {
  it("is missing when the API answers 404", async () => {
    stubFetch({ "products/ghost": 404 });
    expect(await check("products/ghost")).toBe(true);
    expect(calledUrls()[0]).toMatch(/\/uae-en\/products\/ghost$/);
  });

  it("is present when the API answers 200", async () => {
    stubFetch({ "products/tv": 200 });
    expect(await check("products/tv")).toBe(false);
  });

  it("fails open on server errors and network failures", async () => {
    stubFetch({ "products/a": 500, "products/b": new Error("timeout") });
    expect(await check("products/a")).toBe(false);
    expect(await check("products/b")).toBe(false);
  });

  it("caches positive public lookups", async () => {
    stubFetch({ "brands/acme": 200 });
    expect(await check("brands/acme")).toBe(false);
    expect(await check("brands/acme")).toBe(false);
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it("never caches classified ad lookups: an ad archived after a view answers 404 at once (BT-SOQ-04)", async () => {
    stubFetch({ "classifieds/ads/CL-AE-1": 200 });
    expect(await check("classified/find/CL-AE-1")).toBe(false);
    stubFetch({ "classifieds/ads/CL-AE-1": 404 });
    expect(await check("classified/find/CL-AE-1")).toBe(true);
    expect(calledUrls()[0]).toMatch(/\/uae-en\/classifieds\/ads\/CL-AE-1$/);
    expect(calledInit().cache).toBe("no-store");
  });

  it("does not cache negative lookups", async () => {
    expect(await check("brands/gone")).toBe(true);
    expect(await check("brands/gone")).toBe(true);
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it("asks static pages for the storefront audience", async () => {
    stubFetch({ "static-pages/about": 200 });
    await check("pages/about");
    expect(calledUrls()[0]).toMatch(/\/static-pages\/about\?audience=storefront$/);
  });

  it("maps /seller/{slug} to the sellers endpoint", async () => {
    await check("seller/acme");
    expect(calledUrls()[0]).toMatch(/\/uae-en\/sellers\/acme$/);
  });
});

describe("isMissing: catch-all (category / CMS page)", () => {
  it("is missing only when both the page and the category are 404", async () => {
    expect(await check("electronics/phones")).toBe(true);
    expect(calledUrls().map((u) => new URL(u).pathname.split("/").slice(-2).join("/")).sort()).toEqual(["categories/phones", "pages/phones"]);
  });

  it("renders when either exists", async () => {
    stubFetch({ "categories/phones": 200 });
    expect(await check("electronics/phones")).toBe(false);
    stubFetch({ "pages/summer": 200 });
    expect(await check("summer")).toBe(false);
  });
});

describe("isMissing: kind checks (fail closed)", () => {
  it("a classified that is not a property is missing under /property", async () => {
    stubFetch({ "classifieds/ads/car-1": { status: 200, body: { data: { property: null } } } });
    expect(await check("property/car-1")).toBe(true);
  });

  it("a property ad renders", async () => {
    stubFetch({ "classifieds/ads/villa-1": { status: 200, body: { data: { property: { beds: 3 } } } } });
    expect(await check("property/villa-1")).toBe(false);
  });

  it("is missing when the kind cannot be checked", async () => {
    stubFetch({ "classifieds/ads/x": new Error("timeout"), "classifieds/ads/y": 500 });
    expect(await check("property/x")).toBe(true);
    expect(await check("property/y")).toBe(true);
  });

  it("checks blog categories by slug in the tree", async () => {
    stubFetch({ "blog/categories": { status: 200, body: { data: [{ slug: "news", children: [{ slug: "launches" }] }] } } });
    expect(await check("blog/category/launches")).toBe(false);
    expect(await check("blog/category/recipes")).toBe(true);
  });
});

describe("isMissing: authenticated lookups", () => {
  it("sends the bearer token for the customer's own orders", async () => {
    stubFetch({ "orders/123": 404 });
    expect(await check("account/orders/123", { cookies: { nawy_token: "tok" } })).toBe(true);
    expect(calledInit().headers.Authorization).toBe("Bearer tok");
  });

  it("does not look up 'new' pages", async () => {
    expect(await check("account/returns/new", { cookies: { nawy_token: "tok" } })).toBe(false);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("uses the device booking token for a guest travel booking", async () => {
    stubFetch({ "travel/bookings/TB-1": 200 });
    expect(await check("travel/bookings/TB-1", { cookies: { "nawy_booking_TB-1": "bk" } })).toBe(false);
    expect(calledInit().headers["X-Booking-Token"]).toBe("bk");
    expect(calledInit().headers.Authorization).toBeUndefined();
  });

  it("skips travel bookings opened from an emailed link or without any token", async () => {
    expect(await check("travel/bookings/TB-1", { search: "token=abc" })).toBe(false);
    expect(await check("travel/bookings/TB-1")).toBe(false);
    expect(fetchMock).not.toHaveBeenCalled();
  });
});
