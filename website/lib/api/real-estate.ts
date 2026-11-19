import { z } from "zod";
import { ApiError, buildUrl, get, request, type Query } from "./client";
import { money } from "./schemas";

/*
 * Real estate (backend RealEstate/Http/Controllers/Api/RealEstateController
 * + Classifieds ClassifiedPresenter): property ads are classified ads in a
 * category whose vertical is `property`. Viewing requests take no payment
 * (MASTER_PLAN D8); guests may send them.
 */

// ClassifiedController@categories (tree; `vertical` is inherited since round 2)
export type PropertyCategory = { id?: number; slug: string; name: string; section: string | null; vertical: string | null; children: PropertyCategory[] };
export const classifiedCategory: z.ZodType<PropertyCategory> = z.lazy(() =>
  z.object({
    id: z.number().optional(),
    slug: z.string(),
    name: z.string(),
    section: z.string().nullable(),
    vertical: z.string().nullable(),
    children: z.array(classifiedCategory).default([]),
  }),
);

// ClassifiedPresenter::card
export const propertyCard = z.object({
  number: z.string(),
  kind: z.string().nullable(),
  title: z.string(),
  price: money.nullable(),
  is_negotiable: z.boolean().nullable().optional(),
  category: z.string().nullable(),
  city: z.string().nullable(),
  area: z.string().nullable(),
  image: z.string().nullable(),
  image_count: z.number(),
  has_video: z.boolean(),
  key_specs: z.record(z.string(), z.unknown()).or(z.array(z.unknown())).nullable().optional(),
  is_featured: z.boolean(),
  verified_business: z.boolean(),
  location: z.object({ lat: z.number(), lng: z.number() }).nullable(),
  posted_at: z.string().nullable(),
  status: z.string(),
  property: z
    .looseObject({
      purpose: z.string().nullable().optional(),
      property_type: z.string().nullable().optional(),
      bedrooms: z.union([z.number(), z.string()]).nullable().optional(),
      bathrooms: z.union([z.number(), z.string()]).nullable().optional(),
      area_sqm: z.union([z.string(), z.number()]).nullable().optional(),
      furnishing: z.string().nullable().optional(),
      rent_period: z.string().nullable().optional(),
    })
    .nullable()
    .optional(),
});
export type PropertyCard = z.infer<typeof propertyCard>;

const property = z.object({
  purpose: z.string().nullable().optional(),
  property_type: z.string().nullable().optional(),
  bedrooms: z.number().nullable().optional(),
  bathrooms: z.number().nullable().optional(),
  area_sqm: z.union([z.string(), z.number()]).nullable().optional(),
  furnishing: z.string().nullable().optional(),
  rent_period: z.string().nullable().optional(),
  permit_number: z.string().nullable().optional(),
  permit_authority: z.string().nullable().optional(),
  completion_on: z.string().nullable().optional(),
  developer: z.string().nullable().optional(),
  payment_plan: z.unknown().optional(),
  features: z.unknown().optional(),
});
export type PropertyFacts = z.infer<typeof property>;

// ClassifiedPresenter::detail + similar
export const propertyDetail = propertyCard.extend({
  description: z.string().nullable(),
  specs: z.record(z.string(), z.unknown()).or(z.array(z.unknown())).nullable().default([]),
  media: z.array(z.object({ id: z.number(), type: z.string(), url: z.string(), duration_seconds: z.number().nullable().optional() })),
  property: property.nullable(),
  request_types: z.array(z.string()).default([]),
  seller: z.object({
    type: z.string(),
    member_since: z.string().nullable().optional(),
    shop: z.object({ slug: z.string().nullable(), name: z.string().nullable(), verified: z.boolean() }).nullable(),
    broker: z.object({ slug: z.string().nullable(), name: z.string().nullable(), verified: z.boolean() }).nullable(),
    has_phone: z.boolean().optional(),
  }),
  exclusive_marketer: z.object({ slug: z.string(), name: z.string().nullable(), scope: z.string().nullable() }).nullable(),
  views: z.number().nullable().optional(),
  expires_at: z.string().nullable().optional(),
  sold: z.object({ price: z.union([money, z.string()]).nullable(), at: z.string() }).nullable().optional(),
  similar: z.array(propertyCard).default([]),
});
export type PropertyDetail = z.infer<typeof propertyDetail>;

// RealEstateController@broker
export const brokerProfile = z.object({
  slug: z.string(),
  name: z.string().nullable(),
  bio: z.string().nullable(),
  verified: z.boolean(),
  licence: z.object({ number: z.string().nullable(), authority: z.string().nullable(), expires_on: z.string().nullable() }).nullable(),
  office: z.string().nullable(),
  areas: z.array(z.string()).nullable().default([]),
  languages: z.array(z.string()).nullable().default([]),
  followers: z.number(),
  properties: z.array(propertyCard),
});
export type BrokerProfile = z.infer<typeof brokerProfile>;

