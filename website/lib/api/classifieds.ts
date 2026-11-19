import { z } from "zod";
import { get, request, type Query } from "./client";
import { money } from "./schemas";

/*
 * Classifieds (Open Souq) API — backend app/Domains/Classifieds
 * (ClassifiedController, BuyerController, MyClassifiedController,
 * ClassifiedPresenter). Shapes are tolerant: optional keys may be omitted.
 */

const ok = z.unknown();

// ClassifiedController@categories (tree). `vertical` / `section` are the inherited values (round 2);
// the category's own values are `own_vertical` / `own_section`; `request_types` are the D8 requests allowed.
// `max_photos` / `photo_max_kb` (inherited; defaults 20 / 8192) bound the ad photos — absent on older APIs.
export type ClassifiedCategory = {
  id: number;
  slug: string;
  name: string;
  section: string | null;
  vertical: string | null;
  own_section: string | null;
  own_vertical: string | null;
  request_types: string[];
  max_photos?: number | null;
  photo_max_kb?: number | null;
  children: ClassifiedCategory[];
};
export const classifiedCategory: z.ZodType<ClassifiedCategory> = z.lazy(() =>
  z.object({
    id: z.number(),
    slug: z.string(),
    name: z.string(),
    section: z.string().nullable().default(null),
    vertical: z.string().nullable().default(null),
    own_section: z.string().nullable().default(null),
    own_vertical: z.string().nullable().default(null),
    request_types: z.array(z.string()).default([]),
    max_photos: z.number().nullable().optional().catch(undefined),
    photo_max_kb: z.number().nullable().optional().catch(undefined),
    children: z.array(classifiedCategory).default([]),
  }),
);

const option = z.union([z.string(), z.number(), z.object({ value: z.union([z.string(), z.number()]), label: z.string().optional(), label_en: z.string().optional(), label_ar: z.string().optional() }).loose()]);

// ClassifiedSpecs::describe
export const attribute = z.object({
  key: z.string(),
  label: z.string().nullable(),
  type: z.enum(["text", "number", "year", "select", "multiselect", "boolean"]).or(z.string()),
  options: z.array(option).default([]),
  unit: z.string().nullable().optional(),
  min: z.union([z.string(), z.number()]).nullable().optional(),
  max: z.union([z.string(), z.number()]).nullable().optional(),
  required: z.boolean().default(false),
  filterable: z.boolean().default(false),
});
export type ClassifiedAttribute = z.infer<typeof attribute>;

export const categoryAttributes = z.object({
  category: z.string(),
  vertical: z.string().nullable().optional(),
  section: z.string().nullable().optional(),
  requires_contract: z.boolean().default(false),
  /** Photos per ad / KB per photo for this category (inherited; absent on older APIs → 20 / 8192). */
  max_photos: z.number().nullable().optional().catch(undefined),
  photo_max_kb: z.number().nullable().optional().catch(undefined),
  /** One short video per ad when the category allows it (optional until the API sends them; see photo-limits.ts). */
  allows_video: z.boolean().nullable().optional().catch(undefined),
  video_max_seconds: z.number().nullable().optional().catch(undefined),
  video_max_kb: z.number().nullable().optional().catch(undefined),
  attributes: z.array(attribute),
});
export type CategoryAttributes = z.infer<typeof categoryAttributes>;

/** Ad prices arrive rounded to the currency (round 2). */
const adMoney = money;

const specs = z.union([z.record(z.string(), z.unknown()), z.array(z.unknown()).transform((): Record<string, unknown> => ({}))]);

const num = z.union([z.number(), z.string()]).nullable().optional();
export const propertyFacts = z.looseObject({
  purpose: z.string().nullable().optional(),
  property_type: z.string().nullable().optional(),
  bedrooms: num,
  bathrooms: num,
  area_sqm: num,
  furnishing: z.string().nullable().optional(),
  rent_period: z.string().nullable().optional(),
});

