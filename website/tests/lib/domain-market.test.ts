import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

type Module = typeof import("@/lib/domain-market");
let lib: Module;
let fetchMock: ReturnType<typeof vi.fn>;

function stubApi(map: Record<string, Record<string, unknown>> = {}, fail: number | null = null) {
  fetchMock = vi.fn(async (url: string) => {
    if (fail) return new Response("{}", { status: fail });
    const host = new URL(url).searchParams.get("host") ?? "";
    const data = map[host];
    return data
      ? new Response(JSON.stringify({ data }), { status: 200 })
      : new Response(JSON.stringify({ error: { code: "domain_not_mapped" } }), { status: 404 });
  });
  vi.stubGlobal("fetch", fetchMock);
}

beforeEach(async () => {
  vi.resetModules();
  lib = await import("@/lib/domain-market");
});
afterEach(() => vi.unstubAllGlobals());

describe("hostMapping", () => {
  it("maps a custom storefront host to its market and caches the answer", async () => {
    stubApi({ "nawy.om": { kind: "storefront", market: "omn-ar", redirect_to: null, canonical_host: "nawy.om" } });
    expect(await lib.hostMapping("NAWY.OM:443")).toEqual({ kind: "storefront", market: "omn-ar", redirectTo: null, canonicalHost: "nawy.om" });
    await lib.hostMapping("nawy.om");
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(new URL(String(fetchMock.mock.calls[0][0])).pathname).toMatch(/\/domains\/resolve$/);
  });

  it("returns redirect hosts and ignores unknown markets", async () => {
    stubApi({ "www.nawy.om": { kind: "storefront", market: "xyz-fr", redirect_to: "nawy.om" } });
    expect(await lib.hostMapping("www.nawy.om")).toMatchObject({ market: null, redirectTo: "nawy.om" });
  });

  it("never asks for local hosts and treats unmapped or failing lookups as not mapped", async () => {
    stubApi();
    expect(await lib.hostMapping("localhost:3000")).toBeNull();
    expect(await lib.hostMapping("127.0.0.1")).toBeNull();
    expect(fetchMock).not.toHaveBeenCalled();
    expect(await lib.hostMapping("unknown.example.com")).toBeNull();

    stubApi({}, 500);
    expect(await lib.hostMapping("other.example.com")).toBeNull();
  });
});