// RealEstateController@specialRequests
export const specialRequest = z.object({
  number: z.string(),
  purpose: z.string().nullable(),
  property_type: z.string().nullable(),
  city: z.string().nullable(),
  bedrooms: z.number().nullable(),
  details: z.string().nullable(),
  status: z.string(),
  claimed: z.boolean(),
});
export type SpecialRequest = z.infer<typeof specialRequest>;

export const PURPOSES = ["rent", "sale", "off_plan"] as const;
export const PROPERTY_TYPES = ["apartment", "villa", "townhouse", "studio", "penthouse", "land", "office", "shop", "warehouse"] as const;
export const PROPERTY_SORTS = ["recommended", "newest", "price_asc", "price_desc"] as const;

export type PropertyFilters = {
  category?: string;
  q?: string;
  city?: string;
  purpose?: string;
  property_type?: string;
  bedrooms_min?: string;
  bathrooms_min?: string;
  min_area?: string;
  max_area?: string;
  min_price?: string;
  max_price?: string;
  sort?: string;
  page?: number;
  per_page?: number;
};

export const getClassifiedCategories = (market: string) => get(market, "classifieds/categories", z.array(classifiedCategory));
export const getProperties = (market: string, filters: PropertyFilters) => get(market, "classifieds/ads", z.array(propertyCard), filters as Query);
/**
 * FR-CLS-5 map view: `GET classifieds/map` (ClassifiedController@map) — every
 * live ad matching the filters that has a location, as a bare GeoJSON
 * FeatureCollection (no `{data}` envelope; the API caps it at 500 points).
 */
const mapFeatureCollection = z.object({
  type: z.literal("FeatureCollection"),
  features: z.array(
    z.object({
      type: z.literal("Feature"),
      geometry: z.object({ type: z.string(), coordinates: z.unknown() }),
      properties: z.object({
        number: z.string(),
        title: z.string(),
        price: z.union([z.string(), z.number()]).nullable().transform((v) => (v === null ? null : String(v))),
        currency: z.string().nullable(),
        featured: z.boolean().optional(),
      }),
    }),
  ),
});
export type PropertyMapCollection = z.infer<typeof mapFeatureCollection>;

export async function getPropertyMap(market: string, filters: PropertyFilters): Promise<PropertyMapCollection> {
  const query: Query = {};
  for (const [k, v] of Object.entries(filters)) if (k !== "page" && k !== "per_page" && k !== "sort") query[k] = v;
  let response: Response;
  try {
    response = await fetch(buildUrl(market, "classifieds/map", query), { headers: { Accept: "application/json" }, cache: "no-store" });
  } catch (cause) {
    throw new ApiError(0, "network_error", cause instanceof Error ? cause.message : "Network error");
  }
  if (!response.ok) throw new ApiError(response.status, "http_error", response.statusText || "http_error");
  const parsed = mapFeatureCollection.safeParse(await response.json().catch(() => null));
  if (!parsed.success) throw new ApiError(response.status, "invalid_response", "The server returned an unexpected response.");
  return parsed.data;
}
export const getProperty = (market: string, number: string) => get(market, `classifieds/ads/${encodeURIComponent(number)}`, propertyDetail);
export const getBroker = (market: string, slug: string) => get(market, `brokers/${encodeURIComponent(slug)}`, brokerProfile);

export const requestViewing = (market: string, number: string, body: { name: string; email: string; phone: string; preferred_at: string; message?: string; type?: "viewing" | "reserve" }) =>
  request(market, `classifieds/ads/${encodeURIComponent(number)}/viewings`, z.object({ id: z.number(), type: z.string().optional() }), { method: "POST", body });

export const getSpecialRequests = (market: string) => get(market, "special-requests", z.array(specialRequest));
export type SpecialRequestInput = {
  purpose?: string;
  property_type?: string;
  city?: string;
  bedrooms?: number;
  budget_min?: number;
  budget_max?: number;
  details: string;
};
export const createSpecialRequest = (market: string, body: SpecialRequestInput) =>
  request(market, "special-requests", z.object({ number: z.string(), status: z.string() }), { method: "POST", body });
export const cancelSpecialRequest = (market: string, number: string) =>
  request(market, `special-requests/${encodeURIComponent(number)}/cancel`, z.object({ status: z.string() }), { method: "POST" });

/** Top-most categories of the property vertical (`vertical` is already inherited by the API). */
export function propertyCategories(tree: PropertyCategory[]): PropertyCategory[] {
  const out: PropertyCategory[] = [];
  const walk = (nodes: PropertyCategory[]) => {
    for (const node of nodes) {
      if (node.vertical === "property") out.push(node);
      else walk(node.children);
    }
  };
  walk(tree);
  return out;
}