// ClassifiedPresenter::card
export const adCard = z.object({
  number: z.string(),
  kind: z.string().nullable().optional(),
  title: z.string(),
  price: adMoney.nullable(),
  is_negotiable: z.boolean().nullable().optional(),
  category: z.string().nullable().optional(),
  city: z.string().nullable().optional(),
  area: z.string().nullable().optional(),
  image: z.string().nullable().optional(),
  image_count: z.number().default(0),
  has_video: z.boolean().default(false),
  key_specs: specs.default({}),
  is_featured: z.boolean().default(false),
  verified_business: z.boolean().default(false),
  location: z.object({ lat: z.number(), lng: z.number() }).nullable().optional(),
  posted_at: z.string().nullable().optional(),
  status: z.string(),
  views: z.number().optional(),
  /** Property facts on cards (round 2), null outside the property vertical. */
  property: propertyFacts.nullable().optional(),
});
export type AdCard = z.infer<typeof adCard>;
export type PropertyFacts = z.infer<typeof propertyFacts>;

// ClassifiedPresenter::detail + similar
export const adDetail = adCard.extend({
  description: z.string().nullable().optional(),
  specs: specs.default({}),
  media: z.array(z.object({ id: z.number(), type: z.string(), url: z.string(), duration_seconds: z.number().nullable().optional() })).default([]),
  property: z.record(z.string(), z.unknown()).nullable().optional(),
  vertical: z.string().nullable().optional(),
  /** QC-061: how many customers saved the ad ("Save (n)"). */
  saves: z.number().optional(),
  /** D8 requests the buyer may send (`viewing`, `reserve`); empty when the ad is not live. */
  request_types: z.array(z.string()).default([]),
  seller: z.object({
    type: z.string(),
    member_since: z.string().nullable().optional(),
    shop: z
      .object({
        slug: z.string().nullable(),
        name: z.string().nullable(),
        verified: z.boolean(),
        rating: z.union([z.string(), z.number()]).nullable().optional(),
        rating_count: z.number().nullable().optional(),
        member_since: z.string().nullable().optional(),
      })
      .nullable(),
    broker: z.object({ slug: z.string().nullable(), name: z.string().nullable(), verified: z.boolean(), member_since: z.string().nullable().optional() }).nullable(),
    has_phone: z.boolean().optional(),
  }),
  exclusive_marketer: z.object({ slug: z.string().nullable(), name: z.string().nullable(), scope: z.string().nullable() }).nullable().optional(),
  expires_at: z.string().nullable().optional(),
  rejection_reason: z.string().nullable().optional(),
  sold: z.object({ price: z.union([money, z.string()]).nullable(), at: z.string() }).nullable().optional(),
  similar: z.array(adCard).default([]),
});
export type AdDetail = z.infer<typeof adDetail>;

export const shopPage = z.object({
  shop: z.object({
    slug: z.string(),
    name: z.string().nullable(),
    verified: z.boolean(),
    rating: z.union([z.string(), z.number()]).nullable(),
    member_since: z.string().nullable(),
    live_ads: z.number(),
  }),
  ads: z.array(adCard),
});
export type ShopPage = z.infer<typeof shopPage>;

export const thread = z.object({
  id: z.number(),
  ad: z.object({ number: z.string(), title: z.string() }),
  role: z.enum(["buyer", "seller"]).or(z.string()),
  status: z.string(),
  unread: z.number(),
  last_message_at: z.string().nullable(),
});
export type Thread = z.infer<typeof thread>;

export const threadDetail = z.object({
  id: z.number(),
  status: z.string(),
  ad: z.object({ number: z.string(), title: z.string() }),
  messages: z.array(z.object({ id: z.number(), from: z.string().nullable(), body: z.string(), at: z.string().nullable(), read: z.boolean() })),
});
export type ThreadDetail = z.infer<typeof threadDetail>;

