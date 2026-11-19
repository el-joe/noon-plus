import { describe, expect, it } from "vitest";
import { playback } from "@/lib/api/live";
import { invoiceHref } from "@/features/account/order-actions";
import { broadcastAuthEndpoint, getEcho, peerSocketConfig, realtimeConfig } from "@/lib/realtime";

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

describe("invoice PDF and WebRTC playback (D16, D18)", () => {
  it("links the PDF invoice through the invoice route", () => {
    expect(invoiceHref("uae-en", "NW-AE-1")).toBe("/api/invoice/uae-en/NW-AE-1");
    expect(invoiceHref("uae-en", "NW-AE-1", "pdf")).toBe("/api/invoice/uae-en/NW-AE-1?format=pdf");
  });

  it("reads the live playback of a native WebRTC broadcast", () => {
    const idle = playback.parse({ type: "live", webrtc: false });
    expect(idle.type === "live" && idle.webrtc).toBe(false);
    const live = playback.parse({ type: "live", webrtc: true, host_online: true, max_viewers: 20, viewers: 3, full: false, join_url: "live-streams/eid/join" });
    expect(live).toMatchObject({ webrtc: true, host_online: true, max_viewers: 20, viewers: 3, full: false });
  });

  it("falls back to the join answer's public socket when the build has no Reverb settings", () => {
    expect(peerSocketConfig(null)).toBeNull();
    expect(peerSocketConfig({ key: null })).toBeNull();
    expect(peerSocketConfig({ key: "k", host: "ws.nawy.test", port: 8080, scheme: "http" })).toEqual({ key: "k", host: "ws.nawy.test", port: 8080, scheme: "http" });
    expect(peerSocketConfig({ key: "k", host: "ws.nawy.test", port: null, scheme: "https" })).toMatchObject({ port: 443 });
  });
});
