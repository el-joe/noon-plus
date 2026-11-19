import { describe, expect, it } from "vitest";
import { playback } from "@/lib/api/live";
import { invoiceHref } from "@/features/account/order-actions";
import { broadcastAuthEndpoint, getEcho, realtimeConfig } from "@/lib/realtime";

describe("realtime (D16, laravel-echo + pusher-js)", () => {
  it("is off without a Reverb key, so pages keep polling", async () => {
    expect(realtimeConfig({})).toBeNull();
    expect(realtimeConfig({ NEXT_PUBLIC_REVERB_APP_KEY: "  " })).toBeNull();
    await expect(getEcho("uae-en")).resolves.toBeNull();
  });

  it("reads the Reverb host, port and scheme with sensible defaults", () => {
    expect(realtimeConfig({ NEXT_PUBLIC_REVERB_APP_KEY: "k", NEXT_PUBLIC_REVERB_HOST: "ws.nawy.com", NEXT_PUBLIC_REVERB_PORT: "443", NEXT_PUBLIC_REVERB_SCHEME: "https" }))
      .toEqual({ key: "k", host: "ws.nawy.com", port: 443, scheme: "https" });
    expect(realtimeConfig({ NEXT_PUBLIC_REVERB_APP_KEY: "k", NEXT_PUBLIC_REVERB_SCHEME: "http" })).toMatchObject({ port: 80, scheme: "http", host: "localhost" });
    expect(realtimeConfig({ NEXT_PUBLIC_REVERB_APP_KEY: "k", NEXT_PUBLIC_REVERB_PORT: "nope" })).toMatchObject({ port: 443, scheme: "https" });
  });

  it("authorises private channels through the same-origin proxy", () => {
    expect(broadcastAuthEndpoint("omn-ar")).toBe("/api/nawy/omn-ar/broadcasting/auth");
  });
});

describe("invoice PDF and LiveKit playback (D16)", () => {
  it("links the PDF invoice through the invoice route", () => {
    expect(invoiceHref("uae-en", "NW-AE-1")).toBe("/api/invoice/uae-en/NW-AE-1");
    expect(invoiceHref("uae-en", "NW-AE-1", "pdf")).toBe("/api/invoice/uae-en/NW-AE-1?format=pdf");
  });

  it("treats a live room as not joinable unless the API says webrtc", () => {
    const old = playback.parse({ type: "live", url: "wss://live.fake.test", room: "r", token: "fake.x", hls_url: null });
    expect(old.type === "live" && old.webrtc).toBe(false);
    const real = playback.parse({ type: "live", webrtc: true, url: "wss://live.example", room: "r", token: "a.b.c" });
    expect(real.type === "live" && real.webrtc).toBe(true);
  });
});
