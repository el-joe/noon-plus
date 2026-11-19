import { z } from "zod";
import { get, request } from "./client";
import { money, productCard, productImage } from "./schemas";

/*
 * Discovery endpoints (wave 5a, QC-043 / QC-057 / QC-064): search suggest,
 * popular / trending searches, recently viewed sync, frequently bought
 * together, compare, coupon chips and "Offers for you".
 */

const nullableMoney = money.nullable();

export const badge = z.object({ key: z.string(), label: z.string() });
export type ProductBadge = z.infer<typeof badge>;

// DiscoveryController@suggest
export const suggestions = z.object({
  queries: z.array(z.string()).default([]),
  products: z.array(z.object({ slug: z.string(), title: z.string(), image: productImage.nullable().optional(), price: nullableMoney.optional() })).default([]),
  brands: z.array(z.object({ slug: z.string(), name: z.string(), logo: z.string().nullable().optional() })).default([]),
  categories: z.array(z.object({ slug: z.string(), name: z.string() })).default([]),
});
export type Suggestions = z.infer<typeof suggestions>;

export const popularSearch = z.object({ query: z.string() });
export const trendingSearch = z.object({
  type: z.string(),
  label: z.string(),
  query: z.string().nullable().optional(),
  category: z.string().nullable().optional(),
});
export type TrendingSearch = z.infer<typeof trendingSearch>;

export const getSuggestions = (market: string, q: string, signal?: AbortSignal) => get(market, "search/suggest", suggestions, { q, limit: 6 }, { signal });
export const getPopularSearches = (market: string, limit = 8) => get(market, "search/popular", z.array(popularSearch), { limit });
export const getTrendingSearches = (market: string, limit = 8) => get(market, "search/trending", z.array(trendingSearch), { limit });

// RecentlyViewedController (auth)
export const recentCard = productCard.extend({ in_stock: z.boolean().optional(), viewed_at: z.string().nullable().optional(), badges: z.array(badge).optional() });
export type RecentCard = z.infer<typeof recentCard>;
export const getRecentlyViewed = (market: string) => get(market, "me/recently-viewed", z.array(recentCard));
export const syncRecentlyViewed = (market: string, items: { slug: string; viewed_at?: string | null }[]) =>
  request(market, "me/recently-viewed", z.array(recentCard), { method: "POST", body: { items } });
export const clearRecentlyViewed = (market: string, slug?: string) =>
  request(market, slug ? `me/recently-viewed/${encodeURIComponent(slug)}` : "me/recently-viewed", z.array(recentCard), { method: "DELETE" });

// DiscoveryController@frequentlyBoughtTogether
export const fbtItem = productCard.extend({ listing_id: z.number(), bought_together_orders: z.number().optional(), badges: z.array(badge).optional() });
export type FbtItem = z.infer<typeof fbtItem>;
export const frequentlyBoughtTogether = z.object({
  product: z.object({ slug: z.string(), listing_id: z.number().nullable() }),
  items: z.array(fbtItem),
  bundle_total: nullableMoney,
});
export type FrequentlyBoughtTogether = z.infer<typeof frequentlyBoughtTogether>;
export const getFrequentlyBoughtTogether = (market: string, slug: string) =>
  get(market, `products/${encodeURIComponent(slug)}/frequently-bought-together`, frequentlyBoughtTogether);

// OfferPresenter (coupons) + OfferController@index
export const publicCoupon = z.object({
  code: z.string(),
  label: z.string().nullable(),
  description: z.string().nullable().optional(),
  type: z.string(),
  percent: z.string().nullable().optional(),
  amount: nullableMoney.optional(),
  min_order: nullableMoney.optional(),
  max_discount: nullableMoney.optional(),
  scope: z.string().optional(),
  seller: z.object({ slug: z.string().nullable(), name: z.string().nullable() }).nullable().optional(),
  first_order_only: z.boolean().optional(),
  requires_sign_in: z.boolean().optional(),
  ends_at: z.string().nullable().optional(),
});
export type PublicCoupon = z.infer<typeof publicCoupon>;
export const offers = z.object({
  coupons: z.array(publicCoupon).default([]),
  flash_sales: z
    .array(z.object({ id: z.number(), slug: z.string().nullable().optional(), name: z.string(), state: z.string(), starts_at: z.string(), ends_at: z.string(), deals_count: z.number() }))
    .default([]),
});
export type Offers = z.infer<typeof offers>;
export const getOffers = (market: string) => get(market, "offers", offers);
export const getProductCoupons = (market: string, slug: string) => get(market, `products/${encodeURIComponent(slug)}/coupons`, z.array(publicCoupon));

// DiscoveryController@compare
export const compareProduct = productCard.extend({
  badges: z.array(badge).optional(),
  model_number: z.string().nullable().optional(),
  in_stock: z.boolean().optional(),
  seller: z.string().nullable().optional(),
  options: z.union([z.record(z.string(), z.array(z.union([z.string(), z.number()]))), z.array(z.unknown())]).optional(),
});
export type CompareProduct = z.infer<typeof compareProduct>;
export const comparison = z.object({
  products: z.array(compareProduct),
  specifications: z.array(z.object({ label: z.string(), values: z.array(z.union([z.string(), z.number(), z.null()])) })).default([]),
});
export type Comparison = z.infer<typeof comparison>;
export const getComparison = (market: string, slugs: string[]) => get(market, "products/compare", comparison, { slugs: slugs.join(",") });
