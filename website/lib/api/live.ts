import { z } from "zod";
import { get, request } from "./client";
import { productCard } from "./schemas";

/* Live streams / Nawy TV (LiveStreamApiController). */

export const streamSummary = z.object({
  slug: z.string(),
  type: z.string(), // live | recorded
  title: z.string().nullable(),
  status: z.string(), // scheduled | idle | live | ended
  media_mode: z.string().nullable().optional(),
  thumbnail_url: z.string().nullable().optional(),
  scheduled_at: z.string().nullable().optional(),
  scheduled_end_at: z.string().nullable().optional(),
  started_at: z.string().nullable().optional(),
  likes: z.number().default(0),
  /** QC-040: people watching in the last 60 s (0 when not live). */
  viewers: z.number().default(0),
});
export type StreamSummary = z.infer<typeof streamSummary>;

// LiveStreams::playback — recorded file, or a live broadcast over native WebRTC (D18).
export const playback = z.union([
  z.object({
    type: z.literal("recorded"),
    media_url: z.string(),
    mime: z.string().nullable().optional(),
    mode: z.string().nullable().optional(),
    duration_seconds: z.number().nullable().optional(),
    offset_seconds: z.number().default(0),
  }),
  z.object({
    type: z.literal("live"),
    /** D18: true while the broadcast is live — the browser joins the host over WebRTC (`join_url`). */
    webrtc: z.boolean().default(false),
    /** The host studio is publishing right now. */
    host_online: z.boolean().default(false),
    max_viewers: z.number().default(0),
    /** WebRTC viewers connected now; `full` when the cap is reached. */
    viewers: z.number().default(0),
    full: z.boolean().default(false),
    join_url: z.string().nullable().optional(),
  }),
]);
export type Playback = z.infer<typeof playback>;

export const streamDetail = streamSummary.extend({
  description: z.string().nullable().optional(),
  playback: playback.nullable().optional(),
  /** Product cards (round 2: image + rating). */
  products: z.array(productCard.extend({ listing_id: z.number() })).default([]),
});
export type StreamDetail = z.infer<typeof streamDetail>;

export const getStreams = (market: string) => get(market, "live-streams", z.array(streamSummary));
export const getStream = (market: string, slug: string) => get(market, `live-streams/${encodeURIComponent(slug)}`, streamDetail);
export const likeStream = (market: string, slug: string) =>
  request(market, `live-streams/${encodeURIComponent(slug)}/likes`, z.object({ likes: z.number() }), { method: "POST" });

/** QC-063: the stream for the home floating mini-player (a country setting), or null. */
export const getFloatingStream = (market: string) => get(market, "live-streams/floating", streamSummary.nullable());

// QC-040: comments (visible only, oldest first) and the viewer heartbeat.
export const streamComment = z.object({
  id: z.number(),
  author: z.string().nullable(),
  body: z.string(),
  created_at: z.string().nullable(),
  /** Only on the answer to a post: `visible` or `held` (waiting for moderation). */
  status: z.string().optional(),
});
export type StreamComment = z.infer<typeof streamComment>;

export const getStreamComments = (market: string, slug: string, afterId?: number) =>
  get(market, `live-streams/${encodeURIComponent(slug)}/comments`, z.array(streamComment), { after_id: afterId });
export const postStreamComment = (market: string, slug: string, body: string) =>
  request(market, `live-streams/${encodeURIComponent(slug)}/comments`, streamComment, { method: "POST", body: { body } });
export const sendPresence = (market: string, slug: string, viewerId?: string) =>
  request(market, `live-streams/${encodeURIComponent(slug)}/presence`, z.object({ viewers: z.number() }), { method: "POST", body: { viewer_id: viewerId } });

/* D18: native WebRTC viewer — slot, signalling to the host studio, heartbeat with token renewal. */
export const iceServer = z.object({
  urls: z.union([z.string(), z.array(z.string())]),
  username: z.string().optional(),
  credential: z.string().optional(),
});

export const liveJoin = z.object({
  peer_id: z.string(),
  peer_token: z.string(),
  expires_at: z.string().nullable().optional(),
  /** Private channel `private-live.{slug}.peer.{peer}` (Pusher event `signal`). */
  channel: z.string(),
  ice_servers: z.array(iceServer).default([]),
  heartbeat_seconds: z.number().default(30),
  max_viewers: z.number().default(0),
  viewers: z.number().default(0),
  host_online: z.boolean().default(false),
  /** The public Reverb endpoint (used when the build has no NEXT_PUBLIC_REVERB_* settings). */
  socket: z
    .object({ key: z.string().nullable(), host: z.string().nullable(), port: z.number().nullable(), scheme: z.enum(["http", "https"]) })
    .partial()
    .nullable()
    .optional(),
});
export type LiveJoin = z.infer<typeof liveJoin>;

export type ViewerSignal =
  | { type: "ready" | "leave" }
  | { type: "answer"; sdp: string }
  | { type: "candidate"; candidate: { candidate: string; sdpMid: string | null; sdpMLineIndex: number | null } };

export const joinLiveStream = (market: string, slug: string, resume?: { peer_id: string; peer_token: string }) =>
  request(market, `live-streams/${encodeURIComponent(slug)}/join`, liveJoin, { method: "POST", body: resume ?? {} });
export const sendLiveSignal = (market: string, slug: string, peerToken: string, message: ViewerSignal) =>
  request(market, `live-streams/${encodeURIComponent(slug)}/signal`, z.object({ ok: z.boolean() }), { method: "POST", body: { peer_token: peerToken, ...message } });
export const sendPeerPresence = (market: string, slug: string, peerToken: string, viewerId?: string) =>
  request(market, `live-streams/${encodeURIComponent(slug)}/presence`, z.object({ viewers: z.number(), peer_token: z.string().optional(), expires_at: z.string().optional() }), {
    method: "POST",
    body: { viewer_id: viewerId, peer_token: peerToken },
  });