export const savedSearch = z.object({
  id: z.number(),
  name: z.string(),
  filters: z.record(z.string(), z.unknown()).or(z.array(z.unknown()).transform((): Record<string, unknown> => ({}))),
  alert_frequency: z.string(),
  status: z.string(),
});
export type SavedSearch = z.infer<typeof savedSearch>;

export const compareResult = z.object({
  attributes: z.array(z.object({ key: z.string(), label: z.string().nullable(), unit: z.string().nullable().optional() })),
  ads: z.array(adCard.extend({ specs: specs.default({}) })),
});
export type CompareResult = z.infer<typeof compareResult>;

export const submitResult = z.object({
  number: z.string(),
  status: z.string(),
  contract: z.object({ version_id: z.number(), title: z.string(), version: z.union([z.number(), z.string()]) }).nullable().optional(),
});
export type SubmitResult = z.infer<typeof submitResult>;

// ---- Filters ---------------------------------------------------------------

export type AdFilters = {
  q?: string;
  category?: string;
  kind?: "offer" | "wanted" | string;
  city?: string;
  min_price?: string;
  max_price?: string;
  verified?: string;
  with_video?: string;
  /** Inherited vertical (`general`, `property`, …). */
  vertical?: string;
  bathrooms_min?: string;
  /** Area in m². */
  min_area?: string;
  max_area?: string;
  sort?: "recommended" | "newest" | "price_asc" | "price_desc" | string;
  page?: number | string;
  per_page?: number;
  /** Attribute filters keyed by key (`make` → attr[make]) or raw param (`attr[year][min]`). */
  attr?: Record<string, string>;
};

function filterQuery(filters: AdFilters): Query {
  const { attr, ...rest } = filters;
  const query: Query = { ...(rest as Query) };
  for (const [key, value] of Object.entries(attr ?? {})) if (value) query[key.startsWith("attr[") ? key : `attr[${key}]`] = value;
  return query;
}

// ---- Public reads ----------------------------------------------------------

export const getClassifiedCategories = (market: string) => get(market, "classifieds/categories", z.array(classifiedCategory));
export const getCategoryAttributes = (market: string, slug: string) => get(market, `classifieds/categories/${encodeURIComponent(slug)}/attributes`, categoryAttributes);
export const getAds = (market: string, filters: AdFilters) => get(market, "classifieds/ads", z.array(adCard), filterQuery(filters));
export const getAd = (market: string, number: string) => get(market, `classifieds/ads/${encodeURIComponent(number)}`, adDetail);
export const getShop = (market: string, slug: string, page = 1) => get(market, `classifieds/shops/${encodeURIComponent(slug)}`, shopPage, { page });
export const compareAds = (market: string, numbers: string[]) =>
  get(market, "classifieds/compare", compareResult, Object.fromEntries(numbers.map((n, i) => [`numbers[${i}]`, n])));

// ---- Buyer -----------------------------------------------------------------

export type ContactInput = { name: string; email: string; phone: string };
export const sendInquiry = (market: string, number: string, body: ContactInput & { message: string; ref?: string }) =>
  request(market, `classifieds/ads/${encodeURIComponent(number)}/inquiries`, z.object({ thread_id: z.number(), status: z.string() }), { method: "POST", body });
export const reportAd = (market: string, number: string, body: { reason: string; details?: string }) =>
  request(market, `classifieds/ads/${encodeURIComponent(number)}/reports`, ok, { method: "POST", body });
export const revealPhone = (market: string, number: string) =>
  request(market, `classifieds/ads/${encodeURIComponent(number)}/phone`, z.object({ phone: z.string() }), { method: "POST" });

/** D8 viewing / reserve request on any vertical the ad allows (`request_types`); guests too. */
export type VisitRequestInput = ContactInput & { type: "viewing" | "reserve"; preferred_at: string; message?: string };
export const requestAdVisit = (market: string, number: string, body: VisitRequestInput) =>
  request(market, `classifieds/ads/${encodeURIComponent(number)}/viewings`, z.object({ id: z.number(), type: z.string().optional() }), { method: "POST", body });

