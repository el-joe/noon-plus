import { z } from "zod";
import { get, request } from "./client";
import { filterQuery, type ProductFilters } from "./endpoints";
import * as s from "./schemas";

/** Seller storefront (QC-008): `sellers/{slug}`, its products, facets, reviews and follow. */

const money = z.object({ amount: z.string(), currency: z.string() });

export const sellerProfile = z.object({
  slug: z.string(),
  name: z.string(),
  logo: z.string().nullable(),
  banner: z.string().nullable(),
  description: z.string().nullable(),
  city: z.string().nullable(),
  joined_at: z.string().nullable(),
  rating: z.object({
    average: z.union([z.string(), z.number()]).nullable(),
    count: z.number(),
    histogram: z.record(z.string(), z.number()).default({}),
    positive_percent: z.number().nullable(),
  }),
  product_count: z.number(),
  followers: z.number(),
  following: z.boolean(),
  badges: z.array(z.object({ key: z.string(), label: z.string() })).default([]),
  policies: z.object({
    returns: z.object({ window_days: z.number().nullable(), note: z.string().nullable() }).nullable(),
    shipping: z.object({ fee: money.nullable(), free_over: money.nullable(), fulfilled_by_nawy: z.boolean() }).nullable(),
    warranty: z.object({ note: z.string().nullable() }).nullable(),
  }),
  reviews_url: z.string().nullable().optional(),
});
export type SellerProfile = z.infer<typeof sellerProfile>;

export const sellerReviewPage = z.object({
  seller: z.object({ slug: z.string(), name: z.string() }),
  summary: z.object({ average: z.union([z.string(), z.number()]).nullable(), count: z.number(), histogram: z.record(z.string(), z.number()).default({}) }),
  reviews: z.array(s.review),
});
export type SellerReviewPage = z.infer<typeof sellerReviewPage>;

export const followState = z.object({ following: z.boolean(), followers: z.number() });

const enc = encodeURIComponent;

export const getSeller = (market: string, slug: string) => get(market, `sellers/${enc(slug)}`, sellerProfile);
export const getSellerProducts = (market: string, slug: string, filters: ProductFilters, headers?: Record<string, string>) =>
  get(market, `sellers/${enc(slug)}/products`, z.array(s.productCard), filterQuery(filters), { headers });
export const getSellerFacets = (market: string, slug: string, filters: ProductFilters) =>
  get(market, `sellers/${enc(slug)}/products/facets`, s.facets, filterQuery({ ...filters, page: undefined, sort: undefined }));
export const getSellerReviews = (market: string, slug: string, query: { page?: number; per_page?: number; rating?: number } = {}) =>
  get(market, `sellers/${enc(slug)}/reviews`, sellerReviewPage, query);
export const followSeller = (market: string, slug: string, follow: boolean) =>
  request(market, `sellers/${enc(slug)}/follow`, followState, { method: follow ? "POST" : "DELETE" });

/** Storefront path inside a market: `/{market}/seller/{slug}`. */
export function sellerHref(market: string, slug: string): string {
  return `/${market}/seller/${enc(slug)}`;
}
