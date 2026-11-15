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
});
export type StreamSummary = z.infer<typeof streamSummary>;

// LiveStreams::playback — recorded file, or a LiveKit room (no client SDK on the web yet).
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
    url: z.string().nullable().optional(),
    room: z.string().nullable().optional(),
    token: z.string().nullable().optional(),
    hls_url: z.string().nullable().optional(),
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