/**
 * RealEstateController@myViewings / MyClassifiedController@requests. Owner
 * decision D12: a request is a record only — no status, no seller actions.
 * `contact` is present only on requests received on the user's own ads.
 */
export const viewingRequest = z.object({
  id: z.number(),
  type: z.string(),
  preferred_at: z.string().nullable(),
  note: z.string().nullable().optional(),
  created_at: z.string().nullable(),
  ad: z
    .object({
      number: z.string(),
      title: z.string().nullable(),
      price: money.nullable().optional(),
      image: z.string().nullable().optional(),
      city: z.string().nullable().optional(),
      area: z.string().nullable().optional(),
      status: z.string().nullable().optional(),
      category: z.string().nullable().optional(),
    })
    .nullable(),
  contact: z.object({ name: z.string(), email: z.string(), phone: z.string() }).optional(),
});
export type ViewingRequest = z.infer<typeof viewingRequest>;
export const getMyViewingRequests = (market: string, query: { type?: string; page?: number; per_page?: number } = {}) =>
  get(market, "me/viewing-requests", z.array(viewingRequest), query);
/** Requests received on the signed-in user's own ads, with the customer's contact details (D12). */
export const getReceivedViewingRequests = (market: string, query: { type?: string; page?: number; per_page?: number } = {}) =>
  get(market, "classifieds/mine/requests", z.array(viewingRequest), query);

export const getThreads = (market: string, page = 1) => get(market, "classifieds/threads", z.array(thread), { page });
export const getThread = (market: string, id: number) => get(market, `classifieds/threads/${id}`, threadDetail);
export const replyThread = (market: string, id: number, body: string) => request(market, `classifieds/threads/${id}/messages`, ok, { method: "POST", body: { body } });
export const closeThread = (market: string, id: number) => request(market, `classifieds/threads/${id}/close`, ok, { method: "POST" });

export const getFavourites = (market: string) => get(market, "classifieds/favourites", z.array(adCard));
export const addFavourite = (market: string, number: string) => request(market, `classifieds/favourites/${encodeURIComponent(number)}`, ok, { method: "POST" });
export const removeFavourite = (market: string, number: string) => request(market, `classifieds/favourites/${encodeURIComponent(number)}`, ok, { method: "DELETE" });

export const getSavedSearches = (market: string) => get(market, "classifieds/saved-searches", z.array(savedSearch));
export const createSavedSearch = (market: string, body: { name: string; filters: Record<string, unknown>; alert_frequency?: "instant" | "daily" }) =>
  request(market, "classifieds/saved-searches", savedSearch, { method: "POST", body });
export const updateSavedSearch = (market: string, id: number, body: Partial<{ status: "active" | "paused"; alert_frequency: "instant" | "daily"; name: string }>) =>
  request(market, `classifieds/saved-searches/${id}`, savedSearch, { method: "PATCH", body });
export const deleteSavedSearch = (market: string, id: number) => request(market, `classifieds/saved-searches/${id}`, ok, { method: "DELETE" });

const followState = z.object({ following: z.boolean(), followers: z.number() });
export type FollowType = "shop" | "marketer";
export const followShop = (market: string, slug: string, follow: boolean, type: FollowType = "shop") =>
  request(market, "follows", followState, { method: follow ? "POST" : "DELETE", body: { type, slug } });
/** "Am I following?" (auth). */
export const getFollow = (market: string, slug: string, type: FollowType = "shop") => get(market, "follows", followState, { type, slug });

// ---- Owner (post-ad wizard, My ads) ----------------------------------------

export type AdInput = {
  category_id?: number;
  kind?: "offer" | "wanted";
  title?: string;
  description?: string | null;
  price?: string | null;
  is_negotiable?: boolean;
  city?: string | null;
  area?: string | null;
  contact_phone?: string | null;
  specs?: Record<string, unknown>;
};

