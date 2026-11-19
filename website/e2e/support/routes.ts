import { readdirSync, statSync } from "node:fs";
import path from "node:path";

export type RouteEntry = {
  /** Path after the market segment, "" for the home page. */
  path: string;
  /** Route file pattern it came from, e.g. "products/[slug]". */
  pattern: string;
  /** Needs the signed-in customer. */
  auth: boolean;
  /** Expected HTTP status. */
  status: 200 | 404;
};

export type Fixtures = {
  store_slug: string;
  pending_listing_id: number;
  placed_sub_order: { id: number; number: string; order: string };
  packed_sub_order: { id: number; number: string; order: string };
  shipment: { id: number; number: string };
  trip_slug: string | null;
  /** D14: the e2e trip, promoted (null when the seeder could not promote it). */
  promoted_trip_slug?: string | null;
  referral_code: string | null;
};

const APP = path.resolve(__dirname, "../../app/[market]");

/** Every page.tsx pattern under app/[market], relative ("" = the market home). */
export function routePatterns(dir = APP, rel = ""): string[] {
  const out: string[] = [];
  for (const name of readdirSync(dir)) {
    const full = path.join(dir, name);
    if (statSync(full).isDirectory()) out.push(...routePatterns(full, rel ? `${rel}/${name}` : name));
    else if (name === "page.tsx") out.push(rel);
  }
  return out.sort();
}

type Json = { data?: unknown };
async function api(apiRoot: string, market: string, p: string, token?: string): Promise<Json | null> {
  try {
    const res = await fetch(`${apiRoot}/${market}/${p}`, { headers: { Accept: "application/json", ...(token ? { Authorization: `Bearer ${token}` } : {}) } });
    return res.ok ? ((await res.json()) as Json) : null;
  } catch {
    return null;
  }
}
const first = (json: Json | null, key: string): string | null => {
  const list = Array.isArray(json?.data) ? (json!.data as Record<string, unknown>[]) : [];
  const value = list[0]?.[key];
  return value === undefined || value === null ? null : String(value);
};

/** Pages that show an error state without their query (still a 200 page). */
const QUERY_PAGES = new Set(["checkout/payment", "checkout/success", "newsletter/confirm", "reset-password", "auth/social/complete"]);

/**
 * Concrete routes of one market: static patterns as they are, dynamic ones
 * with a real slug from the API (skipped, and reported, when the market has none).
 */
