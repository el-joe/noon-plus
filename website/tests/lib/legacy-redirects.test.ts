import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

type Module = typeof import("@/lib/legacy-redirects");
let lib: Module;
let fetchMock: ReturnType<typeof vi.fn>;

/** Stub the API: `map` of stored old path → new path; anything else 404. `fail` makes every call answer 500. */
function stubApi(map: Record<string, string> = {}, fail: number | Error | null = null) {
  fetchMock = vi.fn(async (url: string) => {
    if (fail instanceof Error) throw fail;
    if (fail) return new Response("{}", { status: fail });
    const path = new URL(url).searchParams.get("path") ?? "";
    const to = map[path];
    return to
      ? new Response(JSON.stringify({ data: { from: path, to, status: 301 } }), { status: 200 })
      : new Response(JSON.stringify({ error: { code: "not_found" } }), { status: 404 });
  });
  vi.stubGlobal("fetch", fetchMock);
}

beforeEach(async () => {
  // Fresh module per test: the answer cache is module state.
  vi.resetModules();
  lib = await import("@/lib/legacy-redirects");
});
afterEach(() => vi.unstubAllGlobals());

describe("legacyRedirect", () => {
  it("returns the new path for an old URL and asks the lookup endpoint with the normalised path", async () => {
    stubApi({ "/uae-en/orders/0f1e": "/uae-en/account/orders/NW-AE-1" });
    expect(await lib.legacyRedirect("/uae-en/orders/0f1e/")).toBe("/uae-en/account/orders/NW-AE-1");
    const url = new URL(String(fetchMock.mock.calls[0][0]));
    expect(url.pathname).toMatch(/\/legacy-redirects$/);
    expect(url.searchParams.get("path")).toBe("/uae-en/orders/0f1e");
  });

  it("answers null for paths the map does not know", async () => {
    stubApi();
    expect(await lib.legacyRedirect("/uae-en/nothing")).toBeNull();
  });

  it("caches found and not-found answers, so a path is asked once", async () => {
    stubApi({ "/uae-en/live-streams": "/uae-en/live" });
    expect(await lib.legacyRedirect("/uae-en/live-streams")).toBe("/uae-en/live");
    expect(await lib.legacyRedirect("/uae-en/live-streams")).toBe("/uae-en/live");
    expect(await lib.legacyRedirect("/uae-en/gone")).toBeNull();
    expect(await lib.legacyRedirect("/uae-en/gone")).toBeNull();
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it("fails open on API errors and timeouts without caching them", async () => {
    stubApi({}, 500);
    expect(await lib.legacyRedirect("/uae-en/wishlist")).toBeNull();
    stubApi({}, new Error("timeout"));
    expect(await lib.legacyRedirect("/uae-en/wishlist")).toBeNull();
    stubApi({ "/uae-en/wishlist": "/uae-en/account/wishlist" });
    expect(await lib.legacyRedirect("/uae-en/wishlist")).toBe("/uae-en/account/wishlist");
  });

  it("never redirects off the site", async () => {
    stubApi({ "/uae-en/a": "https://evil.example/x", "/uae-en/b": "//evil.example/x" });
    expect(await lib.legacyRedirect("/uae-en/a")).toBeNull();
    expect(await lib.legacyRedirect("/uae-en/b")).toBeNull();
  });

  it("does not ask for the root or malformed paths", async () => {
    stubApi();
    expect(await lib.legacyRedirect("/")).toBeNull();
    expect(await lib.legacyRedirect("//evil.example")).toBeNull();
    expect(fetchMock).not.toHaveBeenCalled();
  });
});

describe("isLegacyPortalPath", () => {
  it("matches the old seller portal and site paths without a market prefix", () => {
    for (const path of ["/uae/helpcenter", "/omn/adsupport/articles/x", "/blog/hello", "/join", "/vendors", "/marketers", "/companies/travel-agency", "/faq", "/helpcenter/article/x"]) {
      expect(lib.isLegacyPortalPath(path), path).toBe(true);
    }
  });

  it("leaves other un-prefixed paths to the market redirect", () => {
    for (const path of ["/", "/cart", "/products/x", "/electronics", "/account/orders"]) {
      expect(lib.isLegacyPortalPath(path), path).toBe(false);
    }
  });
});
