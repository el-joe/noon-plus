import { describe, expect, it } from "vitest";
import { playerFallback, usesWebRtc, viewerMessageKey } from "@/features/live/player-state";

describe("live watch page: why the poster shows instead of video", () => {
  it("is not started / ended when the show is not live", () => {
    expect(playerFallback({ isLive: false, status: "scheduled" })).toBe("notStarted");
    expect(playerFallback({ isLive: false, status: "idle" })).toBe("notStarted");
    expect(playerFallback({ isLive: false, status: "ended" })).toBe("ended");
  });

  it("is unavailable when the API offers no browser playback for the live show", () => {
    expect(playerFallback({ isLive: true, status: "live" })).toBe("unavailable");
  });
});

describe("D18: native WebRTC viewer on the watch page", () => {
  it("mounts the WebRTC viewer only for a live broadcast the API marks webrtc", () => {
    expect(usesWebRtc({ isLive: true, playback: { type: "live", webrtc: true } })).toBe(true);
    expect(usesWebRtc({ isLive: true, playback: { type: "live", webrtc: false } })).toBe(false);
    expect(usesWebRtc({ isLive: false, playback: { type: "live", webrtc: true } })).toBe(false);
    expect(usesWebRtc({ isLive: true, playback: { type: "recorded" } })).toBe(false);
    expect(usesWebRtc({ isLive: true, playback: null })).toBe(false);
  });

  it("shows a message for every state but playing", () => {
    expect(viewerMessageKey("connecting")).toBe("viewer.connecting");
    expect(viewerMessageKey("waiting")).toBe("viewer.waitingHost");
    expect(viewerMessageKey("full")).toBe("viewer.full");
    expect(viewerMessageKey("notLive")).toBe("viewer.notLive");
    expect(viewerMessageKey("failed")).toBe("player.failed");
    expect(viewerMessageKey("ended")).toBe("ended");
    expect(viewerMessageKey("playing")).toBeNull();
  });
});