export async function resolveRoutes(apiRoot: string, market: string, fixtures: Fixtures | null, customerToken: string | null): Promise<{ routes: RouteEntry[]; unresolved: string[] }> {
  const routes: RouteEntry[] = [];
  const unresolved: string[] = [];
  const helpCollections = (await api(apiRoot, market, "help/help_center/collections")) as { data?: { slug: string; articles?: { slug: string }[] }[] } | null;
  const adCollections = (await api(apiRoot, market, "help/knowledge_hub/collections")) as typeof helpCollections;
  const creators = (await api(apiRoot, market, "creators")) as { data?: { slug: string; job_types: string[] }[] } | null;
  const joinOptions = (await api(apiRoot, market, "join-options")) as { data?: { types: { type: string }[] }[] } | null;
  const travel = await api(apiRoot, market, "travel/packages");
  const tripSlug = market.startsWith("uae-") && fixtures?.trip_slug ? fixtures.trip_slug : first(travel, "slug");
  const orders = customerToken && market.startsWith("uae-") ? await api(apiRoot, market, "orders", customerToken) : null;

  const resolvers: Record<string, () => Promise<string | null> | string | null> = {
    "[...slug]": async () => first(await api(apiRoot, market, "categories"), "slug"),
    "products/[slug]": async () => first(await api(apiRoot, market, "products?per_page=1"), "slug"),
    "brands/[slug]": async () => first(await api(apiRoot, market, "brands"), "slug"),
    "blog/[slug]": async () => first(await api(apiRoot, market, "blog"), "slug"),
    "blog/category/[slug]": async () => first(await api(apiRoot, market, "blog/categories"), "slug"),
    "help/collections/[slug]": () => helpCollections?.data?.[0]?.slug ?? null,
    "help/articles/[slug]": () => helpCollections?.data?.find((c) => c.articles?.length)?.articles?.[0]?.slug ?? null,
    "adsupport/collections/[slug]": () => adCollections?.data?.[0]?.slug ?? null,
    "adsupport/articles/[slug]": () => adCollections?.data?.find((c) => c.articles?.length)?.articles?.[0]?.slug ?? null,
    "creators/[slug]": () => creators?.data?.[0]?.slug ?? null,
    "brokers/[slug]": () => creators?.data?.find((c) => c.job_types.includes("broker"))?.slug ?? null,
    "marketers/[role]": () => "affiliate",
    "live/[slug]": async () => first(await api(apiRoot, market, "live-streams"), "slug"),
    "flash-sale/[id]": async () => first(await api(apiRoot, market, "flash-sales"), "slug"),
    "travel/[slug]": () => tripSlug,
    "travel/[slug]/book": () => tripSlug,
    "classified/find/[number]": async () => first(await api(apiRoot, market, "classifieds/ads?per_page=1"), "number"),
    "property/[number]": async () => first(await api(apiRoot, market, "classifieds/ads?vertical=property&per_page=1"), "number"),
    "classified/shop/[slug]": async () => ((await api(apiRoot, market, "classifieds/shops/demo-souq-shop")) ? "demo-souq-shop" : null),
    "seller/[slug]": async () => {
      const slug = market.startsWith("uae-") && fixtures ? fixtures.store_slug : null;
      return slug && (await api(apiRoot, market, `sellers/${slug}`)) ? slug : null;
    },
    "join/[type]": () => joinOptions?.data?.flatMap((o) => o.types)?.[0]?.type ?? null,
    "pages/[slug]": async () => ((await api(apiRoot, market, "static-pages/privacy-policy?audience=storefront")) ? "privacy-policy" : null),
    "r/[code]": () => (market.startsWith("uae-") ? fixtures?.referral_code ?? null : null),
    "account/orders/[number]": () => first(orders, "number"),
    // Records the flows create for the e2e customer (absent on a fresh database: reported as skipped).
    "account/returns/[number]": async () => customerToken && first(await api(apiRoot, market, "returns", customerToken), "number"),
    "account/support/[number]": async () => customerToken && first(await api(apiRoot, market, "support/tickets", customerToken), "number"),
    "account/disputes/[number]": async () => customerToken && first(await api(apiRoot, market, "disputes", customerToken), "number"),
    "account/warranty/[number]": async () => customerToken && first(await api(apiRoot, market, "warranty-claims", customerToken), "number"),
    "travel/bookings/[number]": async () => customerToken && first(await api(apiRoot, market, "travel/bookings", customerToken), "number"),
    "travel/bookings/[number]/sent": async () => customerToken && first(await api(apiRoot, market, "travel/bookings", customerToken), "number"),
  };

  for (const pattern of routePatterns()) {
    if (pattern === "missing") continue;
    const auth = isAccount(pattern);
    if (!pattern.includes("[")) {
      if (auth && !customerToken) continue;
      routes.push({ path: pattern, pattern, auth, status: 200 });
      continue;
    }
    const resolver = resolvers[pattern];
    const value = resolver ? await resolver() : null;
    if (!value || (auth && !customerToken)) {
      unresolved.push(pattern);
      continue;
    }
    routes.push({ path: pattern.replace(/\[\.{0,3}[a-z]+\]/, value), pattern, auth, status: 200 });
  }
  return { routes, unresolved };
}

/** Pages opened with the signed-in customer session (account area, own travel bookings). */
export const isAccount = (pattern: string) => pattern === "account" || pattern.startsWith("account/") || pattern.startsWith("travel/bookings/");

/** Bogus paths that must answer a real 404 (QA-012, RT-004). */
export const BOGUS: string[] = [
  "products/e2e-no-such-product",
  "e2e-no-such-category/deeper",
  "help/nope",
  "help/articles/e2e-no-such-article",
  "blog/e2e-no-such-post",
  "travel/e2e-no-such-trip",
  "classified/find/CL-AE-9999999",
  "seller/e2e-no-such-store",
  "creators/e2e-no-such-creator",
  "cart/nope",
  "checkout/nope",
  "deals/nope",
];

export { QUERY_PAGES };