export const getMyAds = (market: string, page = 1) => get(market, "classifieds/mine", z.array(adCard), { page });
export const createAd = (market: string, body: AdInput) => request(market, "classifieds/ads", adDetail, { method: "POST", body });
export const updateAd = (market: string, number: string, body: AdInput) => request(market, `classifieds/ads/${encodeURIComponent(number)}`, adDetail, { method: "PUT", body });
export const uploadAdPhoto = (market: string, number: string, file: File) => {
  const form = new FormData();
  form.append("photo", file);
  return request(market, `classifieds/ads/${encodeURIComponent(number)}/photos`, ok, { method: "POST", body: form });
};
/** One short video per ad (`POST classifieds/ads/{number}/video`; MP4 / WebM / MOV with its length in seconds). */
export const uploadAdVideo = (market: string, number: string, file: File, durationSeconds: number) => {
  const form = new FormData();
  form.append("video", file);
  form.append("duration_seconds", String(durationSeconds));
  return request(market, `classifieds/ads/${encodeURIComponent(number)}/video`, ok, { method: "POST", body: form });
};
export const removeAdMedia = (market: string, number: string, mediaId: number) =>
  request(market, `classifieds/ads/${encodeURIComponent(number)}/media/${mediaId}`, ok, { method: "DELETE" });
export const submitAd = (market: string, number: string) => request(market, `classifieds/ads/${encodeURIComponent(number)}/submit`, submitResult, { method: "POST" });
export const signAdContract = (market: string, number: string, fullName: string) =>
  request(market, `classifieds/ads/${encodeURIComponent(number)}/contract`, z.object({ number: z.string(), status: z.string() }), { method: "POST", body: { full_name: fullName } });
/** Reopen a pending-contract ad: the seller contract to sign (owner). */
export const adContract = z.object({
  number: z.string(),
  status: z.string(),
  contract: z
    .object({ version_id: z.number(), title: z.string(), version: z.union([z.number(), z.string()]), text: z.string().nullable().optional(), has_file: z.boolean().optional(), signed: z.boolean().optional() })
    .nullable(),
});
export type AdContract = z.infer<typeof adContract>;
export const getAdContract = (market: string, number: string) => get(market, `classifieds/ads/${encodeURIComponent(number)}/contract`, adContract);
/** Copy a rejected / expired / archived / sold ad into a new draft (rejected ads stay final). */
export const duplicateAd = (market: string, number: string) => request(market, `classifieds/ads/${encodeURIComponent(number)}/duplicate`, adDetail, { method: "POST" });
export const DUPLICABLE_STATUSES = ["rejected", "expired", "archived", "sold"] as const;
export type AdAction = "pause" | "resume" | "archive" | "renew" | "unsold";
export const adAction = (market: string, number: string, action: AdAction) =>
  request(market, `classifieds/ads/${encodeURIComponent(number)}/${action}`, z.object({ number: z.string(), status: z.string() }).loose(), { method: "POST" });
export const markAdSold = (market: string, number: string, finalPrice: string) =>
  request(market, `classifieds/ads/${encodeURIComponent(number)}/sold`, ok, { method: "POST", body: { final_price: finalPrice } });

// ---- Helpers ---------------------------------------------------------------

export function flattenCategories(tree: ClassifiedCategory[], depth = 0): (ClassifiedCategory & { depth: number })[] {
  return tree.flatMap((c) => [{ ...c, depth }, ...flattenCategories(c.children ?? [], depth + 1)]);
}

export function optionValue(o: z.infer<typeof option>): string {
  return typeof o === "object" ? String(o.value) : String(o);
}

export function optionLabel(o: z.infer<typeof option>, locale: string): string {
  if (typeof o !== "object") return String(o);
  return (locale === "ar" ? o.label_ar : o.label_en) ?? o.label ?? String(o.value);
}

export function specText(value: unknown): string {
  if (value === null || value === undefined) return "";
  if (Array.isArray(value)) return value.map(specText).join(", ");
  if (typeof value === "boolean") return value ? "✓" : "✗";
  if (typeof value === "object") return JSON.stringify(value);
  return String(value);
}
