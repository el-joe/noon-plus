import { describe, expect, it } from "vitest";
import { playerFallback } from "@/features/live/player-state";

const base = { isLive: true, status: "live", webrtcFailed: false, hlsUrl: null, nativeHls: false };

describe("live watch page: why the poster shows instead of video", () => {
  it("is not started / ended when the show is not live", () => {
    expect(playerFallback({ ...base, isLive: false, status: "scheduled" })).toBe("notStarted");
    expect(playerFallback({ ...base, isLive: false, status: "idle" })).toBe("notStarted");
    expect(playerFallback({ ...base, isLive: false, status: "ended" })).toBe("ended");
  });

  it("is a retryable failure when the LiveKit viewer could not join", () => {
    expect(playerFallback({ ...base, webrtcFailed: true, hlsUrl: "https://hls.example/room.m3u8" })).toBe("failed");
  });

  it("is unsupported when only HLS is offered and the browser has no native HLS", () => {
    expect(playerFallback({ ...base, hlsUrl: "https://hls.example/room.m3u8" })).toBe("unsupported");
  });

  it("is unavailable when the API offers no browser playback for the live show", () => {
    expect(playerFallback(base)).toBe("unavailable");
  });
});
