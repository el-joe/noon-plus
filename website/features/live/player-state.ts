/**
 * Why the watch page shows the poster instead of video (no WebRTC room, no
 * playable recording, no HLS this browser can play):
 * - `failed`: the LiveKit viewer could not join (network / token), retryable;
 * - `unsupported`: the API offers an HLS stream but this browser has no native HLS;
 * - `unavailable`: live, but the API offers no browser playback (media server
 *   without a browser endpoint / HLS egress, or the host's room is not up yet);
 * - `ended` / `notStarted`: the show is over, or not live yet.
 */
export type PlayerFallback = "failed" | "unsupported" | "unavailable" | "ended" | "notStarted";

export function playerFallback(input: { isLive: boolean; status: string; webrtcFailed: boolean; hlsUrl: string | null; nativeHls: boolean }): PlayerFallback {
  if (!input.isLive) return input.status === "ended" ? "ended" : "notStarted";
  if (input.webrtcFailed) return "failed";
  if (input.hlsUrl && !input.nativeHls) return "unsupported";
  return "unavailable";
}
