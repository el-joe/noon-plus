import { z } from "zod";
import { get, type Query } from "./client";
import { productCard } from "./schemas";

/* Creator directory and boutique (CreatorDirectoryController). */

export const creatorCard = z.object({
  slug: z.string(),
  name: z.string().nullable(),
  job_types: z.array(z.string()).nullable().default([]),
  avatar_url: z.string().nullable(),
  cover_url: z.string().nullable(),
  followers: z.number().nullable().default(0),
  verified: z.boolean().default(false),
});
export type CreatorCard = z.infer<typeof creatorCard>;

export const creatorDetail = creatorCard.extend({
  bio: z.string().nullable(),
  socials: z.union([z.record(z.string(), z.string().nullable()), z.array(z.unknown())]).default([]),
  /** Product cards (round 2: `price` is `{amount, currency}`) plus the product slug and the creator's referral code. */
  picks: z
    .array(productCard.extend({ listing_id: z.number(), product_slug: z.string().nullable().optional(), referral_code: z.string().nullable().optional() }))
    .default([]),
});
export type CreatorDetail = z.infer<typeof creatorDetail>;

export type CreatorFilters = { type?: string; q?: string; page?: number; per_page?: number };
export const getCreators = (market: string, filters: CreatorFilters = {}) => get(market, "creators", z.array(creatorCard), filters as Query);
export const getCreator = (market: string, slug: string) => get(market, `creators/${encodeURIComponent(slug)}`, creatorDetail);
