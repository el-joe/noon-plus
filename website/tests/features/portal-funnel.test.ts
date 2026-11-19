import { afterEach, describe, expect, it, vi } from "vitest";
import { trackPortalEvent } from "@/features/portal/funnel";

afterEach(() => {
  vi.unstubAllGlobals();
  window.dataLayer = [];
});

describe("portal funnel tracking", () => {
  it("sends each event to the API through the BFF and keeps the dataLayer push", () => {
    const fetchMock = vi.fn(async () => new Response(JSON.stringify({ data: { recorded: true }, meta: {} }), { status: 202 }));
    vi.stubGlobal("fetch", fetchMock);

    trackPortalEvent("uae-en", { event: "step", page: "join/vendor", type: "vendor", step: "store" });

    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
    expect(url).toBe("/api/nawy/uae-en/portal/funnel");
    expect(init.method).toBe("POST");
    expect(init.keepalive).toBe(true);
    expect(JSON.parse(String(init.body))).toEqual({ event: "step", page: "join/vendor", type: "vendor", step: "store" });
    expect(window.dataLayer?.at(-1)).toMatchObject({ event: "portal_step", page: "join/vendor", market: "uae-en" });
  });

  it("never throws when the API cannot be reached", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => Promise.reject(new Error("offline"))));
    expect(() => trackPortalEvent("uae-en", { event: "view", page: "join" })).not.toThrow();
    await new Promise((resolve) => setTimeout(resolve, 0));
  });
});
