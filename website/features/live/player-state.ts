import type { ViewerState } from "./webrtc-session";

/**
 * Why the watch page shows the poster instead of a player:
 * - `unavailable`: live, but the API offers no browser playback (not a WebRTC broadcast);
 * - `ended` / `notStarted`: the show is over, or not live yet.
 * A live broadcast (D18: `playback.webrtc`) plays in the WebRTC viewer, which
 * has its own states ({@link viewerMessageKey}).
 */
export type PlayerFallback = "unavailable" | "ended" | "notStarted";

export function playerFallback(input: { isLive: boolean; status: string }): PlayerFallback {
  if (!input.isLive) return input.status === "ended" ? "ended" : "notStarted";
  return "unavailable";
}

/** True when the watch page should mount the WebRTC viewer. */
export function usesWebRtc(input: { isLive: boolean; playback: { type: string; webrtc?: boolean } | null | undefined }): boolean {
  return input.isLive && input.playback?.type === "live" && input.playback.webrtc === true;
}

/** D18: the message (key under `live`) the WebRTC viewer shows over the video, or null while playing. */
export type ViewerMessageKey = "viewer.connecting" | "viewer.waitingHost" | "viewer.full" | "viewer.notLive" | "player.failed" | "ended";

export function viewerMessageKey(state: ViewerState): ViewerMessageKey | null {
  switch (state) {
    case "connecting":
      return "viewer.connecting";
    case "waiting":
      return "viewer.waitingHost";
    case "full":
      return "viewer.full";
    case "notLive":
      return "viewer.notLive";
    case "failed":
      return "player.failed";
    case "ended":
      return "ended";
    default:
      return null;
  }
}
